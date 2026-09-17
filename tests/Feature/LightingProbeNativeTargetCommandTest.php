<?php

namespace Tests\Feature;

use App\Console\Commands\LightingProbeNativeTargetCommand;
use App\Lighting\Drivers\TuyaCloudClient;
use App\Lighting\Drivers\TuyaCloudException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LightingProbeNativeTargetCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private object $client;

    private const DEVICE = 'fixture_native_lamp';

    private const RAW = '0737370200c703e803e800000000';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->directory = sys_get_temp_dir().'/eh-native-target-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        config(['lighting.cloud.private_directory' => $this->directory]);
        $this->fixtures();
        $this->client = new class extends TuyaCloudClient
        {
            public float $time = 0;

            public array $values = [20 => true, 21 => 'white', 22 => 901, 23 => 197, 25 => '0737370200c703e803e800000000'];

            public array $times = [];

            public array $writes = [];

            public array $writeTimes = [];

            public int $reads = 0;

            public ?int $failAt = null;

            public ?int $staleAt = null;

            public ?int $cancelAfter = null;

            public bool $cancelled = false;

            public bool $foreignScene = false;

            public bool $newIntent = false;

            public function __construct()
            {
                $this->values[35] = base64_encode("\0".substr(pack('N', 800), 1).substr(pack('N', 800), 1));
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
                $this->reads++;
                $this->time += 0.001;
                if ($this->foreignScene && count($this->writes) === 1 && $this->time > 2) {
                    $this->values[25] = 'foreign_scene';
                }
                if ($this->newIntent && count($this->writes) === 1 && $this->time > 2) {
                    DB::table('lighting_states')->where('id', 1)->update([
                        'enabled' => true, 'revision' => 9, 'applied_revision' => 0, 'desired_target' => '{"kind":"white","profile":"action"}',
                    ]);
                }

                return ['serverTime' => $this->wall(), 'properties' => array_map(fn ($dp) => [
                    'dp_id' => $dp, 'value' => $this->values[$dp], 'time' => $this->times[$dp],
                ], array_keys($this->values))];
            }

            public function sendCommands(array $commands): array
            {
                $sentAt = $this->wall();
                $this->writes[] = $commands;
                $this->writeTimes[] = $this->time;
                $this->time += 0.01;
                if (count($this->writes) === $this->failAt) {
                    throw new TuyaCloudException('transport_timeout', writeOutcomeUnknown: true);
                }
                $mapping = ['work_mode' => 21, 'bright_value_v2' => 22, 'temp_value_v2' => 23, 'scene_data_v2' => 25];
                foreach ($commands as $command) {
                    $dp = $mapping[$command['code']];
                    $value = $command['value'];
                    if ($dp === 25) {
                        $raw = sprintf('%02x', $value['scene_num'] - 1);
                        foreach ($value['scene_units'] as $unit) {
                            $raw .= sprintf('%02x%02x02%04x%04x%04x%04x%04x', $unit['unit_switch_duration'],
                                $unit['unit_gradient_duration'], $unit['h'], $unit['s'], $unit['v'], $unit['bright'], $unit['temperature']);
                        }
                        $value = $raw;
                    }
                    $this->values[$dp] = $value;
                    if (count($this->writes) !== $this->staleAt) {
                        $this->times[$dp] = $this->wall();
                    }
                }
                if (count($this->writes) === $this->cancelAfter) {
                    $this->cancelled = true;
                }

                return ['sentAt' => $sentAt, 'acceptedAt' => $this->wall()];
            }

            private function wall(): int
            {
                return 1700000000000 + (int) floor($this->time * 1000);
            }
        };
        $command = new LightingProbeNativeTargetCommand($this->client,
            pause: function (int $ms): void {
                $this->client->time += $ms / 1000;
            },
            clock: fn () => $this->client->time, cancelled: fn () => $this->client->cancelled);
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

    private function runProbe(array $options = []): array
    {
        $code = Artisan::call('lighting:probe-native-target', $options + ['--expect-device-id' => self::DEVICE]);

        return [$code, json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR)];
    }

    public function test_two_identical_nonzero_targets_then_one_white_endpoint_without_power_commands(): void
    {
        [$code,$result] = $this->runProbe();
        $this->assertSame(0, $code, json_encode($result));
        $this->assertSame(2, $result['commandsAttempted']);
        $this->assertSame(30, $result['timingByte']);
        $this->assertTrue($result['sceneStoredConfirmed']);
        $this->assertTrue($result['readbackMatched']);
        $this->assertFalse($result['sceneMayBeActive']);
        $this->assertFalse($result['physicalConfirmed']);
        $this->assertFalse($result['holdVerified']);
        $this->assertGreaterThanOrEqual(25000, $result['observedMs']);
        $units = $this->client->writes[0][0]['value']['scene_units'];
        $this->assertCount(2, $units);
        $this->assertSame($units[0], $units[1]);
        $this->assertSame(['unit_switch_duration' => 30, 'unit_gradient_duration' => 30, 'unit_change_mode' => 'gradient',
            'h' => 0, 's' => 0, 'v' => 0, 'bright' => 10, 'temperature' => 0], $units[0]);
        $this->assertSame(8, $this->client->writes[0][0]['value']['scene_num']);
        $this->assertSame([
            ['code' => 'bright_value_v2', 'value' => 10], ['code' => 'temp_value_v2', 'value' => 0],
            ['code' => 'work_mode', 'value' => 'white'],
        ], $this->client->writes[1]);
        foreach ($this->client->writes as $write) {
            foreach ($write as $command) {
                $this->assertNotContains($command['code'], ['switch_led', 'switch_gradient', 'control_data']);
            }
        }
        $this->assertGreaterThanOrEqual(25, $this->client->writeTimes[1] - $this->client->writeTimes[0]);
        $backup = json_decode(file_get_contents($this->directory.'/'.$result['backup']), true);
        $this->assertSame('07'.str_repeat('1e1e02000000000000000a0000', 2), $backup['native_scene_raw']);
        $this->assertSame(0600, fileperms($this->directory.'/'.$result['backup']) & 0777);
        $this->assertSame(0600, fileperms($this->directory.'/'.$result['report']) & 0777);
        $this->assertStringNotContainsString(self::DEVICE, json_encode($result));
        $this->assertStringNotContainsString(self::RAW, json_encode($result));
    }

    public function test_noncalibrated_white_off_and_scene_sources_fail_before_any_write(): void
    {
        $this->client->values[22] = 900;
        [$code] = $this->runProbe();
        $this->assertSame(1, $code);
        $this->client->values[22] = 901;
        $this->client->values[20] = false;
        [$code] = $this->runProbe();
        $this->assertSame(1, $code);
        $this->client->values[20] = true;
        $this->client->values[21] = 'scene';
        [$code] = $this->runProbe();
        $this->assertSame(1, $code);
        $this->assertSame([], $this->client->writes);
    }

    public function test_invalid_device_timing_or_observation_fail_before_writes(): void
    {
        foreach ([['--expect-device-id' => 'foreign_device'], ['--timing-byte' => '0'], ['--timing-byte' => '101'],
            ['--observe-ms' => '11999'], ['--observe-ms' => '30001']] as $options) {
            [$code] = $this->runProbe($options);
            $this->assertNotSame(0, $code);
        }
        $this->assertSame([], $this->client->writes);
    }

    public function test_unmapped_native_header_and_unconfirmed_presets_fail_closed(): void
    {
        $this->client->values[25] = '00'.substr(self::RAW, 2);
        [$code,$result] = $this->runProbe();
        $this->assertSame(1, $code);
        $this->assertSame('scene_mapping_missing', $result['error']);
        $this->client->values[25] = self::RAW;
        $saved = json_decode(file_get_contents($this->directory.'/scene-presets.json'), true);
        $saved['presets']['blue']['user_confirmed'] = false;
        $this->save('scene-presets.json', $saved);
        [$code] = $this->runProbe();
        $this->assertSame(1, $code);
        $this->assertSame([], $this->client->writes);
    }

    public function test_pending_mailbox_prevents_any_post(): void
    {
        DB::table('lighting_states')->where('id', 1)->update(['enabled' => true, 'revision' => 2, 'applied_revision' => 1,
            'desired_target' => '{"kind":"white","profile":"action"}']);
        [$code,$result] = $this->runProbe();
        $this->assertSame(1, $code);
        $this->assertSame('mailbox_pending', $result['error']);
        $this->assertSame([], $this->client->writes);
    }

    public function test_new_intent_during_observation_prevents_endpoint(): void
    {
        $this->client->newIntent = true;
        [$code,$result] = $this->runProbe();
        $this->assertSame(1, $code);
        $this->assertSame('mailbox_pending', $result['error']);
        $this->assertCount(1, $this->client->writes);
        $this->assertTrue($result['sceneMayBeActive']);
    }

    public function test_uncertain_scene_post_is_never_retried_or_followed_by_endpoint(): void
    {
        $this->client->failAt = 1;
        [$code,$result] = $this->runProbe();
        $this->assertSame(1, $code);
        $this->assertCount(1, $this->client->writes);
        $this->assertTrue($result['writeOutcomeUnknown']);
        $this->assertTrue($result['sceneMayBeActive']);
        $this->assertFalse($result['readbackMatched']);
    }

    public function test_stale_scene_ack_is_not_confirmation_and_no_endpoint_is_sent(): void
    {
        $this->client->staleAt = 1;
        [$code,$result] = $this->runProbe();
        $this->assertSame(1, $code);
        $this->assertCount(1, $this->client->writes);
        $this->assertFalse($result['sceneStoredConfirmed']);
    }

    public function test_cancellation_and_foreign_scene_do_not_trigger_cleanup_write(): void
    {
        $this->client->cancelAfter = 1;
        [$code,$result] = $this->runProbe();
        $this->assertSame('probe_cancelled', $result['error']);
        $this->assertCount(1, $this->client->writes);
    }

    public function test_foreign_scene_during_observation_is_not_overwritten(): void
    {
        $this->client->foreignScene = true;
        [$code,$result] = $this->runProbe();
        $this->assertSame('source_mismatch', $result['error']);
        $this->assertCount(1, $this->client->writes);
    }

    public function test_uncertain_white_endpoint_never_resends(): void
    {
        $this->client->failAt = 2;
        [$code,$result] = $this->runProbe();
        $this->assertSame(1, $code);
        $this->assertCount(2, $this->client->writes);
        $this->assertTrue($result['writeOutcomeUnknown']);
        $this->assertFalse($result['readbackMatched']);
    }

    public function test_existing_probe_lock_prevents_any_post(): void
    {
        $lock = fopen($this->directory.'/probe.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            [$code,$result] = $this->runProbe();
            $this->assertSame('sender_busy',$result['error']);
            $this->assertSame([],$this->client->writes);
        } finally {
            fclose($lock);
        }
    }
}
