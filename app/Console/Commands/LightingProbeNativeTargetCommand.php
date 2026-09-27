<?php

namespace App\Console\Commands;

use App\Lighting\Drivers\CloudLightingFiles;
use App\Lighting\Drivers\NativeCloudLightingDriver;
use App\Lighting\Drivers\TuyaCloudClient;
use App\Lighting\Drivers\TuyaCloudException;
use App\Models\LightingState;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Two identical nonzero scene targets; no promise of firmware timing or hold. */
class LightingProbeNativeTargetCommand extends Command
{
    protected $signature = 'lighting:probe-native-target
        {--expect-device-id= : Exact selected device ID}
        {--timing-byte=30 : Experimental scene timing byte, 1..100; not milliseconds}
        {--observe-ms=25000 : Read-only observation period, 12000..30000 ms}';

    protected $description = 'Probe two identical native Dark scene units, then commit white Dark without power commands';

    private Closure $clock;

    private Closure $pause;

    private Closure $externalCancellation;

    private bool $cancelled = false;

    private float $started;

    private string $directory;

    private string $reportName;

    private array $result = [];

    private NativeCloudLightingDriver $reader;

    public function __construct(private ?TuyaCloudClient $client = null, ?Closure $pause = null,
        ?Closure $clock = null, ?Closure $cancelled = null)
    {
        parent::__construct();
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
        $this->pause = $pause ?? static fn (int $ms) => usleep($ms * 1000);
        $this->externalCancellation = $cancelled ?? static fn (): bool => false;
    }

    public function handle(): int
    {
        $expected = $this->option('expect-device-id');
        $byte = $this->option('timing-byte');
        $observe = $this->option('observe-ms');
        if (! is_string($expected) || ! preg_match('/\A[A-Za-z0-9_-]{6,128}\z/D', $expected)
            || ! $this->integerOption($byte, 1, 100) || ! $this->integerOption($observe, 12000, 30000)) {
            $this->line(json_encode(['ok' => false, 'error' => 'invalid_probe_arguments', 'commandsAttempted' => 0]));

            return self::INVALID;
        }
        $this->started = ($this->clock)();
        $this->cancelled = false;
        $this->result = ['ok' => false, 'commandsAttempted' => 0, 'powerCommandsSent' => 0,
            'sceneStoredConfirmed' => false, 'readbackMatched' => false, 'sceneMayBeActive' => false,
            'writeOutcomeUnknown' => false, 'physicalConfirmed' => false, 'holdVerified' => false,
            'visualVerificationRequired' => true, 'timingByte' => (int) $byte, 'observeMs' => (int) $observe,
            'unitCount' => 2, 'targetWhite' => ['brightness' => 10, 'temperature' => 0], 'steps' => []];
        $this->reportName = '';
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
            $files = new CloudLightingFiles($this->directory, $expected, $this->client);
            $db = DB::connection();
            $identity = $db->getDriverName().':'.$db->getConfig('host').':'.$db->getDatabaseName();
            $locks[] = $this->lock(storage_path('framework/lighting-sender-'.hash('sha256', $identity).'.lock'));
            $locks[] = $this->lock($this->directory.'/probe.lock');
            $this->guard();
            $this->reader = new NativeCloudLightingDriver($this->client);
            $source = $this->read();
            $this->requireSource($source, $files);
            [$value, $raw] = $this->program($source, $files, (int) $byte);
            $suffix = gmdate('Ymd\THis\Z').'-'.Str::uuid();
            $this->result['backup'] = 'native-target-before-'.$suffix.'.json';
            $this->save($this->result['backup'], ['schema_version' => 1, 'device_id' => $expected,
                'source' => $source, 'scene_value' => $value, 'native_scene_raw' => $raw]);
            $this->reportName = 'native-target-probe-'.$suffix.'.json';
            $sceneTarget = [20 => true, 21 => 'scene', 25 => $raw];
            $scene = $this->write('scene', [
                ['code' => 'scene_data_v2', 'value' => $value], ['code' => 'work_mode', 'value' => 'scene'],
            ], $source, $sceneTarget, [21, 25]);
            $this->result['sceneStoredConfirmed'] = true;
            $observeStarted = ($this->clock)();
            $until = $observeStarted + (int) $observe / 1000;
            while (($this->clock)() < $until) {
                $this->waitUntil(min($until, ($this->clock)() + 1));
                $scene = $this->read();
                $this->requireMatch($scene, $sceneTarget);
            }
            $this->result['observedMs'] = (int) round((($this->clock)() - $observeStarted) * 1000);
            $target = [20 => true, 21 => 'white', 22 => 10, 23 => 0];
            $this->write('white_endpoint', [
                ['code' => 'bright_value_v2', 'value' => 10], ['code' => 'temp_value_v2', 'value' => 0],
                ['code' => 'work_mode', 'value' => 'white'],
            ], $scene, $target, [21]);
            $this->result['sceneMayBeActive'] = false;
            $this->result['readbackMatched'] = true;
            $this->result['ok'] = true;
        } catch (Throwable $error) {
            $safe = ['configuration_error', 'device_mismatch', 'source_mismatch', 'scene_mapping_missing',
                'offline', 'transport_timeout', 'sender_busy', 'mailbox_pending', 'backup_failed',
                'probe_cancelled', 'probe_deadline_exceeded'];
            $this->result['error'] = $error instanceof TuyaCloudException && in_array($error->getMessage(), $safe, true)
                ? $error->getMessage() : 'configuration_error';
            if ($error instanceof TuyaCloudException) {
                $this->result['writeOutcomeUnknown'] = $this->result['writeOutcomeUnknown'] || $error->writeOutcomeUnknown;
            }
            // No catch/finally writes: uncertainty or lost ownership must not
            // replay a scene or overwrite a later manual/application action.
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
        if ($this->reportName !== '') {
            try {
                $this->save($this->reportName, $this->result);
                $this->result['report'] = $this->reportName;
            } catch (Throwable) {
                $this->result['ok'] = false;
                $this->result['reportSaved'] = false;
            }
        }
        $this->line(json_encode($this->result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $this->result['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function requireSource(array $source, CloudLightingFiles $files): void
    {
        foreach ($files->profiles as $alias => $profile) {
            if ($this->matches($source, [20 => true, 21 => 'white', 22 => $profile['brightness'], 23 => $profile['temperature']])) {
                $this->result['sourceProfile'] = $alias;

                return;
            }
        }
        throw new TuyaCloudException('source_mismatch');
    }

    private function program(array $source, CloudLightingFiles $files, int $byte): array
    {
        $raw = $source['values'][25] ?? null;
        if (! is_string($raw) || ! preg_match('/\A[0-9a-fA-F]{28,210}\z/D', $raw) || (strlen($raw) - 2) % 26 !== 0) {
            throw new TuyaCloudException('scene_mapping_missing');
        }
        $header = substr($raw, 0, 2);
        $number = null;
        foreach ($files->scenes as $scene) {
            if (hexdec(substr($scene['raw'], 0, 2)) === hexdec($header)
                && $scene['value']['scene_num'] === hexdec($header) + 1) {
                $number = $scene['value']['scene_num'];
            }
        }
        if ($number === null) {
            throw new TuyaCloudException('scene_mapping_missing');
        }
        $unit = ['unit_switch_duration' => $byte, 'unit_gradient_duration' => $byte,
            'unit_change_mode' => 'gradient', 'h' => 0, 's' => 0, 'v' => 0, 'bright' => 10, 'temperature' => 0];
        $schema = $files->schema['scene_data_v2']['scene_units'];
        if (! in_array('gradient', $schema['unit_change_mode']['range'] ?? [], true)) {
            throw new TuyaCloudException('configuration_error');
        }
        foreach ($unit as $field => $value) {
            if ($field !== 'unit_change_mode') {
                CloudLightingFiles::range($value, $schema[$field] ?? null);
            }
        }
        $hex = sprintf('%02x%02x02%04x%04x%04x%04x%04x', $byte, $byte, 0, 0, 0, 10, 0);

        return [['scene_num' => $number, 'scene_units' => [$unit, $unit]], $header.$hex.$hex];
    }

    private function write(string $stage, array $commands, array $before, array $target, array $requiredFresh): array
    {
        $this->guard();
        $latest = $this->read();
        $this->requireMatch($latest, $before['values']);
        $this->result['commandsAttempted']++;
        $this->result['sceneMayBeActive'] = true;
        $this->result['writeOutcomeUnknown'] = true;
        $this->result['stage'] = $stage;
        $this->save($this->reportName, $this->result); // Durable marker before POST.
        $this->guard();
        $receipt = $this->client->sendCommands($commands);
        if (! is_int($receipt['sentAt'] ?? null)) {
            throw new TuyaCloudException('transport_timeout', writeOutcomeUnknown: true);
        }
        $until = ($this->clock)() + 5;
        do {
            $snapshot = $this->read();
            $fresh = true;
            foreach ($target as $dp => $value) {
                if (in_array($dp, $requiredFresh, true) || ($latest['values'][$dp] ?? null) !== $value) {
                    $fresh = $fresh && ($snapshot['times'][$dp] ?? 0) >= $receipt['sentAt']
                        && ($snapshot['times'][$dp] ?? 0) > ($latest['times'][$dp] ?? 0);
                }
            }
            if ($fresh && $this->matches($snapshot, $target)) {
                $this->result['writeOutcomeUnknown'] = false;
                $this->result['steps'][] = ['stage' => $stage, 'storedConfirmed' => true,
                    'elapsedMs' => (int) round((($this->clock)() - $this->started) * 1000)];

                return $snapshot;
            }
            $this->waitUntil(min($until, ($this->clock)() + 0.5));
        } while (($this->clock)() < $until);
        throw new TuyaCloudException('transport_timeout', writeOutcomeUnknown: true);
    }

    private function read(): array
    {
        $this->guard();
        $snapshot = $this->reader->readSnapshot();
        $this->guard();

        return $snapshot;
    }

    private function matches(array $snapshot, array $target): bool
    {
        foreach ($target as $dp => $value) {
            if (($snapshot['values'][$dp] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    private function requireMatch(array $snapshot, array $target): void
    {
        if (! $this->matches($snapshot, $target)) {
            throw new TuyaCloudException('source_mismatch');
        }
    }

    private function guard(): void
    {
        if ($this->cancelled || ($this->externalCancellation)()) {
            throw new TuyaCloudException('probe_cancelled');
        }
        if (($this->clock)() - $this->started >= 50) {
            throw new TuyaCloudException('probe_deadline_exceeded');
        }
        $state = LightingState::findOrFail(1);
        if ($state->enabled && $state->desired_target !== null && $state->revision !== $state->applied_revision) {
            throw new TuyaCloudException('mailbox_pending');
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
            ($this->pause)((int) ceil(min(0.1, $remaining) * 1000));
        }
    }

    private function integerOption(mixed $value, int $min, int $max): bool
    {
        return is_string($value) && preg_match('/\A[0-9]{1,5}\z/D', $value) && (int) $value >= $min && (int) $value <= $max;
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
        $temporary = tempnam($this->directory, '.native-target-');
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
