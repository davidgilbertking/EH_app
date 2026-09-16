<?php

namespace Tests\Feature;

use App\Console\Commands\LightingProbeCloudNativeCommand;
use App\Lighting\Drivers\TuyaCloudClient;
use App\Lighting\Drivers\TuyaCloudException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LightingProbeCloudNativeCommandTest extends TestCase
{
    private string $directory;

    private object $client;

    private const DEVICE = 'fixture_native_lamp';

    private const RAW = '0737370200c703e803e800000000';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->directory = sys_get_temp_dir().'/eh-native-cloud-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        config(['lighting.cloud.private_directory' => $this->directory]);
        $this->fixtures();
        $this->client = new class extends TuyaCloudClient
        {
            public float $time = 0;

            public array $writes = [];

            public array $values = [20 => true, 21 => 'scene', 22 => 1000, 23 => 332, 25 => '0737370200c703e803e800000000'];

            public array $times = [];

            public ?int $failAt = null;

            public bool $staleGradient = false;

            public bool $tamperAfterOn = false;

            public bool $cancelOnWait = false;

            public bool $cancelled = false;

            private float $lastWrite = 0;

            public function __construct()
            {
                $this->values[35] = $this->encoded(500, 600);
                $this->times = array_fill_keys(array_keys($this->values), 1699999999000);
            }

            public function deviceId(): string
            {
                return 'fixture_native_lamp';
            }

            public function readModel(): array
            {
                return ['services' => [['code' => '', 'properties' => [['abilityId' => 35, 'code' => 'switch_gradient',
                    'accessMode' => 'rw', 'typeSpec' => ['type' => 'raw', 'maxlen' => 128]]]]]];
            }

            public function readProperties(): array
            {
                $this->time += 0.001;
                if ($this->tamperAfterOn && count($this->writes) === 4 && $this->time - $this->lastWrite > 0.5) {
                    $this->values[35] = $this->encoded(999, 999);
                }
                $properties = [];
                foreach ($this->values as $dp => $value) {
                    $properties[] = ['dp_id' => $dp, 'value' => $value, 'time' => $this->times[$dp]];
                }

                return ['serverTime' => $this->wall(), 'properties' => $properties];
            }

            public function sendSwitchGradient(mixed $onMs, mixed $offMs): array
            {
                return $this->mutate([35 => $this->encoded($onMs, $offMs)]);
            }

            public function sendCommands(array $commands): array
            {
                $mapping = ['switch_led' => 20, 'work_mode' => 21, 'bright_value_v2' => 22, 'temp_value_v2' => 23];
                $changes = [];
                foreach ($commands as $command) {
                    $changes[$mapping[$command['code']]] = $command['value'];
                }

                return $this->mutate($changes);
            }

            private function mutate(array $changes): array
            {
                $this->writes[] = $changes;
                if (count($this->writes) === $this->failAt) {
                    throw new TuyaCloudException('transport_timeout', writeOutcomeUnknown: true);
                }
                $this->time += 0.01;
                $this->lastWrite = $this->time;
                foreach ($changes as $dp => $value) {
                    $this->values[$dp] = $value;
                    if ($dp !== 35 || ! $this->staleGradient) {
                        $this->times[$dp] = $this->wall();
                    }
                }

                return ['sentAt' => $this->wall(), 'acceptedAt' => $this->wall()];
            }

            private function wall(): int
            {
                return 1700000000000 + (int) floor($this->time * 1000);
            }

            private function encoded(int $on, int $off): string
            {
                return base64_encode("\0".substr(pack('N', $on), 1).substr(pack('N', $off), 1));
            }
        };
        $command = new LightingProbeCloudNativeCommand($this->client, pause: function (int $ms): void {
            $this->client->time += $ms / 1000;
            if ($this->client->cancelOnWait && count($this->client->writes) === 2) {
                $this->client->cancelled = true;
            }
        }, clock: fn () => $this->client->time, cancelled: fn () => $this->client->cancelled);
        $this->app->make(Kernel::class)->registerCommand($command);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    private function fixtures(): void
    {
        $range = fn ($min, $max) => ['min' => $min, 'max' => $max, 'scale' => 0, 'step' => 1];
        $units = ['unit_change_mode' => ['range' => ['static', 'jump', 'gradient']], 'unit_switch_duration' => $range(0, 100),
            'unit_gradient_duration' => $range(0, 100), 'h' => $range(0, 360), 's' => $range(0, 1000), 'v' => $range(0, 1000),
            'bright' => $range(0, 1000), 'temperature' => $range(0, 1000)];
        $spec = [['switch_led', 'Boolean', []], ['work_mode', 'Enum', ['range' => ['white', 'scene']]],
            ['bright_value_v2', 'Integer', $range(10, 1000)], ['temp_value_v2', 'Integer', $range(0, 1000)],
            ['control_data', 'Json', ['change_mode' => ['range' => ['gradient']], 'h' => $range(0, 360), 's' => $range(0, 255),
                'v' => $range(0, 255), 'bright' => $range(0, 1000), 'temperature' => $range(0, 1000)]],
            ['scene_data_v2', 'Json', ['scene_num' => $range(1, 8), 'scene_units' => $units]]];
        $this->save('cloud-functions-inspection.json', ['device_id' => self::DEVICE, 'response' => ['success' => true,
            'result' => ['functions' => array_map(fn ($entry) => ['code' => $entry[0], 'type' => $entry[1], 'values' => $entry[2]], $spec)]]]);
        $identity = ['schema_version' => 1, 'device_id' => self::DEVICE, 'protocol' => '3.5'];
        $profiles = [];
        foreach (['dark' => [1, 0, 10, 0], 'action' => [100, 33, 1000, 332], 'encounters' => [90, 20, 901, 197]] as $name => $value) {
            $profiles[$name] = ['ui' => ['brightness' => $value[0], 'temperature' => $value[1]],
                'dps' => [20 => true, 21 => 'white', 22 => $value[2], 23 => $value[3]], 'user_confirmed' => true, 'replay_verified' => true];
        }
        $this->save('calibration.json', $identity + ['white_profiles' => $profiles]);
        $this->save('scene-presets.json', $identity + ['presets' => ['blue' => ['dps' => [21 => 'scene', 25 => self::RAW],
            'sha256' => hash('sha256', self::RAW), 'user_confirmed' => true]]]);
        $this->save('cloud-scenes.json', ['schema_version' => 1, 'device_id' => self::DEVICE, 'scenes' => ['blue' => [
            'source_sha256' => hash('sha256', self::RAW), 'source_header' => 7, 'mapping_verified' => true,
            'value' => ['scene_num' => 8, 'scene_units' => [['unit_switch_duration' => 55, 'unit_gradient_duration' => 55,
                'unit_change_mode' => 'gradient', 'h' => 199, 's' => 1000, 'v' => 1000, 'bright' => 0, 'temperature' => 0]]]]]]);
    }

    private function save(string $name, array $value): void
    {
        file_put_contents($this->directory.'/'.$name, json_encode($value, JSON_THROW_ON_ERROR));
        chmod($this->directory.'/'.$name, 0600);
    }

    private function runProbe(string $operation, array $options = []): array
    {
        $code = Artisan::call('lighting:probe-cloud-native', $options + ['operation' => $operation, '--expect-device-id' => self::DEVICE]);

        return [$code, json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR)];
    }

    public function test_status_is_read_only_and_reports_decoded_gradient_without_identifiers(): void
    {
        [$code, $result] = $this->runProbe('status');
        $this->assertSame(0, $code);
        $this->assertSame(['onMs' => 500, 'offMs' => 600], $result['originalGradient']);
        $this->assertSame([], $this->client->writes);
        $this->assertSame([], glob($this->directory.'/cloud-native-*.json'));
        $this->assertStringNotContainsString(self::DEVICE, json_encode($result));
        $this->assertStringNotContainsString(self::RAW, json_encode($result));
    }

    public function test_dark_orders_five_writes_keeps_white_setup_off_and_restores_only_owned_gradient(): void
    {
        [$code, $result] = $this->runProbe('dark');
        $this->assertSame(0, $code, json_encode($result));
        $this->assertSame(5, $result['commandsAttempted']);
        $this->assertSame([20 => false], $this->client->writes[1]);
        $this->assertSame([21 => 'white', 22 => 10, 23 => 0], $this->client->writes[2]);
        $this->assertSame([20 => true], $this->client->writes[3]);
        $this->assertTrue($result['gradientRestored']);
        $this->assertFalse($result['physicalConfirmed']);
        $this->assertSame(10, $result['stored']['brightness']);
        $this->assertGreaterThanOrEqual(5300, $result['elapsedMs']);
        $backup = $this->directory.'/'.$result['backup'];
        $this->assertSame(0600, fileperms($backup) & 0777);
        $this->assertSame(['onMs' => 500, 'offMs' => 600], json_decode(file_get_contents($backup), true)['original_switch_gradient']);
    }

    public function test_white_uses_8_second_on_and_800ms_off_from_exact_dark(): void
    {
        $this->client->values[21] = 'white';
        $this->client->values[22] = 10;
        $this->client->values[23] = 0;
        [$code, $result] = $this->runProbe('white');
        $this->assertSame(0, $code, json_encode($result));
        $this->assertSame(['onMs' => 8000, 'offMs' => 800], $result['temporaryGradient']);
        $this->assertSame([21 => 'white', 22 => 1000, 23 => 332], $this->client->writes[2]);
        $this->assertGreaterThanOrEqual(9600, $result['elapsedMs']);
        $this->assertSame(1000, $result['stored']['brightness']);
        $this->assertTrue($result['gradientRestored']);
    }

    public function test_unknown_scene_wrong_white_source_or_device_never_write(): void
    {
        [$code, $result] = $this->runProbe('dark', ['--expect-device-id' => 'another_fixture_device']);
        $this->assertSame('device_mismatch', $result['error']);
        $this->client->values[25] = 'unknown';
        [$code, $result] = $this->runProbe('dark');
        $this->assertSame('unknown_scene', $result['error']);
        [$code, $result] = $this->runProbe('white');
        $this->assertSame('source_mismatch', $result['error']);
        $this->assertSame([], $this->client->writes);
    }

    public function test_stale_gradient_readback_stops_before_switch_off_without_retry(): void
    {
        $this->client->staleGradient = true;
        [$code, $result] = $this->runProbe('dark');
        $this->assertSame(1, $code);
        $this->assertSame('transport_timeout', $result['error']);
        $this->assertSame(1, $result['commandsAttempted']);
        $this->assertCount(1, $this->client->writes);
        $this->assertTrue($this->client->values[20]);
        $this->assertLessThan(6000, $result['elapsedMs']);
    }

    public function test_white_setup_failure_leaves_lamp_off_and_never_runs_cleanup_or_retry(): void
    {
        $this->client->failAt = 3;
        [$code, $result] = $this->runProbe('dark');
        $this->assertSame(1, $code);
        $this->assertSame('transport_timeout', $result['error']);
        $this->assertCount(3, $this->client->writes);
        $this->assertFalse($this->client->values[20]);
        $this->assertFalse($result['gradientRestored']);
        $this->assertTrue($result['writeOutcomeUnknown']);
    }

    public function test_cancellation_after_confirmed_off_never_switches_on(): void
    {
        $this->client->cancelOnWait = true;
        [$code, $result] = $this->runProbe('dark');
        $this->assertSame(1, $code);
        $this->assertSame('probe_cancelled', $result['error']);
        $this->assertCount(2, $this->client->writes);
        $this->assertFalse($this->client->values[20]);
        $this->assertFalse($result['gradientRestored']);
    }

    public function test_external_gradient_change_prevents_restoration(): void
    {
        $this->client->tamperAfterOn = true;
        [$code, $result] = $this->runProbe('dark');
        $this->assertSame(1, $code);
        $this->assertSame('gradient_ownership_lost', $result['error']);
        $this->assertCount(4, $this->client->writes);
        $this->assertFalse($result['gradientRestored']);
    }

    public function test_backup_permission_failure_prevents_first_write(): void
    {
        chmod($this->directory, 0750);
        [$code, $result] = $this->runProbe('dark');
        $this->assertSame(1, $code);
        $this->assertSame('backup_failed', $result['error']);
        $this->assertSame([], $this->client->writes);
    }

    public function test_out_of_range_timing_is_rejected_before_any_write(): void
    {
        [$code, $result] = $this->runProbe('dark', ['--duration-ms' => '60001']);
        $this->assertSame(2, $code);
        $this->assertSame('invalid_probe_arguments', $result['error']);
        $this->assertSame([], $this->client->writes);
    }
}
