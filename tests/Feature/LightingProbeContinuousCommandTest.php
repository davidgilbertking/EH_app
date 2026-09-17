<?php

namespace Tests\Feature;

use App\Console\Commands\LightingProbeContinuousCommand;
use App\Lighting\Drivers\TuyaCloudClient;
use App\Lighting\Drivers\TuyaCloudException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LightingProbeContinuousCommandTest extends TestCase
{
    private string $directory;

    private object $client;

    private const DEVICE = 'fixture_native_lamp';

    private const RAW = '0737370200c703e803e800000000';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->directory = sys_get_temp_dir().'/eh-continuous-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        config(['lighting.cloud.private_directory' => $this->directory]);
        $this->fixtures();
        $this->client = new class extends TuyaCloudClient
        {
            public float $time = 0;

            public array $writes = [];

            public array $writeTimes = [];

            public array $values = [20 => true, 21 => 'white', 22 => 901, 23 => 197, 25 => '0737370200c703e803e800000000'];

            public array $times = [];

            public bool $modelValid = true;

            public bool $cancelled = false;

            public ?int $cancelAfter = null;

            public ?int $failAt = null;

            public bool $staleEndpoint = false;

            public bool $externalChange = false;

            public float $writeDelay = 0.01;

            public int $reads = 0;

            public bool $lateEndpoint = false;

            private ?array $pending = null;

            public function __construct()
            {
                $this->times = array_fill_keys(array_keys($this->values), 1699999999000);
            }

            public function deviceId(): string
            {
                return 'fixture_native_lamp';
            }

            public function readModel(): array
            {
                return ['services' => [['code' => '', 'properties' => [['abilityId' => 28, 'code' => 'control_data',
                    'accessMode' => $this->modelValid ? 'wr' : 'ro', 'typeSpec' => ['type' => 'string', 'maxlen' => 255]]]]]];
            }

            public function readProperties(): array
            {
                $this->reads++;
                $this->time += 0.001;
                if ($this->externalChange && $this->reads === 2) {
                    $this->values[20] = false;
                }
                if ($this->pending !== null && $this->reads >= 4) {
                    $this->apply($this->pending);
                    $this->pending = null;
                }

                return ['serverTime' => $this->wall(), 'properties' => array_map(fn ($dp) => [
                    'dp_id' => $dp, 'value' => $this->values[$dp], 'time' => $this->times[$dp],
                ], array_keys($this->values))];
            }

            public function sendRealtime(array $channels): array
            {
                return $this->write(['realtime' => $channels]);
            }

            public function sendCommands(array $commands): array
            {
                $receipt = $this->write(['commands' => $commands]);
                if ($this->lateEndpoint) {
                    $this->pending = $commands;
                } else {
                    $this->apply($commands);
                }

                return $receipt;
            }

            private function apply(array $commands): void
            {
                $mapping = ['work_mode' => 21, 'bright_value_v2' => 22, 'temp_value_v2' => 23, 'scene_data_v2' => 25];
                foreach ($commands as $command) {
                    $dp = $mapping[$command['code']];
                    $this->values[$dp] = $dp === 25 ? '0737370200c703e803e800000000' : $command['value'];
                    if (! $this->staleEndpoint) {
                        $this->times[$dp] = $this->wall();
                    }
                }
            }

            private function write(array $body): array
            {
                $sent = $this->wall();
                $this->writes[] = $body;
                $this->writeTimes[] = $this->time;
                $this->time += $this->writeDelay;
                if (count($this->writes) === $this->failAt) {
                    throw new TuyaCloudException('transport_timeout', writeOutcomeUnknown: true);
                }
                if (count($this->writes) === $this->cancelAfter) {
                    $this->cancelled = true;
                }

                return ['sentAt' => $sent, 'acceptedAt' => $this->wall()];
            }

            private function wall(): int
            {
                return 1700000000000 + (int) floor($this->time * 1000);
            }
        };
        $command = new LightingProbeContinuousCommand($this->client,
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

    private function runProbe(string $operation, array $options = []): array
    {
        $code = Artisan::call('lighting:probe-continuous', $options + [
            'operation' => $operation, '--expect-device-id' => self::DEVICE,
        ]);

        return [$code, json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR)];
    }

    private function setDark(): void
    {
        $this->client->values[21] = 'white';
        $this->client->values[22] = 10;
        $this->client->values[23] = 0;
    }

    private function assertNeverPowersOrZeros(): void
    {
        foreach ($this->client->writes as $write) {
            if (isset($write['realtime'])) {
                $this->assertGreaterThanOrEqual(10, $write['realtime']['v'] + $write['realtime']['bright']);
                foreach (['v', 'bright'] as $channel) {
                    $this->assertTrue($write['realtime'][$channel] === 0 || $write['realtime'][$channel] >= 10,
                        'Each active light channel must stay above the firmware clipping floor.');
                }
            } else {
                foreach ($write['commands'] as $command) {
                    $this->assertNotContains($command['code'], ['switch_led', 'switch_gradient']);
                }
            }
        }
    }

    public function test_white_dark_uses_nonzero_frames_one_endpoint_and_private_backup(): void
    {
        [$code, $result] = $this->runProbe('dark');
        $this->assertSame(0, $code, json_encode($result));
        $this->assertSame('encounters', $result['sourceProfile']);
        $this->assertTrue($result['readbackMatched']);
        $this->assertFalse($result['physicalConfirmed']);
        $this->assertSame(9, $result['commandsAttempted']);
        $this->assertSame(['h' => 0, 's' => 0, 'v' => 0, 'bright' => 10, 'temperature' => 0], $this->client->writes[7]['realtime']);
        $this->assertCount(3, $this->client->writes[8]['commands']);
        $this->assertSame(0600, fileperms($this->directory.'/'.$result['backup']) & 0777);
        $this->assertSame(0600, fileperms($this->directory.'/'.$result['report']) & 0777);
        $this->assertNeverPowersOrZeros();
        $this->assertStringNotContainsString(self::DEVICE, json_encode($result));
        $this->assertStringNotContainsString(self::RAW, json_encode($result));
    }

    public function test_white_rise_has_no_source_reset_and_uses_perceptual_curve(): void
    {
        $this->setDark();
        [$code, $result] = $this->runProbe('white');
        $this->assertSame(0, $code, json_encode($result));
        $this->assertSame(25, $result['commandsAttempted']);
        $this->assertArrayHasKey('realtime', $this->client->writes[0]);
        $brightness = array_column(array_column(array_slice($this->client->writes, 0, -1), 'realtime'), 'bright');
        $this->assertSame(1000, end($brightness));
        $this->assertLessThan(100, $brightness[5]);
        $sorted = $brightness;
        sort($sorted);
        $this->assertSame($sorted, $brightness);
        $this->assertNeverPowersOrZeros();
    }

    public function test_scene_entry_preserves_native_1000_hsv_and_exact_captured_endpoint(): void
    {
        $this->setDark();
        [$code, $result] = $this->runProbe('scene', ['--alias' => 'blue']);
        $this->assertSame(0, $code, json_encode($result));
        $this->assertSame(['h' => 199, 's' => 1000, 'v' => 10, 'bright' => 0, 'temperature' => 0], $this->client->writes[0]['realtime']);
        $this->assertSame(10, $result['commandsAttempted']);
        $this->assertSame(9, $result['framesSent']);
        $this->assertSame(4000, $result['durationMs']);
        $this->assertSame(1, $result['minimumAnchorFrames']);
        $this->assertGreaterThanOrEqual(0.4, $this->client->writeTimes[1] - $this->client->writeTimes[0] - $this->client->writeDelay);
        $last = $this->client->writes[count($this->client->writes) - 2]['realtime'];
        $this->assertSame(['h' => 199, 's' => 1000, 'v' => 1000, 'bright' => 0, 'temperature' => 0], $last);
        $saved = json_decode(file_get_contents($this->directory.'/cloud-scenes.json'), true)['scenes']['blue']['value'];
        $this->assertSame($saved, end($this->client->writes)['commands'][0]['value']);
        $this->assertSame('scene', end($this->client->writes)['commands'][1]['value']);
        $this->assertArrayNotHasKey('experimentalChannelMapping', $result);
        $this->assertNeverPowersOrZeros();
    }

    public function test_known_scene_exit_is_explicitly_approximate_and_never_replays_preset(): void
    {
        $this->client->values[21] = 'scene';
        [$code, $result] = $this->runProbe('dark');
        $this->assertSame(0, $code, json_encode($result));
        $this->assertSame('first_unit_hsv_not_current_output', $result['sceneExitApproximation']);
        $frames = array_column(array_slice($this->client->writes, 0, -1), 'realtime');
        $this->assertSame(10, $result['commandsAttempted']);
        $this->assertSame(4000, $result['durationMs']);
        $this->assertSame(['h' => 199, 's' => 1000, 'v' => 10, 'bright' => 0, 'temperature' => 0], $frames[count($frames) - 2]);
        $this->assertSame(0, end($frames)['v']);
        $this->assertSame(10, end($frames)['bright']);
        foreach (array_slice($frames, 0, -1) as $frame) {
            $this->assertSame(199, $frame['h']);
            $this->assertSame(0, $frame['bright']);
        }
        $lastRgb = count($frames) - 2;
        $this->assertGreaterThanOrEqual(0.4, $this->client->writeTimes[$lastRgb + 1] - $this->client->writeTimes[$lastRgb] - $this->client->writeDelay);
        $this->assertNeverPowersOrZeros();
    }

    public function test_slow_scene_entry_cannot_skip_its_minimum_anchor(): void
    {
        $this->setDark();
        $this->client->writeDelay = 1.1;
        [$code, $result] = $this->runProbe('scene', ['--alias' => 'blue']);
        $this->assertSame(0, $code, json_encode($result));
        $this->assertGreaterThan(0, $result['skippedSlots']);
        $this->assertSame(10, $this->client->writes[0]['realtime']['v']);
        $this->assertSame(0, $this->client->writes[0]['realtime']['bright']);
        $this->assertGreaterThanOrEqual(0.4, $this->client->writeTimes[1] - $this->client->writeTimes[0] - $this->client->writeDelay);
        $this->assertSame(1000, $this->client->writes[count($this->client->writes) - 2]['realtime']['v']);
        $this->assertNeverPowersOrZeros();
    }

    public function test_uncertain_minimum_entry_anchor_stops_before_growth_or_endpoint(): void
    {
        $this->setDark();
        $this->client->failAt = 1;
        [$code, $result] = $this->runProbe('scene', ['--alias' => 'blue']);
        $this->assertSame(1, $code);
        $this->assertTrue($result['writeOutcomeUnknown']);
        $this->assertFalse($result['endpointAttempted']);
        $this->assertCount(1, $this->client->writes);
        $this->assertSame(10, $this->client->writes[0]['realtime']['v']);
        $this->assertNeverPowersOrZeros();
    }

    public function test_uncertain_exit_bridge_stops_without_retry_or_white_endpoint(): void
    {
        $this->client->values[21] = 'scene';
        $this->client->failAt = 9;
        [$code, $result] = $this->runProbe('dark');
        $this->assertSame(1, $code);
        $this->assertTrue($result['writeOutcomeUnknown']);
        $this->assertFalse($result['endpointAttempted']);
        $this->assertCount(9, $this->client->writes);
        $this->assertSame(10, $this->client->writes[7]['realtime']['v']);
        $this->assertSame(['h' => 0, 's' => 0, 'v' => 0, 'bright' => 10, 'temperature' => 0], $this->client->writes[8]['realtime']);
        $this->assertNeverPowersOrZeros();
    }

    public function test_wrong_source_or_model_rejects_before_any_write(): void
    {
        $this->client->values[20] = false;
        [$code] = $this->runProbe('dark');
        $this->assertSame(1, $code);
        $this->client->values[20] = true;
        $this->client->values[21] = 'scene';
        $this->client->values[25] = 'foreign';
        [$code, $result] = $this->runProbe('dark');
        $this->assertSame('unknown_scene', $result['error']);
        $this->client->values[21] = 'white';
        [$code] = $this->runProbe('scene', ['--alias' => 'blue']);
        $this->assertSame(1, $code);
        $this->client->modelValid = false;
        [$code] = $this->runProbe('dark');
        $this->assertSame(1, $code);
        $this->assertSame([], $this->client->writes);
    }

    public function test_wrong_device_bad_duration_and_tampered_preset_reject_before_writes(): void
    {
        [$code] = $this->runProbe('dark', ['--expect-device-id' => 'another_device']);
        $this->assertSame(1, $code);
        [$code] = $this->runProbe('dark', ['--duration-ms' => '21000']);
        $this->assertSame(2, $code);
        $saved = json_decode(file_get_contents($this->directory.'/scene-presets.json'), true);
        $saved['presets']['blue']['sha256'] = str_repeat('0', 64);
        $this->save('scene-presets.json', $saved);
        [$code] = $this->runProbe('dark');
        $this->assertSame(1, $code);
        $this->assertSame([], $this->client->writes);
    }

    public function test_uncertain_post_stops_without_resend_or_endpoint(): void
    {
        $this->client->failAt = 2;
        [$code, $result] = $this->runProbe('dark');
        $this->assertSame(1, $code);
        $this->assertCount(2, $this->client->writes);
        $this->assertTrue($result['writeOutcomeUnknown']);
        $this->assertFalse($result['endpointAttempted']);
    }

    public function test_cancellation_after_frame_stops_without_endpoint(): void
    {
        $this->client->cancelAfter = 2;
        [$code, $result] = $this->runProbe('dark');
        $this->assertSame(1, $code);
        $this->assertSame('probe_cancelled', $result['error']);
        $this->assertCount(2, $this->client->writes);
        $this->assertFalse($result['endpointAttempted']);
    }

    public function test_slow_posts_skip_slots_without_catchup_bursts(): void
    {
        $this->client->writeDelay = 1.1;
        [$code, $result] = $this->runProbe('dark');
        $this->assertSame(0, $code, json_encode($result));
        $this->assertGreaterThan(0, $result['skippedSlots']);
        foreach ($this->client->writeTimes as $index => $time) {
            if ($index > 0) {
                $this->assertGreaterThanOrEqual(0.5, $time - $this->client->writeTimes[$index - 1]);
            }
        }
        $this->assertLessThan(9, $result['commandsAttempted']);
    }

    public function test_accepted_endpoint_with_stale_shadow_is_not_success_or_retried(): void
    {
        $this->client->staleEndpoint = true;
        [$code, $result] = $this->runProbe('dark');
        $this->assertSame(1, $code);
        $this->assertFalse($result['readbackMatched']);
        $this->assertCount(9, $this->client->writes);
        $this->assertTrue($result['endpointAttempted']);
    }

    public function test_late_full_report_reconciles_endpoint_without_resend(): void
    {
        $this->client->lateEndpoint = true;
        [$code, $result] = $this->runProbe('dark');
        $this->assertSame(0, $code, json_encode($result));
        $this->assertTrue($result['readbackMatched']);
        $this->assertCount(9, $this->client->writes);
        $this->assertGreaterThanOrEqual(4, $this->client->reads);
    }

    public function test_external_change_prevents_final_endpoint(): void
    {
        $this->client->externalChange = true;
        [$code, $result] = $this->runProbe('dark');
        $this->assertSame(1, $code);
        $this->assertSame('source_mismatch', $result['error']);
        $this->assertFalse($result['endpointAttempted']);
        $this->assertCount(8, $this->client->writes);
    }

    public function test_existing_diagnostic_lock_prevents_any_write(): void
    {
        $lock = fopen($this->directory.'/probe.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            [$code, $result] = $this->runProbe('dark');
            $this->assertSame(1, $code);
            $this->assertSame('sender_busy', $result['error']);
            $this->assertSame([], $this->client->writes);
        } finally {
            fclose($lock);
        }
    }
}
