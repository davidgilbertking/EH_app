<?php

namespace App\Console\Commands;

use App\Lighting\Drivers\CloudLightingFiles;
use App\Lighting\Drivers\TuyaCloudClient;
use App\Lighting\Drivers\TuyaCloudException;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Bounded experiment, never a worker: no power writes, retry, or failure undo. */
class LightingProbeContinuousCommand extends Command
{
    protected $signature = 'lighting:probe-continuous
        {operation : dark, scene, or white}
        {--expect-device-id= : Exact selected device ID}
        {--alias= : Captured scene alias for scene operation}
        {--profile=action : Calibrated action or encounters target for white}
        {--duration-ms= : Ramp duration; defaults dark/scene 4000 and white 12000}
        {--tick-ms=500 : Minimum scheduled interval, 300..1000 ms}';

    protected $description = 'Probe paced cloud gradients while preserving power ON; visual acceptance required';

    private Closure $pause;

    private Closure $clock;

    private Closure $externalCancellation;

    private bool $cancelled = false;

    private float $started;

    private string $directory;

    private ?string $reportName = null;

    private array $result = [];

    public function __construct(private ?TuyaCloudClient $client = null, ?Closure $pause = null,
        ?Closure $clock = null, ?Closure $cancelled = null)
    {
        parent::__construct();
        $this->pause = $pause ?? static fn (int $ms) => usleep($ms * 1000);
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
        $this->externalCancellation = $cancelled ?? static fn (): bool => false;
    }

    public function handle(): int
    {
        $operation = $this->argument('operation');
        $expected = $this->option('expect-device-id');
        $duration = $this->option('duration-ms') ?? ($operation === 'white' ? '12000' : '4000');
        $tick = $this->option('tick-ms');
        $alias = $this->option('alias');
        $profile = $this->option('profile');
        if (! in_array($operation, ['dark', 'scene', 'white'], true) || ! is_string($expected)
            || ! preg_match('/\A[A-Za-z0-9_-]{6,128}\z/D', $expected)
            || ! $this->integerOption($duration, 500, 20000) || ! $this->integerOption($tick, 300, 1000)
            || intdiv((int) $duration, (int) $tick) < 5
            || ($operation === 'scene' && ! in_array($alias, ['green', 'yellow', 'blue'], true))
            || ! in_array($profile, ['action', 'encounters'], true)) {
            $this->line(json_encode(['ok' => false, 'error' => 'invalid_probe_arguments', 'commandsAttempted' => 0]));

            return self::INVALID;
        }
        $this->started = ($this->clock)();
        $this->cancelled = false;
        $this->reportName = null;
        $this->result = ['ok' => false, 'operation' => $operation, 'commandsAttempted' => 0, 'framesSent' => 0,
            'skippedSlots' => 0, 'endpointAttempted' => false, 'readbackMatched' => false,
            'writeOutcomeUnknown' => false, 'physicalConfirmed' => false, 'visualVerificationRequired' => true,
            'powerCommandsSent' => 0, 'durationMs' => (int) $duration, 'tickMs' => (int) $tick, 'timings' => []];
        $locks = [];
        $trapped = function_exists('pcntl_signal');
        if ($trapped) {
            $this->trap([SIGINT, SIGTERM], function () {
                $this->cancelled = true;
            });
        }
        try {
            $this->client ??= new TuyaCloudClient;
            if ($this->client->deviceId() !== $expected) {
                throw new TuyaCloudException('device_mismatch');
            }
            $this->directory = config('lighting.cloud.private_directory');
            $files = new CloudLightingFiles($this->directory, $expected);
            $locks[] = $this->lock($this->senderPath());
            $locks[] = $this->lock($this->directory.'/probe.lock');
            $this->guard();
            $this->requireRealtimeModel($this->client->readModel());
            $state = $this->read();
            [$frames, $target, $endpoint, $entryAnchor, $exitBridge] = $this->plan($files, $state, $operation, $alias, $profile, (int) $duration, (int) $tick);
            $suffix = gmdate('Ymd\THis\Z').'-'.Str::uuid();
            $this->result['backup'] = 'cloud-continuous-before-'.$suffix.'.json';
            $this->save($this->result['backup'], ['schema_version' => 1, 'device_id' => $expected, 'source' => $state]);
            $this->reportName = 'cloud-continuous-probe-'.$suffix.'.json';
            // A killed process cannot update its counts. The durable preflight
            // report must not be mistaken for proof that no write happened.
            $this->save($this->reportName, array_replace($this->result, ['executionStatus' => 'in_progress',
                'commandsAttempted' => null, 'commandUpperBound' => count($frames) + 1 + (int) ($entryAnchor !== null) + (int) ($exitBridge !== null)]));
            $this->guard();
            if ($entryAnchor !== null) {
                $this->send($entryAnchor, 'minimum_anchor');
                $this->result['framesSent']++;
                // This native gradient changes channels at their minimum. It
                // must settle before the separately timed RGB growth starts.
                $this->waitUntil(($this->clock)() + 0.4);
            }
            $rampStarted = ($this->clock)();
            $previousStarted = null;
            foreach ($frames as $index => $frame) {
                $slot = $rampStarted + $frame['offset'];
                $this->waitUntil($slot);
                $now = ($this->clock)();
                // Drop superseded intermediate targets. Never drain a backlog
                // after a slow RPC, and keep even the final target paced.
                if ($index < count($frames) - 1 && $now >= $rampStarted + $frames[$index + 1]['offset']) {
                    $this->result['skippedSlots']++;

                    continue;
                }
                // Small scheduler jitter must not discard every other frame.
                // A 90% minimum interval still forbids catch-up bursts.
                $minimumInterval = (int) $tick / 1000 * 0.9;
                if ($previousStarted !== null && $now - $previousStarted < $minimumInterval) {
                    if ($index < count($frames) - 1) {
                        $this->result['skippedSlots']++;

                        continue;
                    }
                    $this->waitUntil($previousStarted + $minimumInterval);
                }
                $previousStarted = ($this->clock)();
                $this->send($frame['value'], 'gradient', $frame['offset']);
                $this->result['framesSent']++;
            }
            if ($exitBridge !== null) {
                // Never interpolate V10/white0 into V0/white10: intermediate
                // values below 10 can both be clipped to zero by firmware.
                $this->waitUntil(max(($this->clock)() + 0.4, $previousStarted + (int) $tick / 1000 * 0.9));
                $this->send($exitBridge, 'minimum_bridge');
                $this->result['framesSent']++;
            }
            // This is an experimental settling allowance, not output telemetry.
            $this->waitUntil(($this->clock)() + 1);
            $before = $this->read();
            $this->requireState($before, $state['values']);
            $this->guard();
            $this->result['endpointAttempted'] = true;
            $receipt = $this->send($endpoint, 'endpoint');
            $this->confirmStoredState($target, $before, $receipt);
            $this->result['ok'] = true;
        } catch (Throwable $error) {
            $safe = ['device_mismatch', 'source_mismatch', 'unknown_scene', 'configuration_error', 'offline',
                'transport_timeout', 'probe_cancelled', 'probe_deadline_exceeded', 'sender_busy', 'backup_failed'];
            $this->result['error'] = $error instanceof TuyaCloudException && in_array($error->getMessage(), $safe, true)
                ? $error->getMessage() : 'configuration_error';
            if ($error instanceof TuyaCloudException) {
                $this->result['writeOutcomeUnknown'] = $this->result['writeOutcomeUnknown'] || $error->writeOutcomeUnknown;
                if (is_string($error->vendorCode) && preg_match('/\A[0-9]{1,8}\z/D', $error->vendorCode)) {
                    $this->result['vendorCode'] = $error->vendorCode;
                }
            }
            // Stop here: no final endpoint, restoration, or write retry after
            // cancellation, uncertain transport, or changed external ownership.
        } finally {
            foreach (array_reverse($locks) as $lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            if ($trapped) {
                $this->untrap();
            }
        }
        $this->result['elapsedMs'] = (int) round((($this->clock)() - $this->started) * 1000);
        $this->result['executionStatus'] = $this->result['ok'] ? 'complete' : 'stopped';
        if ($this->reportName !== null) {
            try {
                $this->save($this->reportName, $this->result);
                $this->result['report'] = $this->reportName;
            } catch (Throwable) {
                $this->result['ok'] = false;
                $this->result['reportSaved'] = false;
            }
        }
        $summary = $this->result;
        unset($summary['timings']);
        $this->line(json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $this->result['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function plan(CloudLightingFiles $files, array $state, string $operation, ?string $alias, string $profile, int $duration, int $tick): array
    {
        $values = $state['values'];
        if (($values[20] ?? null) !== true) {
            throw new TuyaCloudException('source_mismatch');
        }
        $source = null;
        if (($values[21] ?? null) === 'white') {
            foreach ($files->profiles as $name => $white) {
                if (($values[22] ?? null) === $white['brightness'] && ($values[23] ?? null) === $white['temperature']) {
                    $source = $this->whiteChannels($white);
                    $this->result['sourceProfile'] = $name;
                }
            }
        } elseif (($values[21] ?? null) === 'scene' && $operation === 'dark') {
            foreach ($files->scenes as $name => $scene) {
                if (($values[25] ?? null) === $scene['raw']) {
                    $source = $this->sceneChannels($scene);
                    $this->result['sourceAlias'] = $name;
                    $this->result['sceneExitApproximation'] = 'first_unit_hsv_not_current_output';
                }
            }
            if ($source === null) {
                throw new TuyaCloudException('unknown_scene');
            }
        }
        if ($source === null || ($operation !== 'dark' && ($this->result['sourceProfile'] ?? null) !== 'dark')) {
            throw new TuyaCloudException('source_mismatch');
        }
        $white = $files->profiles[$operation === 'white' ? $profile : 'dark'];
        $targetChannels = $this->whiteChannels($white);
        $target = [20 => true, 21 => 'white', 22 => $white['brightness'], 23 => $white['temperature']];
        $endpoint = [['code' => 'bright_value_v2', 'value' => $white['brightness']],
            ['code' => 'temp_value_v2', 'value' => $white['temperature']], ['code' => 'work_mode', 'value' => 'white']];
        $entryAnchor = $exitBridge = null;
        if ($operation === 'scene') {
            $scene = $files->scenes[$alias] ?? null;
            if ($scene === null) {
                throw new TuyaCloudException('unknown_scene');
            }
            $targetChannels = $this->sceneChannels($scene);
            $entryAnchor = $targetChannels;
            $entryAnchor['v'] = 10;
            $source = $entryAnchor;
            $target = [20 => true, 21 => 'scene', 25 => $scene['raw']];
            $endpoint = [['code' => 'scene_data_v2', 'value' => $scene['value']], ['code' => 'work_mode', 'value' => 'scene']];
            $this->result['targetAlias'] = $alias;
        } else {
            $this->result['targetProfile'] = $operation === 'white' ? $profile : 'dark';
        }
        if (isset($this->result['sceneExitApproximation'])) {
            $exitBridge = $targetChannels;
            $targetChannels = $source;
            $targetChannels['v'] = 10;
        }
        $this->result['minimumAnchorFrames'] = (int) ($entryAnchor !== null) + (int) ($exitBridge !== null);
        $this->result['anchorSettleMs'] = $this->result['minimumAnchorFrames'] > 0 ? 400 : 0;
        $frames = [];
        $count = intdiv($duration, $tick);
        if ($count + $this->result['minimumAnchorFrames'] > 80) {
            throw new TuyaCloudException('configuration_error');
        }
        for ($index = 1; $index <= $count; $index++) {
            $offset = $index === $count ? $duration : $index * $tick;
            $fraction = $offset / $duration;
            $from = $source;
            $to = $targetChannels;
            $weight = $operation === 'white' ? $fraction ** 2.2 : $fraction * $fraction * (3 - 2 * $fraction);
            $value = [];
            foreach (['h', 's', 'v', 'bright', 'temperature'] as $field) {
                $value[$field] = (int) round($from[$field] + ($to[$field] - $from[$field]) * $weight);
            }
            $this->validateChannels($value);
            $frames[] = ['offset' => $offset / 1000, 'value' => $value];
        }
        foreach ([$entryAnchor, $exitBridge] as $anchor) {
            if ($anchor !== null) {
                $this->validateChannels($anchor);
            }
        }

        return [$frames, $target, $endpoint, $entryAnchor, $exitBridge];
    }

    private function validateChannels(array $value): void
    {
        // Native string DP28 uses H360 and S/V1000, not the standard
        // cloud JSON schema's incompatible S/V255 channel range.
        foreach (['h', 's', 'v', 'bright', 'temperature'] as $field) {
            if (! is_int($value[$field] ?? null) || $value[$field] < 0 || $value[$field] > ($field === 'h' ? 360 : 1000)) {
                throw new TuyaCloudException('configuration_error');
            }
        }
        if (($value['v'] > 0 && $value['v'] < 10) || ($value['bright'] > 0 && $value['bright'] < 10)
            || $value['v'] + $value['bright'] < 10) {
            throw new TuyaCloudException('configuration_error');
        }
    }

    private function whiteChannels(array $white): array
    {
        return ['h' => 0, 's' => 0, 'v' => 0, 'bright' => $white['brightness'], 'temperature' => $white['temperature']];
    }

    private function sceneChannels(array $scene): array
    {
        $unit = $scene['value']['scene_units'][0];
        if ($unit['bright'] !== 0 || $unit['temperature'] !== 0 || $unit['v'] !== 1000) {
            throw new TuyaCloudException('configuration_error');
        }

        return array_intersect_key($unit, array_flip(['h', 's', 'v', 'bright', 'temperature']));
    }

    private function send(array $commands, string $stage, ?float $slot = null): array
    {
        $this->guard();
        if ($stage !== 'endpoint') {
            $this->validateChannels($commands);
        }
        $begun = ($this->clock)();
        $this->result['commandsAttempted']++;
        $this->result['writeOutcomeUnknown'] = true;
        try {
            $receipt = $stage === 'endpoint' ? $this->client->sendCommands($commands) : $this->client->sendRealtime($commands);
        } finally {
            $this->result['timings'][] = ['stage' => $stage, 'slotMs' => $slot === null ? null : (int) round($slot * 1000),
                'startedMs' => (int) round(($begun - $this->started) * 1000),
                'requestMs' => (int) round((($this->clock)() - $begun) * 1000)];
        }
        if (! is_int($receipt['sentAt'] ?? null)) {
            throw new TuyaCloudException('transport_timeout', writeOutcomeUnknown: true);
        }
        $this->result['writeOutcomeUnknown'] = false;
        $this->guard();

        return $receipt;
    }

    private function read(): array
    {
        $this->guard();
        $report = $this->client->readProperties();
        $this->guard();
        if (! is_int($report['serverTime'] ?? null) || ! is_array($report['properties'] ?? null)) {
            throw new TuyaCloudException('configuration_error');
        }
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

        return ['values' => $values, 'times' => $times, 'serverTime' => $report['serverTime']];
    }

    private function confirmStoredState(array $target, array $before, array $receipt): void
    {
        $until = ($this->clock)() + 5;
        do {
            $state = $this->read();
            $matches = $fresh = true;
            $changed = false;
            foreach ($target as $dp => $value) {
                $matches = $matches && ($state['values'][$dp] ?? null) === $value;
                if (($before['values'][$dp] ?? null) !== $value) {
                    $changed = true;
                    $fresh = $fresh && ($state['times'][$dp] ?? 0) >= $receipt['sentAt']
                        && ($state['times'][$dp] ?? 0) > ($before['times'][$dp] ?? 0);
                }
            }
            if (! $changed) {
                $fresh = false;
                foreach (array_keys($target) as $dp) {
                    $fresh = $fresh || ($dp !== 20 && ($state['times'][$dp] ?? 0) >= $receipt['sentAt']
                        && ($state['times'][$dp] ?? 0) > ($before['times'][$dp] ?? 0));
                }
            }
            if ($matches && $fresh) {
                $this->result['readbackMatched'] = true;
                $this->result['stored'] = ['on' => $state['values'][20], 'mode' => $state['values'][21]];

                return;
            }
            $this->waitUntil(min($until, ($this->clock)() + 0.5));
        } while (($this->clock)() < $until);
        throw new TuyaCloudException('transport_timeout', writeOutcomeUnknown: true);
    }

    private function requireState(array $state, array $expected): void
    {
        foreach ($expected as $dp => $value) {
            if (($state['values'][$dp] ?? null) !== $value) {
                throw new TuyaCloudException('source_mismatch');
            }
        }
    }

    private function guard(): void
    {
        if ($this->cancelled || ($this->externalCancellation)()) {
            throw new TuyaCloudException('probe_cancelled');
        }
        if (($this->clock)() - $this->started >= 30) {
            throw new TuyaCloudException('probe_deadline_exceeded');
        }
    }

    private function waitUntil(float $until): void
    {
        while (true) {
            $this->guard();
            $remaining = $until - ($this->clock)();
            if ($remaining <= 0) {
                return;
            }
            ($this->pause)((int) ceil(min(0.05, $remaining) * 1000));
        }
    }

    private function integerOption(mixed $value, int $min, int $max): bool
    {
        return is_string($value) && preg_match('/\A[0-9]{1,5}\z/D', $value) && (int) $value >= $min && (int) $value <= $max;
    }

    private function requireRealtimeModel(array $model): void
    {
        $matches = 0;
        foreach ($model['services'] ?? [] as $service) {
            foreach ($service['properties'] ?? [] as $property) {
                if (($property['abilityId'] ?? null) !== 28 && ($property['code'] ?? null) !== 'control_data') {
                    continue;
                }
                if (($service['code'] ?? null) !== '' || ($property['abilityId'] ?? null) !== 28
                    || ($property['code'] ?? null) !== 'control_data' || ($property['accessMode'] ?? null) !== 'wr'
                    || ($property['typeSpec']['type'] ?? null) !== 'string'
                    || ! is_int($property['typeSpec']['maxlen'] ?? null) || $property['typeSpec']['maxlen'] < 21) {
                    throw new TuyaCloudException('configuration_error');
                }
                $matches++;
            }
        }
        if ($matches !== 1) {
            throw new TuyaCloudException('configuration_error');
        }
    }

    private function senderPath(): string
    {
        $connection = DB::connection();
        $identity = $connection->getDriverName().':'.$connection->getConfig('host').':'.$connection->getDatabaseName();

        return storage_path('framework/lighting-sender-'.hash('sha256', $identity).'.lock');
    }

    private function lock(string $path)
    {
        if (is_link($path)) {
            throw new TuyaCloudException('sender_busy');
        }
        $handle = fopen($path, 'c');
        if ($handle === false || ! chmod($path, 0600) || ! flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new TuyaCloudException('sender_busy');
        }

        return $handle;
    }

    private function save(string $name, array $data): void
    {
        if (! is_dir($this->directory) || is_link($this->directory) || (fileperms($this->directory) & 0077) !== 0) {
            throw new TuyaCloudException('backup_failed');
        }
        $temporary = tempnam($this->directory, '.continuous-');
        $handle = null;
        try {
            if ($temporary === false || ! chmod($temporary, 0600)) {
                throw new TuyaCloudException('backup_failed');
            }
            $handle = fopen($temporary, 'wb');
            $payload = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
            if ($handle === false || fwrite($handle, $payload) !== strlen($payload) || ! fflush($handle) || ! fsync($handle)) {
                throw new TuyaCloudException('backup_failed');
            }
            fclose($handle);
            $handle = null;
            if (! rename($temporary, $this->directory.'/'.$name)) {
                throw new TuyaCloudException('backup_failed');
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_string($temporary) && file_exists($temporary)) {
                unlink($temporary);
            }
        }
    }
}
