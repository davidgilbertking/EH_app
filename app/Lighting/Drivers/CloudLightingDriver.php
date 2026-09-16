<?php

namespace App\Lighting\Drivers;

use Closure;

/** One cloud command per step; reported state is never physical fade telemetry. */
class CloudLightingDriver implements LightingDriver
{
    private array $settings;

    private ?CloudLightingFiles $files = null;

    private Closure $clock;

    private Closure $sleep;

    public function __construct(private TuyaCloudClient $client, ?array $settings = null, ?Closure $clock = null, ?Closure $sleep = null)
    {
        $this->settings = $settings ?? config('lighting.cloud', []);
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
        $this->sleep = $sleep ?? static fn (int $milliseconds) => usleep($milliseconds * 1000);
    }

    public function capabilities(): array
    {
        return ['readState' => true, 'setWhite' => true, 'applyCapturedScene' => true,
            'sceneCapture' => false, 'nativeWhiteTransition' => false, 'nativeTransitionCancellation' => false,
            'safeSceneExit' => false, 'orderedCommands' => false, 'transitionCompletionSignal' => false,
            'reportedStateReadback' => true, 'simulated' => false];
    }

    public function readState(?array $lastObservation, int $now): array
    {
        $this->configuration();
        if (! $this->client->isOnline()) {
            throw new TuyaCloudException('offline');
        }
        $properties = $this->properties();
        $observation = $this->observation($properties, $now);
        if (($observation['mode'] ?? null) === 'white' && ($lastObservation['mode'] ?? null) === 'white'
            && ($lastObservation['outputSettled'] ?? null) === false
            && in_array($lastObservation['completion'] ?? null, ['cloud_accepted', 'late_acknowledgement', 'transport_uncertain'], true)) {
            // DP28 changes live output without changing stored DP22/23. This
            // includes a late acknowledgement persisted across worker restart.
            return array_merge($lastObservation, ['quality' => 'commanded', 'outputSettled' => false,
                'completion' => 'cloud_accepted', 'driver' => 'cloud', 'observedAt' => $now,
                'reportedAt' => $observation['reportedAt'], 'physicalConfirmed' => false]);
        }
        if (($lastObservation['quality'] ?? null) === 'confirmed'
            && ($lastObservation['outputSettled'] ?? null) === true
            && ($lastObservation['completion'] ?? null) === 'cloud_readback'
            && ($lastObservation['reportedAt'] ?? PHP_INT_MAX) <= $observation['reportedAt']
            && ($lastObservation['reportedStateHash'] ?? null) === $observation['reportedStateHash']) {
            $observation = $this->confirmed($observation);
            if ($observation['mode'] === 'scene' && ($lastObservation['color'] ?? null) === ($observation['color'] ?? null)) {
                $observation['mythosSessionId'] = $lastObservation['mythosSessionId'] ?? null;
            }
        }

        return $observation;
    }

    public function execute(array $command, ?array $lastObservation, int $now): array
    {
        $files = $this->configuration();
        $operation = $command['operation'] ?? null;
        if ($operation === 'scene_dim' || ! in_array($operation, ['white', 'dark_anchor', 'scene'], true)) {
            throw new TuyaCloudException('unsupported_transition');
        }
        if ($operation === 'scene') {
            $alias = $command['color'] ?? '';
            $scene = $files->scenes[$alias] ?? null;
            if ($scene === null) {
                throw new TuyaCloudException('preset_not_configured');
            }
            if (! $this->settledWhite($lastObservation) || abs(($lastObservation['brightnessPct'] ?? -1) - 1) > 0.00001
                || abs($lastObservation['temperaturePct'] ?? -1) > 0.00001
                || ! is_string($command['mythosSessionId'] ?? null) || $command['mythosSessionId'] === '') {
                throw new TuyaCloudException('unsupported_transition');
            }
            $receipt = $this->client->sendCommands([
                ['code' => 'scene_data_v2', 'value' => $scene['value']], ['code' => 'work_mode', 'value' => 'scene'],
            ]);
            $observation = $this->confirm(['20' => true, '21' => 'scene', '25' => $scene['raw']], [21, 25], $receipt, $now);
            $observation['mythosSessionId'] = $command['mythosSessionId'];

            return $observation;
        }
        // H6 has not established a safe colour/scene exit. In particular, never
        // substitute old stored white values for the current RGB output.
        if (($lastObservation['mode'] ?? null) !== 'white' || ($lastObservation['rgbSuppressed'] ?? null) !== true) {
            throw new TuyaCloudException('unsupported_transition');
        }
        $brightness = $operation === 'dark_anchor' ? 1.0 : ($command['brightnessPct'] ?? null);
        $temperature = $operation === 'dark_anchor' ? 0.0 : ($command['temperaturePct'] ?? null);
        if ((! is_int($brightness) && ! is_float($brightness)) || (! is_int($temperature) && ! is_float($temperature))) {
            throw new TuyaCloudException('configuration_error');
        }
        $white = $files->white((float) $brightness, (float) $temperature);
        if ($operation === 'white' && ($command['commit'] ?? null) === false) {
            $value = ['change_mode' => 'gradient', 'h' => 0, 's' => 0, 'v' => 0,
                'bright' => $white['brightness'], 'temperature' => $white['temperature']];
            foreach (['h', 's', 'v', 'bright', 'temperature'] as $field) {
                CloudLightingFiles::range($value[$field], $files->schema['control_data'][$field]);
            }
            $this->client->sendCommands([['code' => 'control_data', 'value' => $value]]);

            return ['mode' => 'white', 'brightnessPct' => (float) $brightness, 'temperaturePct' => (float) $temperature,
                'rgbSuppressed' => true, 'quality' => 'commanded', 'outputSettled' => false,
                'completion' => 'cloud_accepted', 'driver' => 'cloud', 'observedAt' => $now, 'physicalConfirmed' => false];
        }
        if ($operation === 'white' && ($command['commit'] ?? null) !== true) {
            throw new TuyaCloudException('configuration_error');
        }
        $receipt = $this->client->sendCommands([
            ['code' => 'switch_led', 'value' => true],
            ['code' => 'bright_value_v2', 'value' => $white['brightness']],
            ['code' => 'temp_value_v2', 'value' => $white['temperature']],
            ['code' => 'work_mode', 'value' => 'white'],
        ]);

        return $this->confirm(['20' => true, '21' => 'white', '22' => $white['brightness'], '23' => $white['temperature']], [21, 22, 23], $receipt, $now);
    }

    private function confirm(array $target, array $freshFields, array $receipt, int $now): array
    {
        $sentAt = $receipt['sentAt'] ?? null;
        if (! is_int($sentAt)) {
            throw new TuyaCloudException('transport_timeout', writeOutcomeUnknown: true);
        }
        $until = ($this->clock)() + max(0.5, min(5, ($this->settings['confirmation_timeout_ms'] ?? 4000) / 1000));
        do {
            $properties = $this->properties();
            $matches = true;
            foreach ($target as $dp => $value) {
                $matches = $matches && ($properties['values'][$dp] ?? null) === $value;
            }
            $fresh = false;
            foreach ($freshFields as $dp) {
                $fresh = $fresh || ($properties['times'][$dp] ?? 0) >= $sentAt;
            }
            // API success and the query timestamp are insufficient. At least a
            // relevant DEVICE property must have been reported after this write,
            // and the complete stored target must match. This is not photometry.
            if ($matches && $fresh) {
                return $this->confirmed($this->observation($properties, $now));
            }
            $remaining = $until - ($this->clock)();
            if ($remaining <= 0) {
                break;
            }
            ($this->sleep)((int) ceil(min($remaining * 1000, max(500, min(1500, $this->settings['poll_interval_ms'] ?? 500)))));
        } while (($this->clock)() < $until);
        throw new TuyaCloudException('transport_timeout', writeOutcomeUnknown: true);
    }

    private function properties(): array
    {
        $report = $this->client->readProperties();
        $values = $times = [];
        foreach ($report['properties'] as $property) {
            $id = $property['dp_id'] ?? $property['dpId'] ?? null;
            if (! is_int($id) || ! in_array($id, [20, 21, 22, 23, 25], true)) {
                continue;
            }
            if (array_key_exists($id, $values) || ! array_key_exists('value', $property)
                || ! is_int($property['time'] ?? null) || $property['time'] < 0 || $property['time'] > $report['serverTime'] + 1000) {
                throw new TuyaCloudException('configuration_error');
            }
            $values[$id] = $property['value'];
            $times[$id] = $property['time'];
        }
        if (! is_bool($values[20] ?? null) || ! in_array($values[21] ?? null, ['white', 'scene', 'colour', 'music'], true)) {
            throw new TuyaCloudException('configuration_error');
        }

        return ['values' => $values, 'times' => $times];
    }

    private function observation(array $properties, int $now): array
    {
        $files = $this->configuration();
        $dps = $properties['values'];
        $mode = $dps[20] ? $dps[21] : 'off';
        $observation = ['mode' => $mode, 'quality' => 'reported', 'outputSettled' => false,
            'completion' => 'cloud_reported', 'driver' => 'cloud', 'observedAt' => $now,
            'reportedAt' => max($properties['times']), 'physicalConfirmed' => false, 'rgbSuppressed' => $mode === 'white'];
        $identity = [20 => $dps[20], 21 => $dps[21]];
        if ($mode === 'white') {
            if (! is_int($dps[22] ?? null) || ! is_int($dps[23] ?? null)) {
                throw new TuyaCloudException('configuration_error');
            }
            $observation = array_merge($observation, $files->whitePercentages($dps[22], $dps[23]));
            $identity += [22 => $dps[22], 23 => $dps[23]];
        } elseif ($mode === 'scene') {
            $observation += ['color' => null, 'sceneLevel' => 1.0, 'mythosSessionId' => null];
            foreach ($files->scenes as $alias => $scene) {
                if (($dps[25] ?? null) === $scene['raw']) {
                    $observation['color'] = $alias;
                    $observation['sceneSha256'] = $scene['source_sha256'];
                }
            }
            $identity[25] = $dps[25] ?? null;
        }
        $observation['reportedStateHash'] = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));

        return $observation;
    }

    private function configuration(): CloudLightingFiles
    {
        return $this->files ??= new CloudLightingFiles($this->settings['private_directory'] ?? storage_path('app/private/lighting'), $this->client->deviceId());
    }

    private function settledWhite(?array $observation): bool
    {
        return ($observation['mode'] ?? null) === 'white' && ($observation['rgbSuppressed'] ?? null) === true
            && ($observation['quality'] ?? null) === 'confirmed' && ($observation['outputSettled'] ?? null) === true
            && ($observation['completion'] ?? null) === 'cloud_readback';
    }

    private function confirmed(array $observation): array
    {
        return array_merge($observation, ['quality' => 'confirmed', 'outputSettled' => true, 'completion' => 'cloud_readback']);
    }
}
