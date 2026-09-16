<?php

namespace Tests\Unit;

use App\Lighting\Drivers\NativeCloudLightingDriver;
use App\Lighting\Drivers\TuyaCloudClient;
use App\Lighting\Drivers\TuyaCloudException;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NativeCloudLightingDriverTest extends TestCase
{
    private string $directory;

    private string $deviceId = 'fixture_device_123';

    private string $raw;

    private array $scene;

    private int $clock = 200000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/eh-native-cloud-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $limits = static fn (int $min, int $max): array => ['min' => $min, 'max' => $max, 'scale' => 0, 'step' => 1];
        $unitSchema = ['unit_change_mode' => ['range' => ['static', 'jump', 'gradient']],
            'unit_switch_duration' => $limits(0, 100), 'unit_gradient_duration' => $limits(0, 100),
            'h' => $limits(0, 360), 's' => $limits(0, 1000), 'v' => $limits(0, 1000),
            'bright' => $limits(0, 1000), 'temperature' => $limits(0, 1000)];
        $schema = [
            ['switch_led', 'Boolean', []], ['work_mode', 'Enum', ['range' => ['white', 'colour', 'scene', 'music']]],
            ['bright_value_v2', 'Integer', $limits(10, 1000)], ['temp_value_v2', 'Integer', $limits(0, 1000)],
            ['control_data', 'Json', ['change_mode' => ['range' => ['direct', 'gradient']], 'h' => $limits(0, 360),
                's' => $limits(0, 255), 'v' => $limits(0, 255), 'bright' => $limits(0, 1000), 'temperature' => $limits(0, 1000)]],
            ['scene_data_v2', 'Json', ['scene_num' => $limits(1, 8), 'scene_units' => $unitSchema]],
        ];
        $this->save('cloud-functions-inspection.json', ['device_id' => $this->deviceId, 'response' => ['success' => true,
            'result' => ['functions' => array_map(static fn ($entry) => ['code' => $entry[0], 'type' => $entry[1],
                'values' => json_encode($entry[2], JSON_THROW_ON_ERROR)], $schema)]]]);
        $profiles = [];
        foreach (['dark' => [1, 0, 10, 0], 'action' => [100, 33, 1000, 332], 'encounters' => [90, 20, 901, 197]] as $name => $values) {
            $profiles[$name] = ['ui' => ['brightness' => $values[0], 'temperature' => $values[1]],
                'dps' => ['20' => true, '21' => 'white', '22' => $values[2], '23' => $values[3]],
                'user_confirmed' => true, 'replay_verified' => true];
        }
        $this->save('calibration.json', $this->identity() + ['white_profiles' => $profiles]);
        // Uppercase raw is deliberately preserved; the cloud number is paired
        // explicitly with opaque LAN header 07 rather than inferred from it.
        $this->raw = '0737370200C703E803E800000000';
        $this->scene = ['scene_num' => 8, 'scene_units' => [['unit_switch_duration' => 55, 'unit_gradient_duration' => 55,
            'unit_change_mode' => 'gradient', 'h' => 199, 's' => 1000, 'v' => 1000, 'bright' => 0, 'temperature' => 0]]];
        $this->save('scene-presets.json', $this->identity() + ['presets' => ['blue' => [
            'dps' => ['21' => 'scene', '25' => $this->raw], 'sha256' => hash('sha256', $this->raw),
            'user_confirmed' => true, 'replay_verified' => false]]]);
        $this->save('cloud-scenes.json', ['schema_version' => 1, 'device_id' => $this->deviceId, 'scenes' => ['blue' => [
            'source_sha256' => hash('sha256', $this->raw), 'source_header' => 7, 'mapping_verified' => true, 'value' => $this->scene]]]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    private function identity(): array
    {
        return ['schema_version' => 1, 'device_id' => $this->deviceId, 'protocol' => '3.5'];
    }

    private function save(string $name, array $value): void
    {
        file_put_contents($this->directory.'/'.$name, json_encode($value, JSON_THROW_ON_ERROR));
        chmod($this->directory.'/'.$name, 0600);
    }

    private function edit(string $name, Closure $change): void
    {
        $value = json_decode(file_get_contents($this->directory.'/'.$name), true, flags: JSON_THROW_ON_ERROR);
        $this->save($name, $change($value));
    }

    private function model(): array
    {
        return ['services' => [['code' => '', 'properties' => [[
            'abilityId' => 35, 'code' => 'switch_gradient', 'accessMode' => 'rw', 'typeSpec' => ['type' => 'raw', 'maxlen' => 128],
        ]]]]];
    }

    private function client(?array $model = null): TuyaCloudClient
    {
        $client = $this->createMock(TuyaCloudClient::class);
        $client->method('deviceId')->willReturn($this->deviceId);
        $client->expects($this->once())->method('readModel')->willReturn($model ?? $this->model());

        return $client;
    }

    private function driver(TuyaCloudClient $client): NativeCloudLightingDriver
    {
        return new NativeCloudLightingDriver($client, ['private_directory' => $this->directory], fn () => $this->clock);
    }

    private function report(array $values = []): array
    {
        $values += [20 => true, 21 => 'white', 22 => 10, 23 => 0, 35 => 'AAADIAADIA=='];
        $properties = [];
        foreach ($values as $id => $value) {
            $properties[] = ['dp_id' => $id, 'value' => $value, 'time' => 199000];
        }

        return ['properties' => $properties, 'serverTime' => 200000];
    }

    public function test_snapshot_decodes_dp35_and_caches_model_without_writing_or_inventing_output_proof(): void
    {
        $client = $this->client();
        $client->expects($this->exactly(2))->method('readProperties')->willReturnOnConsecutiveCalls(
            $this->report(), $this->report([22 => 901, 23 => 197]));
        $client->expects($this->never())->method('sendCommands');
        $client->expects($this->never())->method('sendSwitchGradient');
        $driver = $this->driver($client);
        $snapshot = $driver->readSnapshot();
        $this->assertSame(['onMs' => 800, 'offMs' => 800], $snapshot['values'][35]);
        $this->assertSame(200000, $snapshot['receivedAt']);
        $this->assertSame(199000, $snapshot['times'][20]);
        $observation = $driver->readState(null, 200010);
        $this->assertSame('reported', $observation['quality']);
        $this->assertFalse($observation['outputSettled']);
        $this->assertFalse($observation['physicalConfirmed']);
        $this->assertSame(90.0, $observation['brightnessPct']);
        $this->assertSame(20.0, $observation['temperaturePct']);
        $this->assertArrayNotHasKey('values', $observation);
    }

    public function test_gradient_issues_once_after_read_only_preflight_and_returns_only_receipt(): void
    {
        $client = $this->client();
        $client->expects($this->once())->method('readProperties')->willReturn($this->report());
        $client->expects($this->once())->method('sendSwitchGradient')->with(12000, 2000)
            ->willReturn(['sentAt' => 200000, 'acceptedAt' => 200100]);
        $client->expects($this->never())->method('sendCommands');
        $driver = $this->driver($client);
        $driver->readSnapshot();
        $this->assertSame(['sentAt' => 200000, 'acceptedAt' => 200100], $driver->issue(['operation' => 'gradient', 'onMs' => 12000, 'offMs' => 2000]));
    }

    public function test_issue_without_model_preflight_performs_no_implicit_network_read_or_write(): void
    {
        $client = $this->createMock(TuyaCloudClient::class);
        $client->method('deviceId')->willReturn($this->deviceId);
        $client->expects($this->never())->method('readModel');
        $client->expects($this->never())->method('readProperties');
        $client->expects($this->never())->method('sendSwitchGradient');
        $this->expectExceptionMessage('configuration_error');
        $this->driver($client)->issue(['operation' => 'gradient', 'onMs' => 12000, 'offMs' => 2000]);
    }

    public function test_prepared_white_and_endpoint_white_use_exact_calibration_and_never_send_power(): void
    {
        $client = $this->client();
        $client->expects($this->exactly(2))->method('readProperties')->willReturnOnConsecutiveCalls(
            $this->report([20 => false, 21 => 'scene', 25 => $this->raw]), $this->report([22 => 1000, 23 => 332]));
        $expected = [['code' => 'bright_value_v2', 'value' => 901], ['code' => 'temp_value_v2', 'value' => 197],
            ['code' => 'work_mode', 'value' => 'white']];
        $client->expects($this->exactly(2))->method('sendCommands')->with($expected)->willReturn(['sentAt' => 200000]);
        $client->expects($this->never())->method('sendSwitchGradient');
        $driver = $this->driver($client);
        $driver->issue(['operation' => 'prepare_white', 'profile' => 'encounters', 'sourceSnapshot' => $driver->readSnapshot()]);
        $driver->issue(['operation' => 'endpoint_white', 'profile' => 'encounters', 'sourceSnapshot' => $driver->readSnapshot()]);
    }

    public function test_power_off_from_external_scene_does_not_infer_current_rgb_phase(): void
    {
        $client = $this->client();
        $client->expects($this->once())->method('readProperties')->willReturn($this->report([21 => 'scene', 25 => 'unknown_external_program']));
        $client->expects($this->once())->method('sendCommands')->with([['code' => 'switch_led', 'value' => false]])->willReturn(['sentAt' => 200000]);
        $driver = $this->driver($client);
        $driver->issue(['operation' => 'power', 'on' => false, 'sourceSnapshot' => $driver->readSnapshot()]);
    }

    public function test_power_on_requires_prepared_off_white_profile_and_is_not_a_combined_scene_restart(): void
    {
        $client = $this->client();
        $client->expects($this->once())->method('readProperties')->willReturn($this->report([20 => false, 22 => 1000, 23 => 332]));
        $client->expects($this->once())->method('sendCommands')->with([['code' => 'switch_led', 'value' => true]])->willReturn(['sentAt' => 200000]);
        $driver = $this->driver($client);
        $driver->issue(['operation' => 'power', 'on' => true, 'sourceSnapshot' => $driver->readSnapshot()]);
    }

    public function test_scene_write_preserves_explicit_captured_mapping_and_summary_does_not_claim_physical_phase(): void
    {
        $client = $this->client();
        $client->expects($this->exactly(2))->method('readProperties')->willReturnOnConsecutiveCalls(
            $this->report(), $this->report([21 => 'scene', 25 => $this->raw]));
        $client->expects($this->once())->method('sendCommands')->with([
            ['code' => 'scene_data_v2', 'value' => $this->scene], ['code' => 'work_mode', 'value' => 'scene'],
        ])->willReturn(['sentAt' => 200000]);
        $driver = $this->driver($client);
        $this->assertSame($this->raw, $driver->scenes()['blue']['raw']);
        $driver->issue(['operation' => 'scene', 'color' => 'blue', 'sourceSnapshot' => $driver->readSnapshot()]);
        $observed = $driver->observationFromSnapshot($driver->readSnapshot(), 200001);
        $this->assertSame('blue', $observed['color']);
        $this->assertSame(hash('sha256', $this->raw), $observed['sceneSha256']);
        $this->assertFalse($observed['physicalConfirmed']);
        $this->assertFalse($observed['outputSettled']);
        $this->assertStringNotContainsString($this->raw, json_encode($observed));
    }

    public function test_realtime_white_has_zero_rgb_and_compatibility_execute_only_reports_acceptance(): void
    {
        $client = $this->client();
        $client->expects($this->once())->method('readProperties')->willReturn($this->report());
        $client->expects($this->once())->method('sendCommands')->with([['code' => 'control_data', 'value' => [
            'change_mode' => 'gradient', 'h' => 0, 's' => 0, 'v' => 0, 'bright' => 901, 'temperature' => 197,
        ]]])->willReturn(['sentAt' => 200000]);
        $driver = $this->driver($client);
        $result = $driver->execute(['operation' => 'realtime_white', 'brightnessPct' => 90, 'temperaturePct' => 20], $driver->readSnapshot(), 200001);
        $this->assertSame(['sentAt' => 200000, 'acceptedAt' => null], $result['receipt']);
        $this->assertSame('commanded', $result['quality']);
        $this->assertFalse($result['outputSettled']);
        $this->assertFalse($result['physicalConfirmed']);
    }

    public static function unsafeSources(): array
    {
        return [
            'prepare while on' => [['operation' => 'prepare_white', 'profile' => 'dark'], []],
            'endpoint while off' => [['operation' => 'endpoint_white', 'profile' => 'action'], [20 => false]],
            'endpoint while RGB' => [['operation' => 'endpoint_white', 'profile' => 'action'], [21 => 'colour']],
            'on while scene off' => [['operation' => 'power', 'on' => true], [20 => false, 21 => 'scene', 25 => 'external']],
            'on at unverified raw profile' => [['operation' => 'power', 'on' => true], [20 => false, 22 => 900, 23 => 200]],
            'on already on' => [['operation' => 'power', 'on' => true], []],
            'off already off' => [['operation' => 'power', 'on' => false], [20 => false]],
            'scene while bright' => [['operation' => 'scene', 'color' => 'blue'], [22 => 1000, 23 => 332]],
            'scene while off' => [['operation' => 'scene', 'color' => 'blue'], [20 => false]],
            'realtime while RGB' => [['operation' => 'realtime_white', 'brightnessPct' => 1, 'temperaturePct' => 0], [21 => 'colour']],
        ];
    }

    #[DataProvider('unsafeSources')]
    public function test_wrong_sources_fail_before_any_post(array $command, array $values): void
    {
        $client = $this->client();
        $client->expects($this->once())->method('readProperties')->willReturn($this->report($values));
        $client->expects($this->never())->method('sendCommands');
        $client->expects($this->never())->method('sendSwitchGradient');
        $driver = $this->driver($client);
        $snapshot = $driver->readSnapshot();
        $this->expectExceptionMessage('unsupported_transition');
        $driver->issue($command + ['sourceSnapshot' => $snapshot]);
    }

    public function test_source_snapshot_too_old_cannot_authorize_a_new_on_command(): void
    {
        $client = $this->client();
        $client->expects($this->once())->method('readProperties')->willReturn($this->report([20 => false]));
        $client->expects($this->never())->method('sendCommands');
        $driver = $this->driver($client);
        $snapshot = $driver->readSnapshot();
        $this->clock += 5001;
        $this->expectExceptionMessage('unsupported_transition');
        $driver->issue(['operation' => 'power', 'on' => true, 'sourceSnapshot' => $snapshot]);
    }

    public function test_timed_out_write_is_not_retried_or_followed_by_a_poll(): void
    {
        $client = $this->client();
        $client->expects($this->once())->method('readProperties')->willReturn($this->report());
        $client->expects($this->once())->method('sendCommands')->willThrowException(new TuyaCloudException('transport_timeout', writeOutcomeUnknown: true));
        $driver = $this->driver($client);
        $snapshot = $driver->readSnapshot();
        try {
            $driver->issue(['operation' => 'power', 'on' => false, 'sourceSnapshot' => $snapshot]);
            $this->fail('Unknown command delivery must stop.');
        } catch (TuyaCloudException $error) {
            $this->assertSame('transport_timeout', $error->getMessage());
            $this->assertTrue($error->writeOutcomeUnknown);
            $this->assertNull($error->getPrevious());
        }
    }

    public function test_invalid_model_prevents_even_snapshot_read_and_all_writes(): void
    {
        $model = $this->model();
        $model['services'][0]['properties'][0]['accessMode'] = 'ro';
        $client = $this->client($model);
        $client->expects($this->never())->method('readProperties');
        $client->expects($this->never())->method('sendCommands');
        $this->expectExceptionMessage('configuration_error');
        $this->driver($client)->readSnapshot();
    }

    public function test_invalid_gradient_shadow_is_sanitized(): void
    {
        $client = $this->client();
        $client->expects($this->once())->method('readProperties')->willReturn($this->report([35 => 'fixture-private-payload']));
        try {
            $this->driver($client)->readSnapshot();
            $this->fail('Malformed raw must not be accepted.');
        } catch (TuyaCloudException $error) {
            $this->assertSame('configuration_error', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
    }

    public function test_mismatched_calibration_identity_fails_without_network(): void
    {
        $this->edit('calibration.json', static fn ($saved) => array_replace($saved, ['device_id' => 'other_fixture_device']));
        $client = $this->createMock(TuyaCloudClient::class);
        $client->method('deviceId')->willReturn($this->deviceId);
        $client->expects($this->never())->method('readModel');
        $client->expects($this->never())->method('readProperties');
        $client->expects($this->never())->method('sendCommands');
        $this->expectExceptionMessage('configuration_error');
        $this->driver($client)->readSnapshot();
    }

    public function test_corrupt_scene_pair_fails_before_read_or_write(): void
    {
        $this->edit('cloud-scenes.json', static function ($saved) {
            $saved['scenes']['blue']['value']['scene_units'][0]['h'] = 200;

            return $saved;
        });
        $client = $this->createMock(TuyaCloudClient::class);
        $client->method('deviceId')->willReturn($this->deviceId);
        $client->expects($this->never())->method('readModel');
        $client->expects($this->never())->method('sendCommands');
        $this->expectExceptionMessage('configuration_error');
        $this->driver($client)->scenes();
    }

    public function test_interruption_capability_is_opt_in_and_performs_no_io(): void
    {
        foreach ([[[], false], [['native_interruptions' => false], false], [['native_interruptions' => true], true]] as [$settings, $expected]) {
            $client = $this->createMock(TuyaCloudClient::class);
            foreach (['deviceId', 'readModel', 'readProperties', 'sendCommands', 'sendSwitchGradient'] as $method) {
                $client->expects($this->never())->method($method);
            }
            $driver = new NativeCloudLightingDriver($client, $settings);
            $this->assertSame($expected, $driver->capabilities()['nativeTransitionCancellation']);
        }
    }
}
