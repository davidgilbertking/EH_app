<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LightingProbeCloudCommandTest extends TestCase
{
    private const DEVICE = 'fixture_cloud_probe_lamp';

    private const SECRET = 'fixture-probe-secret-0123456789';

    private const TOKEN = 'fixture_probe_access_token';

    private string $directory;

    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->directory = sys_get_temp_dir().'/eh-cloud-probe-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        config(['lighting.cloud' => [
            'device_id' => self::DEVICE, 'endpoint' => 'https://openapi.tuyaeu.com',
            'client_id' => 'fixture_probe_client_id', 'client_secret' => self::SECRET,
            'private_directory' => $this->directory,
        ]]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    #[DataProvider('invalidCli')]
    public function test_invalid_device_or_operation_never_contacts_cloud(array $arguments): void
    {
        Http::fake();
        [$exit, $report] = $this->runProbe($arguments, addExpectedDevice: false);

        $this->assertSame(1, $exit);
        $this->assertSame('configuration_error', $report['error']);
        $this->assertSame(0, $report['write_attempts']);
        $this->assertFalse($report['lighting_changed']);
        Http::assertNothingSent();
    }

    public static function invalidCli(): array
    {
        return [
            'missing expected device' => [['operation' => 'status']],
            'wrong expected device' => [['operation' => 'white', '--expect-device-id' => 'another_fixture_lamp']],
            'unknown operation' => [['operation' => 'toggle', '--expect-device-id' => self::DEVICE]],
        ];
    }

    public function test_status_is_get_only_and_outputs_no_credentials_or_raw_scene(): void
    {
        $rawScene = '0737370200c703e803e800000000';
        $this->fakeCloud([21 => 'scene', 25 => $rawScene, 99 => 'private_unrelated_property']);
        [$exit, $report, $output] = $this->runProbe(['operation' => 'status']);

        $this->assertSame(0, $exit);
        $this->assertTrue($report['ok']);
        $this->assertSame(0, $report['write_attempts']);
        $this->assertNull($report['endpoint_reported']);
        $this->assertFalse($report['visual_confirmation_required']);
        $this->assertSame(hash('sha256', $rawScene), $report['after'][25]['value']);
        $this->assertArrayNotHasKey(99, $report['after']);
        foreach ([self::SECRET, self::TOKEN, 'fixture_probe_client_id', $rawScene, 'private_unrelated_property'] as $private) {
            $this->assertStringNotContainsString($private, $output);
        }
        $this->assertOnlyReads();
    }

    #[DataProvider('unsafeSources')]
    public function test_write_probes_reject_unverified_source_without_a_command(string $operation, array $source): void
    {
        $this->writeCalibration();
        $this->fakeCloud($source);
        [$exit, $report] = $this->runProbe(['operation' => $operation]);

        $this->assertSame(1, $exit);
        $this->assertSame('unsupported_transition', $report['error']);
        $this->assertSame(0, $report['write_attempts']);
        $this->assertFalse($report['lighting_changed']);
        $this->assertOnlyReads();
    }

    public static function unsafeSources(): array
    {
        return [
            'scene from bright white' => ['scene', [22 => 1000, 23 => 332]],
            'white from bright white' => ['white', [22 => 901, 23 => 197]],
            'white from powered off' => ['white', [20 => false]],
            'white from incomplete dark shadow' => ['white', [23 => null]],
            'dark from unknown scene' => ['dark', [21 => 'scene', 25 => 'uncaptured_scene']],
            'dark from ordinary white' => ['dark', [22 => 1000, 23 => 332]],
        ];
    }

    #[DataProvider('invalidWhiteOptions')]
    public function test_invalid_white_options_never_send_a_command(array $options): void
    {
        $this->writeCalibration();
        $this->fakeCloud();
        [$exit, $report] = $this->runProbe(['operation' => 'white'] + $options);

        $this->assertSame(1, $exit);
        $this->assertSame('configuration_error', $report['error']);
        $this->assertSame(0, $report['write_attempts']);
        $this->assertOnlyReads();
    }

    public static function invalidWhiteOptions(): array
    {
        return [
            'unknown profile' => [['--profile' => 'arbitrary']],
            'too long' => [['--duration-ms' => '30001']],
            'too short' => [['--duration-ms' => '999']],
            'malformed duration' => [['--duration-ms' => '1000junk']],
        ];
    }

    public function test_ambiguous_first_write_is_not_retried_or_followed_by_a_commit(): void
    {
        $this->writeCalibration();
        $this->fakeCloud();
        [$exit, $report, $output] = $this->runProbe(['operation' => 'white', '--duration-ms' => '1000']);

        $this->assertSame(1, $exit);
        $this->assertSame('transport_timeout', $report['error']);
        $this->assertSame(1, $report['write_attempts']);
        $this->assertSame('unknown', $report['lighting_changed']);
        $this->assertStringNotContainsString(self::SECRET, $output);
        // Laravel does not record a fake callback that throws before returning
        // a response, so count attempted requests independently for this case.
        $this->assertCount(3, $this->requests);
        $write = $this->requests[2];
        $this->assertSame('POST', $write->method());
        $this->assertStringEndsWith('/commands', $write->url());
        $this->assertSame([['code' => 'control_data', 'value' => [
            'change_mode' => 'gradient', 'h' => 0, 's' => 0, 'v' => 0, 'bright' => 10, 'temperature' => 0]]], $write['commands']);
    }

    private function runProbe(array $arguments, bool $addExpectedDevice = true): array
    {
        if ($addExpectedDevice) {
            $arguments += ['--expect-device-id' => self::DEVICE];
        }
        $exit = Artisan::call('lighting:probe-cloud', $arguments);
        $output = Artisan::output();

        return [$exit, json_decode($output, true, flags: JSON_THROW_ON_ERROR), $output];
    }

    private function fakeCloud(array $dps = []): void
    {
        $properties = [];
        foreach ($dps + [20 => true, 21 => 'white', 22 => 10, 23 => 0] as $dp => $value) {
            $properties[] = ['dp_id' => $dp, 'value' => $value, 'time' => 1700000000000];
        }
        Http::fake(function (Request $request) use ($properties) {
            $this->requests[] = $request;
            if (str_contains($request->url(), '/token?')) {
                return Http::response(['success' => true, 'result' => ['access_token' => self::TOKEN, 'expire_time' => 3600]]);
            }
            if (str_ends_with($request->url(), '/shadow/properties')) {
                return Http::response(['success' => true, 't' => 1700000000100, 'result' => ['properties' => $properties]]);
            }
            throw new ConnectionException('fixture timeout containing '.self::SECRET);
        });
    }

    private function assertOnlyReads(): void
    {
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    }

    private function writeCalibration(): void
    {
        $range = static fn (int $min, int $max): array => ['min' => $min, 'max' => $max, 'scale' => 0, 'step' => 1];
        $functions = [
            ['switch_led', 'Boolean', []], ['work_mode', 'Enum', ['range' => ['white', 'scene']]],
            ['bright_value_v2', 'Integer', $range(10, 1000)], ['temp_value_v2', 'Integer', $range(0, 1000)],
            ['control_data', 'Json', ['change_mode' => ['range' => ['gradient']],
                'h' => $range(0, 360), 's' => $range(0, 255), 'v' => $range(0, 255)]],
            ['scene_data_v2', 'Json', []],
        ];
        $profiles = [];
        foreach (['dark' => [1, 0, 10, 0], 'action' => [100, 33, 1000, 332], 'encounters' => [90, 20, 901, 197]] as $name => $values) {
            $profiles[$name] = ['ui' => ['brightness' => $values[0], 'temperature' => $values[1]],
                'dps' => ['20' => true, '21' => 'white', '22' => $values[2], '23' => $values[3]],
                'user_confirmed' => true, 'replay_verified' => true];
        }
        foreach ([
            'cloud-functions-inspection.json' => ['device_id' => self::DEVICE, 'response' => ['success' => true,
                'result' => ['functions' => array_map(static fn ($entry) => ['code' => $entry[0], 'type' => $entry[1], 'values' => $entry[2]], $functions)]]],
            'calibration.json' => ['schema_version' => 1, 'device_id' => self::DEVICE, 'protocol' => '3.5', 'white_profiles' => $profiles],
        ] as $name => $document) {
            file_put_contents($this->directory.'/'.$name, json_encode($document, JSON_THROW_ON_ERROR));
            chmod($this->directory.'/'.$name, 0600);
        }
    }
}
