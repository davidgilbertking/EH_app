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

/** Bounded commissioning only: no worker activation, write retries, or failure undo. */
class LightingProbeCloudNativeCommand extends Command
{
    protected $signature = 'lighting:probe-cloud-native
        {operation=status : status, dark, or white}
        {--expect-device-id= : Exact selected physical device ID}
        {--duration-ms= : Native OFF duration for dark, ON duration for white; defaults 4000/8000}
        {--off-ms=800 : Native OFF duration for the white experiment}';

    protected $description = 'Inspect or commission one bounded native cloud switch-gradient transition';

    private Closure $pause;

    private Closure $clock;

    private Closure $externalCancellation;

    private bool $cancelled = false;

    private float $started = 0;

    private array $result = [];

    private ?string $reportName = null;

    private string $directory = '';

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
        $duration = $this->option('duration-ms') ?? ($operation === 'dark' ? '4000' : '8000');
        $off = $this->option('off-ms');
        if (! in_array($operation, ['status', 'dark', 'white'], true) || ! is_string($expected)
            || ! preg_match('/\A[A-Za-z0-9_-]{6,128}\z/D', $expected)
            || ! $this->validDuration($duration) || ! $this->validDuration($off)) {
            $this->line(json_encode(['ok' => false, 'error' => 'invalid_probe_arguments', 'commandsAttempted' => 0]));

            return self::INVALID;
        }
        $this->started = ($this->clock)();
        $this->result = ['ok' => false, 'operation' => $operation, 'stage' => 'preflight',
            'commandsAttempted' => 0, 'writeOutcomeUnknown' => false, 'gradientRestored' => false,
            'physicalConfirmed' => false, 'visualVerificationRequired' => $operation !== 'status', 'steps' => []];
        $this->reportName = null;
        $this->cancelled = false;
        $lock = null;
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
            $this->guard();
            $model = $this->client->readModel();
            $this->requireGradientModel($model);
            if ($operation !== 'status') {
                $lock = $this->senderLock();
            }
            $state = $this->read();
            $original = $state['values'][35];
            $this->result['originalGradient'] = $original;
            $this->result['stored'] = $this->summary($state);
            if ($operation === 'status') {
                $this->result['ok'] = true;
                $this->result['stage'] = 'status';
            } else {
                $this->directory = config('lighting.cloud.private_directory');
                $files = new CloudLightingFiles($this->directory, $expected);
                $dark = $files->profiles['dark'];
                $target = $operation === 'dark' ? $dark : $files->profiles['action'];
                $source = [20 => true, 21 => $operation === 'dark' ? 'scene' : 'white'];
                if ($operation === 'dark') {
                    $alias = null;
                    foreach ($files->scenes as $name => $scene) {
                        if (($state['values'][25] ?? null) === $scene['raw']) {
                            $alias = $name;
                        }
                    }
                    if ($alias === null) {
                        throw new TuyaCloudException('unknown_scene');
                    }
                    $source[25] = $files->scenes[$alias]['raw'];
                    $this->result['sourceAlias'] = $alias;
                } else {
                    $source += [22 => $dark['brightness'], 23 => $dark['temperature']];
                }
                $this->requireState($state, $source);
                $owned = ['onMs' => $operation === 'dark' ? $original['onMs'] : (int) $duration,
                    'offMs' => $operation === 'dark' ? (int) $duration : (int) $off];
                $this->result['temporaryGradient'] = $owned;
                $suffix = gmdate('Ymd\THis\Z').'-'.Str::uuid();
                $backup = 'cloud-native-before-'.$suffix.'.json';
                $this->save($backup, ['schema_version' => 1, 'device_id' => $expected,
                    'operation' => $operation, 'original_switch_gradient' => $original]);
                $this->result['backup'] = $backup;
                $this->reportName = 'cloud-native-probe-'.$suffix.'.json';
                $this->persist();

                $state = $this->write('configure_gradient', $state, $source + [35 => $owned], [35],
                    fn () => $this->client->sendSwitchGradient($owned['onMs'], $owned['offMs']));
                $source[20] = false;
                $state = $this->write('switch_off', $state, $source + [35 => $owned], [20],
                    fn () => $this->client->sendCommands([['code' => 'switch_led', 'value' => false]]));
                $this->wait($owned['offMs'] + 400);
                $state = $this->read();
                $this->requireState($state, $source + [35 => $owned]);

                $white = [20 => false, 21 => 'white', 22 => $target['brightness'], 23 => $target['temperature'], 35 => $owned];
                $state = $this->write('prepare_white_while_off', $state, $white, [21, 22, 23],
                    fn () => $this->client->sendCommands([
                        ['code' => 'work_mode', 'value' => 'white'],
                        ['code' => 'bright_value_v2', 'value' => $target['brightness']],
                        ['code' => 'temp_value_v2', 'value' => $target['temperature']],
                    ]));
                $white[20] = true;
                $state = $this->write('switch_on', $state, $white, [20],
                    fn () => $this->client->sendCommands([['code' => 'switch_led', 'value' => true]]));
                $this->wait($owned['onMs'] + 400);
                $state = $this->read();
                if ($state['values'][35] !== $owned) {
                    throw new TuyaCloudException('gradient_ownership_lost');
                }
                $this->requireState($state, $white);
                $white[35] = $original;
                $state = $this->write('restore_gradient', $state, $white, [35],
                    fn () => $this->client->sendSwitchGradient($original['onMs'], $original['offMs']));
                $this->result['gradientRestored'] = true;
                $this->result['stored'] = $this->summary($state);
                $this->result['ok'] = true;
                $this->result['stage'] = 'complete';
            }
        } catch (Throwable $error) {
            $safe = ['configuration_error', 'offline', 'transport_timeout', 'device_mismatch', 'unknown_scene',
                'source_mismatch', 'gradient_ownership_lost', 'probe_cancelled', 'probe_deadline_exceeded', 'sender_busy', 'backup_failed'];
            $this->result['error'] = $error instanceof TuyaCloudException && in_array($error->getMessage(), $safe, true)
                ? $error->getMessage() : 'configuration_error';
            if ($error instanceof TuyaCloudException) {
                $this->result['writeOutcomeUnknown'] = $this->result['writeOutcomeUnknown'] || $error->writeOutcomeUnknown;
                if (is_string($error->vendorCode) && preg_match('/\A[0-9]{1,8}\z/D', $error->vendorCode)) {
                    $this->result['vendorCode'] = $error->vendorCode;
                }
            }
            // Intentionally no POST here: failure/cancellation never switches on,
            // restores settings over a changed owner, or replays an uncertain write.
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            if ($trapped) {
                $this->untrap();
            }
        }
        $this->result['elapsedMs'] = (int) round((($this->clock)() - $this->started) * 1000);
        try {
            $this->persist();
        } catch (Throwable) {
            $this->result['ok'] = false;
            $this->result['reportSaved'] = false;
        }
        $this->line(json_encode($this->result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $this->result['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function validDuration(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9]{1,5}\z/D', $value) && (int) $value <= 60000;
    }

    private function guard(): void
    {
        if ($this->cancelled || ($this->externalCancellation)()) {
            throw new TuyaCloudException('probe_cancelled');
        }
        if (($this->clock)() - $this->started >= 180) {
            throw new TuyaCloudException('probe_deadline_exceeded');
        }
    }

    private function requireGradientModel(array $model): void
    {
        $matches = [];
        foreach ($model['services'] ?? [] as $service) {
            foreach ($service['properties'] ?? [] as $property) {
                if (($service['code'] ?? null) === '' && ($property['abilityId'] ?? null) === 35) {
                    $matches[] = $property;
                }
            }
        }
        if (count($matches) !== 1 || ($matches[0]['code'] ?? null) !== 'switch_gradient'
            || ($matches[0]['accessMode'] ?? null) !== 'rw' || ($matches[0]['typeSpec']['type'] ?? null) !== 'raw') {
            throw new TuyaCloudException('configuration_error');
        }
    }

    private function read(): array
    {
        $this->guard();
        $report = $this->client->readProperties();
        $values = $times = [];
        foreach ($report['properties'] as $property) {
            $dp = $property['dp_id'] ?? $property['dpId'] ?? null;
            if (! is_int($dp) || ! in_array($dp, [20, 21, 22, 23, 25, 35], true)) {
                continue;
            }
            if (array_key_exists($dp, $values) || ! array_key_exists('value', $property)
                || ! is_int($property['time'] ?? null) || $property['time'] < 0 || $property['time'] > $report['serverTime'] + 1000) {
                throw new TuyaCloudException('configuration_error');
            }
            $values[$dp] = $dp === 35 ? $this->gradient($property['value']) : $property['value'];
            $times[$dp] = $property['time'];
        }
        if (! is_bool($values[20] ?? null) || ! is_string($values[21] ?? null)
            || ! is_int($values[22] ?? null) || ! is_int($values[23] ?? null) || ! isset($values[35])) {
            throw new TuyaCloudException('configuration_error');
        }

        return ['values' => $values, 'times' => $times];
    }

    private function gradient(mixed $value): array
    {
        if (! is_string($value)) {
            throw new TuyaCloudException('configuration_error');
        }
        $raw = strlen($value) === 7 && $value[0] === "\0" ? $value : base64_decode($value, true);
        if (! is_string($raw) || strlen($raw) !== 7 || $raw[0] !== "\0"
            || ($raw !== $value && base64_encode($raw) !== $value)) {
            throw new TuyaCloudException('configuration_error');
        }
        $on = (ord($raw[1]) << 16) | (ord($raw[2]) << 8) | ord($raw[3]);
        $off = (ord($raw[4]) << 16) | (ord($raw[5]) << 8) | ord($raw[6]);
        if ($on > 60000 || $off > 60000) {
            throw new TuyaCloudException('configuration_error');
        }

        return ['onMs' => $on, 'offMs' => $off];
    }

    private function requireState(array $state, array $expected): void
    {
        foreach ($expected as $dp => $value) {
            if (($state['values'][$dp] ?? null) !== $value) {
                throw new TuyaCloudException('source_mismatch');
            }
        }
    }

    private function write(string $step, array $before, array $expected, array $freshFields, Closure $send): array
    {
        $this->guard();
        $this->result['stage'] = $step;
        $this->result['commandsAttempted']++;
        $this->result['writeOutcomeUnknown'] = true;
        $this->persist();
        $this->guard();
        $receipt = $send();
        if (! is_int($receipt['sentAt'] ?? null)) {
            throw new TuyaCloudException('transport_timeout', writeOutcomeUnknown: true);
        }
        $until = ($this->clock)() + 5;
        do {
            $state = $this->read();
            $matches = true;
            foreach ($expected as $dp => $value) {
                $matches = $matches && ($state['values'][$dp] ?? null) === $value;
            }
            $fresh = false;
            foreach ($freshFields as $dp) {
                $fresh = $fresh || (($state['times'][$dp] ?? 0) >= $receipt['sentAt']
                    && ($state['times'][$dp] ?? 0) > ($before['times'][$dp] ?? 0));
            }
            if ($matches && $fresh) {
                $this->result['writeOutcomeUnknown'] = false;
                $this->result['stored'] = $this->summary($state);
                $this->result['steps'][] = ['step' => $step, 'storedConfirmed' => true, 'sentAt' => $receipt['sentAt']];
                $this->persist();

                return $state;
            }
            $this->wait(500);
        } while (($this->clock)() < $until);
        throw new TuyaCloudException('transport_timeout', writeOutcomeUnknown: true);
    }

    private function wait(int $milliseconds): void
    {
        for ($remaining = $milliseconds; $remaining > 0; $remaining -= $chunk) {
            $this->guard();
            $chunk = min(100, $remaining);
            ($this->pause)($chunk);
        }
        $this->guard();
    }

    private function summary(array $state): array
    {
        return ['on' => $state['values'][20], 'mode' => $state['values'][21],
            'brightness' => $state['values'][22], 'temperature' => $state['values'][23], 'gradient' => $state['values'][35]];
    }

    private function persist(): void
    {
        if ($this->reportName !== null) {
            $this->save($this->reportName, $this->result);
        }
    }

    private function save(string $name, array $data): void
    {
        if (! is_dir($this->directory) || is_link($this->directory) || (fileperms($this->directory) & 0077) !== 0) {
            throw new TuyaCloudException('backup_failed');
        }
        $temporary = tempnam($this->directory, '.cloud-native-');
        $handle = null;
        try {
            if ($temporary === false || ! chmod($temporary, 0600)) {
                throw new TuyaCloudException('backup_failed');
            }
            $handle = fopen($temporary, 'wb');
            $payload = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
            if ($handle === false || fwrite($handle, $payload) !== strlen($payload)
                || ! fflush($handle) || ! fsync($handle)) {
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

    private function senderLock()
    {
        $connection = DB::connection();
        $identity = $connection->getDriverName().':'.$connection->getConfig('host').':'.$connection->getDatabaseName();
        $handle = fopen(storage_path('framework/lighting-sender-'.hash('sha256', $identity).'.lock'), 'c');
        if ($handle === false || ! flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new TuyaCloudException('sender_busy');
        }

        return $handle;
    }
}
