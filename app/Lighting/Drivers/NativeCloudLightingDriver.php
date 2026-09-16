<?php

namespace App\Lighting\Drivers;

use Closure;

/** Atomic cloud I/O only. The coordinator owns waits, freshness proof and leases. */
class NativeCloudLightingDriver implements LightingDriver
{
    private array $settings;

    private ?CloudLightingFiles $files = null;

    private bool $modelValidated = false;

    private Closure $clock;

    public function __construct(private TuyaCloudClient $client, ?array $settings = null, ?Closure $clock = null)
    {
        $this->settings = $settings ?? config('lighting.cloud', []);
        $this->clock = $clock ?? static fn (): int => (int) floor(microtime(true) * 1000);
    }

    public function capabilities(): array
    {
        return ['readState' => true, 'setWhite' => true, 'applyCapturedScene' => true,
            'sceneCapture' => false, 'nativeWhiteTransition' => true,
            'nativeTransitionCancellation' => (bool) ($this->settings['native_interruptions'] ?? false),
            'safeSceneExit' => true, 'orderedCommands' => false, 'transitionCompletionSignal' => false,
            'reportedStateReadback' => true, 'nativeSwitchGradient' => true, 'simulated' => false];
    }

    public function profiles(): array
    {
        return $this->configuration()->profiles;
    }

    public function scenes(): array
    {
        return $this->configuration()->scenes;
    }

    /** One properties read after a once-per-instance read-only model preflight. */
    public function readSnapshot(): array
    {
        $this->configuration();
        $this->validateModel();
        $report = $this->client->readProperties();
        if (! is_int($report['serverTime'] ?? null) || ! is_array($report['properties'] ?? null)) {
            throw new TuyaCloudException('configuration_error');
        }
        $values = $times = [];
        foreach ($report['properties'] as $property) {
            if (! is_array($property)) {
                throw new TuyaCloudException('configuration_error');
            }
            $id = $property['dp_id'] ?? $property['dpId'] ?? null;
            if (! is_int($id) || ! in_array($id, [20, 21, 22, 23, 25, 35], true)) {
                continue;
            }
            if (array_key_exists($id, $values) || ! array_key_exists('value', $property)) {
                throw new TuyaCloudException('configuration_error');
            }
            $values[$id] = $id === 35 ? $this->decodeGradient($property['value']) : $property['value'];
            $times[$id] = $property['time'] ?? null;
        }
        $snapshot = ['values' => $values, 'times' => $times, 'serverTime' => $report['serverTime'], 'receivedAt' => ($this->clock)()];
        $this->validateSnapshot($snapshot);

        return $snapshot;
    }

    /**
     * Exactly one POST, no status reads, polling, fallback, or sleep. The caller
     * must persist an attempt marker and qualify sourceSnapshot before calling.
     */
    public function issue(array $descriptor): array
    {
        $files = $this->configuration();
        $operation = $descriptor['operation'] ?? null;
        if (! in_array($operation, ['gradient', 'power', 'prepare_white', 'endpoint_white', 'scene', 'realtime_white'], true)) {
            throw new TuyaCloudException('unsupported_transition');
        }
        // Require the read-only preflight before side effects. In particular,
        // sendSwitchGradient must not lazily fetch its model inside this step.
        if (! $this->modelValidated) {
            throw new TuyaCloudException('configuration_error');
        }
        if ($operation === 'gradient') {
            $on = $descriptor['onMs'] ?? null;
            $off = $descriptor['offMs'] ?? null;
            $this->validateGradient(['onMs' => $on, 'offMs' => $off]);

            return $this->receipt($this->client->sendSwitchGradient($on, $off));
        }
        $snapshot = $descriptor['sourceSnapshot'] ?? null;
        if (! is_array($snapshot)) {
            throw new TuyaCloudException('unsupported_transition');
        }
        $this->validateSnapshot($snapshot);
        $age = ($this->clock)() - $snapshot['receivedAt'];
        if ($age < 0 || $age > max(1000, min(10000, $this->settings['snapshot_max_age_ms'] ?? 5000))) {
            throw new TuyaCloudException('unsupported_transition');
        }
        $values = $snapshot['values'];
        $commands = [];
        if ($operation === 'power') {
            $on = $descriptor['on'] ?? null;
            if (! is_bool($on) || $on === $values[20] || ($on && ! $this->isPreparedWhite($values))) {
                throw new TuyaCloudException('unsupported_transition');
            }
            $commands = [['code' => 'switch_led', 'value' => $on]];
        } elseif ($operation === 'prepare_white' || $operation === 'endpoint_white') {
            $profile = $files->profiles[$descriptor['profile'] ?? ''] ?? null;
            if ($profile === null) {
                throw new TuyaCloudException('preset_not_configured');
            }
            if (($operation === 'prepare_white' && $values[20] !== false)
                || ($operation === 'endpoint_white' && ($values[20] !== true || $values[21] !== 'white'))) {
                throw new TuyaCloudException('unsupported_transition');
            }
            $commands = [['code' => 'bright_value_v2', 'value' => $profile['brightness']],
                ['code' => 'temp_value_v2', 'value' => $profile['temperature']], ['code' => 'work_mode', 'value' => 'white']];
        } elseif ($operation === 'scene') {
            $scene = $files->scenes[$descriptor['color'] ?? ''] ?? null;
            if ($scene === null) {
                throw new TuyaCloudException('preset_not_configured');
            }
            $dark = $files->profiles['dark'];
            if ($values[20] !== true || $values[21] !== 'white'
                || $values[22] !== $dark['brightness'] || $values[23] !== $dark['temperature']) {
                throw new TuyaCloudException('unsupported_transition');
            }
            $commands = [['code' => 'scene_data_v2', 'value' => $scene['value']], ['code' => 'work_mode', 'value' => 'scene']];
        } else {
            if ($values[20] !== true || $values[21] !== 'white') {
                throw new TuyaCloudException('unsupported_transition');
            }
            $brightness = $descriptor['brightnessPct'] ?? null;
            $temperature = $descriptor['temperaturePct'] ?? null;
            if ((! is_int($brightness) && ! is_float($brightness)) || (! is_int($temperature) && ! is_float($temperature))) {
                throw new TuyaCloudException('configuration_error');
            }
            $white = $files->white($brightness, $temperature);
            $value = ['change_mode' => 'gradient', 'h' => 0, 's' => 0, 'v' => 0,
                'bright' => $white['brightness'], 'temperature' => $white['temperature']];
            foreach (['h', 's', 'v', 'bright', 'temperature'] as $field) {
                CloudLightingFiles::range($value[$field], $files->schema['control_data'][$field]);
            }
            $commands = [['code' => 'control_data', 'value' => $value]];
        }

        return $this->receipt($this->client->sendCommands($commands));
    }

    public function readState(?array $lastObservation, int $now): array
    {
        return $this->observationFromSnapshot($this->readSnapshot(), $now);
    }

    /** Pure public summary: raw scene payloads and timing snapshots stay internal. */
    public function observationFromSnapshot(array $snapshot, int $now): array
    {
        $this->validateSnapshot($snapshot);
        $values = $snapshot['values'];
        $mode = $values[20] ? $values[21] : 'off';
        $result = ['driver' => 'cloud', 'mode' => $mode, 'quality' => 'reported', 'outputSettled' => false,
            'completion' => 'cloud_reported', 'physicalConfirmed' => false, 'observedAt' => $now,
            'reportedAt' => max($snapshot['times']), 'rgbSuppressed' => $mode === 'white'];
        if ($mode === 'white') {
            $result += $this->configuration()->whitePercentages($values[22], $values[23]);
        } elseif ($mode === 'scene') {
            $result += ['color' => null, 'sceneLevel' => 1.0, 'mythosSessionId' => null];
            foreach ($this->scenes() as $alias => $scene) {
                if (($values[25] ?? null) === $scene['raw']) {
                    $result['color'] = $alias;
                    $result['sceneSha256'] = $scene['source_sha256'];
                }
            }
        }

        return $result;
    }

    /** Compatibility only; the native coordinator normally calls issue directly. */
    public function execute(array $command, ?array $lastObservation, int $now): array
    {
        $command['sourceSnapshot'] ??= $lastObservation;
        $receipt = $this->issue($command);

        return ['driver' => 'cloud', 'quality' => 'commanded', 'outputSettled' => false,
            'completion' => 'cloud_accepted', 'physicalConfirmed' => false, 'observedAt' => $now,
            'receipt' => $receipt, 'sentAt' => $receipt['sentAt'], 'acceptedAt' => $receipt['acceptedAt']];
    }

    private function validateModel(): void
    {
        if ($this->modelValidated) {
            return;
        }
        $model = $this->client->readModel();
        $matches = 0;
        foreach ($model['services'] ?? [] as $service) {
            foreach ($service['properties'] ?? [] as $property) {
                if (($property['abilityId'] ?? null) !== 35 && ($property['code'] ?? null) !== 'switch_gradient') {
                    continue;
                }
                if (($service['code'] ?? null) !== '' || ($property['abilityId'] ?? null) !== 35
                    || ($property['code'] ?? null) !== 'switch_gradient' || ($property['accessMode'] ?? null) !== 'rw'
                    || ($property['typeSpec']['type'] ?? null) !== 'raw'
                    || ! is_int($property['typeSpec']['maxlen'] ?? null) || $property['typeSpec']['maxlen'] < 7) {
                    throw new TuyaCloudException('configuration_error');
                }
                $matches++;
            }
        }
        if ($matches !== 1) {
            throw new TuyaCloudException('configuration_error');
        }
        $this->modelValidated = true;
    }

    private function validateSnapshot(array $snapshot): void
    {
        $files = $this->configuration();
        $values = $snapshot['values'] ?? null;
        $times = $snapshot['times'] ?? null;
        if (! is_array($values) || ! is_array($times) || ! is_int($snapshot['serverTime'] ?? null)
            || ! is_int($snapshot['receivedAt'] ?? null) || ! is_bool($values[20] ?? null)
            || ! in_array($values[21] ?? null, ['white', 'scene', 'colour', 'music'], true)) {
            throw new TuyaCloudException('configuration_error');
        }
        CloudLightingFiles::range($values[22] ?? null, $files->schema['bright_value_v2']);
        CloudLightingFiles::range($values[23] ?? null, $files->schema['temp_value_v2']);
        $this->validateGradient($values[35] ?? null);
        if (($values[21] === 'scene' && ! is_string($values[25] ?? null))
            || (isset($values[25]) && (! is_string($values[25]) || strlen($values[25]) > 4096))) {
            throw new TuyaCloudException('configuration_error');
        }
        foreach ($values as $id => $value) {
            if (! in_array($id, [20, 21, 22, 23, 25, 35], true) || ! is_int($times[$id] ?? null)
                || $times[$id] < 0 || $times[$id] > $snapshot['serverTime'] + 1000) {
                throw new TuyaCloudException('configuration_error');
            }
        }
    }

    private function decodeGradient(mixed $value): array
    {
        $raw = is_string($value) ? base64_decode($value, true) : false;
        if (! is_string($raw) || strlen($raw) !== 7 || $raw[0] !== "\0" || base64_encode($raw) !== $value) {
            throw new TuyaCloudException('configuration_error');
        }
        $gradient = ['onMs' => unpack('N', "\0".substr($raw, 1, 3))[1], 'offMs' => unpack('N', "\0".substr($raw, 4, 3))[1]];
        $this->validateGradient($gradient);

        return $gradient;
    }

    private function validateGradient(mixed $gradient): void
    {
        if (! is_array($gradient) || count($gradient) !== 2) {
            throw new TuyaCloudException('configuration_error');
        }
        foreach (['onMs', 'offMs'] as $field) {
            if (! is_int($gradient[$field] ?? null) || $gradient[$field] < 0 || $gradient[$field] > 60000) {
                throw new TuyaCloudException('configuration_error');
            }
        }
    }

    private function isPreparedWhite(array $values): bool
    {
        if ($values[21] === 'white') {
            foreach ($this->profiles() as $profile) {
                if ($values[22] === $profile['brightness'] && $values[23] === $profile['temperature']) {
                    return true;
                }
            }
        }

        return false;
    }

    private function receipt(array $receipt): array
    {
        if (! is_int($receipt['sentAt'] ?? null) || (isset($receipt['acceptedAt']) && ! is_int($receipt['acceptedAt']))) {
            throw new TuyaCloudException('transport_timeout', writeOutcomeUnknown: true);
        }

        return ['sentAt' => $receipt['sentAt'], 'acceptedAt' => $receipt['acceptedAt'] ?? null];
    }

    private function configuration(): CloudLightingFiles
    {
        return $this->files ??= new CloudLightingFiles($this->settings['private_directory'] ?? storage_path('app/private/lighting'), $this->client->deviceId());
    }
}
