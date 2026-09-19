<?php

namespace Tests\Unit;

use App\Lighting\Drivers\CloudLightingDriver;
use App\Lighting\Drivers\CloudLightingFiles;
use App\Lighting\Drivers\TuyaCloudClient;
use App\Lighting\Drivers\TuyaCloudException;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CloudLightingDriverTest extends TestCase
{
    private string $directory;

    private string $deviceId = 'fixture_device_123';

    private string $raw;

    private array $scene;

    private float $clock = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/eh-cloud-test-'.bin2hex(random_bytes(8));
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

    private function client(): TuyaCloudClient
    {
        $client = $this->createMock(TuyaCloudClient::class);
        $client->method('deviceId')->willReturn($this->deviceId);
        $client->expects($this->never())->method('readFunctions');
        $client->expects($this->never())->method('readModel');

        return $client;
    }

    private function driver(TuyaCloudClient $client): CloudLightingDriver
    {
        return new CloudLightingDriver($client, ['private_directory' => $this->directory, 'confirmation_timeout_ms' => 1000,
            'poll_interval_ms' => 500], fn () => $this->clock, function (int $ms): void {
                $this->clock += $ms / 1000;
            });
    }

    private function report(array $dps = [], int $time = 100001): array
    {
        $dps += [20 => true, 21 => 'white', 22 => 10, 23 => 0];
        $properties = [];
        foreach ($dps as $id => $value) {
            $properties[] = ['dp_id' => $id, 'code' => 'native_unrelated_name', 'value' => $value, 'time' => $time];
        }

        return ['properties' => $properties, 'serverTime' => 200000];
    }

    private function white(bool $settled = false, float $brightness = 1, float $temperature = 0): array
    {
        return ['mode' => 'white', 'brightnessPct' => $brightness, 'temperaturePct' => $temperature,
            'rgbSuppressed' => true, 'quality' => $settled ? 'confirmed' : 'reported', 'outputSettled' => $settled,
            'completion' => $settled ? 'cloud_readback' : 'cloud_reported'];
    }

    public function test_read_normalizes_calibrated_values_but_never_promotes_initial_shadow_to_darkness_proof(): void
    {
        $client = $this->client();
        $client->method('isOnline')->willReturn(true);
        $client->expects($this->exactly(2))->method('readProperties')->willReturnOnConsecutiveCalls(
            $this->report(), $this->report([22 => 901, 23 => 197]));
        $client->expects($this->never())->method('sendCommands');
        $driver = $this->driver($client);
        $dark = $driver->readState(null, 20);
        $this->assertSame('reported', $dark['quality']);
        $this->assertFalse($dark['outputSettled']);
        $this->assertSame(1.0, $dark['brightnessPct']);
        $changed = $driver->readState($dark, 30);
        $this->assertSame(90.0, $changed['brightnessPct']);
        $this->assertSame(20.0, $changed['temperaturePct']);
        $this->assertFalse($changed['physicalConfirmed']);
    }

    public function test_intermediate_white_is_one_gradient_command_and_old_shadow_cannot_erase_the_commanded_origin(): void
    {
        $client = $this->client();
        $client->expects($this->once())->method('sendCommands')->with([['code' => 'control_data', 'value' => [
            'change_mode' => 'gradient', 'h' => 0, 's' => 0, 'v' => 0, 'bright' => 901, 'temperature' => 197]]])
            ->willReturn(['sentAt' => 100000, 'acceptedAt' => 100001]);
        $client->method('isOnline')->willReturn(true);
        $client->expects($this->exactly(2))->method('readProperties')->willReturn($this->report());
        $driver = $this->driver($client);
        $commanded = $driver->execute(['operation' => 'white', 'brightnessPct' => 90, 'temperaturePct' => 20, 'commit' => false], $this->white(), 10);
        $this->assertSame('commanded', $commanded['quality']);
        $this->assertFalse($commanded['outputSettled']);
        $this->assertSame(90.0, $driver->readState($commanded, 20)['brightnessPct']);
        $commanded['quality'] = 'unknown';
        $commanded['completion'] = 'late_acknowledgement';
        $this->assertSame(90.0, $driver->readState($commanded, 30)['brightnessPct']);
    }

    public function test_terminal_white_requires_matching_fresh_device_properties_after_one_exact_calibrated_write(): void
    {
        $client = $this->client();
        $client->expects($this->once())->method('sendCommands')->with([
            ['code' => 'switch_led', 'value' => true], ['code' => 'bright_value_v2', 'value' => 901],
            ['code' => 'temp_value_v2', 'value' => 197], ['code' => 'work_mode', 'value' => 'white'],
        ])->willReturn(['sentAt' => 100000]);
        $client->expects($this->exactly(2))->method('readProperties')->willReturnOnConsecutiveCalls(
            $this->report([22 => 901, 23 => 197], 99999), $this->report([22 => 901, 23 => 197]));
        $observed = $this->driver($client)->execute(['operation' => 'white', 'brightnessPct' => 90, 'temperaturePct' => 20,
            'commit' => true], $this->white(), 50);
        $this->assertSame('cloud_readback', $observed['completion']);
        $this->assertTrue($observed['outputSettled']);
        $this->assertSame(90.0, $observed['brightnessPct']);
        $this->assertSame(20.0, $observed['temperaturePct']);
        $this->assertFalse($observed['physicalConfirmed']);
    }

    public function test_unchanged_old_dark_shadow_times_out_without_resending(): void
    {
        $client = $this->client();
        $client->expects($this->once())->method('sendCommands')->willReturn(['sentAt' => 100000]);
        $client->expects($this->exactly(2))->method('readProperties')->willReturn($this->report(time: 99999));
        try {
            $this->driver($client)->execute(['operation' => 'dark_anchor'], $this->white(), 50);
            $this->fail('Old shadow must not confirm output after transient DP28.');
        } catch (TuyaCloudException $exception) {
            $this->assertSame('transport_timeout', $exception->getMessage());
            $this->assertTrue($exception->writeOutcomeUnknown);
        }
    }

    public function test_api_timeout_is_not_followed_by_another_write_or_a_claimed_completion(): void
    {
        $client = $this->client();
        $client->expects($this->once())->method('sendCommands')->willThrowException(new TuyaCloudException('transport_timeout', writeOutcomeUnknown: true));
        $client->expects($this->never())->method('readProperties');
        $this->expectExceptionMessage('transport_timeout');
        $this->driver($client)->execute(['operation' => 'dark_anchor'], $this->white(), 50);
    }

    public function test_scene_uses_explicit_cloud_mapping_and_complete_exact_raw_shadow_confirmation(): void
    {
        $client = $this->client();
        $client->expects($this->once())->method('sendCommands')->with([
            ['code' => 'scene_data_v2', 'value' => $this->scene], ['code' => 'work_mode', 'value' => 'scene'],
        ])->willReturn(['sentAt' => 100000]);
        $client->expects($this->once())->method('readProperties')->willReturn($this->report([21 => 'scene', 25 => $this->raw]));
        $observed = $this->driver($client)->execute(['operation' => 'scene', 'color' => 'blue', 'mythosSessionId' => 'session-1'], $this->white(true), 50);
        $this->assertSame('blue', $observed['color']);
        $this->assertSame('session-1', $observed['mythosSessionId']);
        $this->assertSame('confirmed', $observed['quality']);
        $this->assertFalse($observed['physicalConfirmed']);
        $this->assertArrayNotHasKey('raw', $observed);
    }

    #[DataProvider('unsafeTransitions')]
    public function test_unverified_darkness_and_rgb_exits_never_write(array $command, array $observation): void
    {
        $client = $this->client();
        $client->expects($this->never())->method('sendCommands');
        $this->expectExceptionMessage('unsupported_transition');
        $this->driver($client)->execute($command, $observation, 50);
    }

    public static function unsafeTransitions(): array
    {
        return [
            'reported dark is insufficient' => [['operation' => 'scene', 'color' => 'blue', 'mythosSessionId' => 'session-1'],
                ['mode' => 'white', 'brightnessPct' => 1, 'temperaturePct' => 0, 'quality' => 'reported', 'outputSettled' => false, 'rgbSuppressed' => true]],
            'scene exit' => [['operation' => 'dark_anchor'], ['mode' => 'scene', 'rgbSuppressed' => false]],
            'off' => [['operation' => 'white', 'brightnessPct' => 100, 'temperaturePct' => 33, 'commit' => true], ['mode' => 'off']],
            'scene dim' => [['operation' => 'scene_dim', 'level' => 0.1], ['mode' => 'scene']],
        ];
    }

    #[DataProvider('invalidConfiguration')]
    public function test_bad_private_configuration_fails_before_any_command(string $change): void
    {
        match ($change) {
            'device' => $this->edit('calibration.json', static fn ($saved) => array_replace($saved, ['device_id' => 'wrong_device'])),
            'checksum' => $this->edit('scene-presets.json', static function ($saved) {
                $saved['presets']['blue']['sha256'] = str_repeat('a', 64);

                return $saved;
            }),
            'unconfirmed' => $this->edit('scene-presets.json', static function ($saved) {
                $saved['presets']['blue']['user_confirmed'] = false;

                return $saved;
            }),
            'unverified white' => $this->edit('calibration.json', static function ($saved) {
                $saved['white_profiles']['dark']['replay_verified'] = false;

                return $saved;
            }),
            'changed paired unit' => $this->edit('cloud-scenes.json', static function ($saved) {
                $saved['scenes']['blue']['value']['scene_units'][0]['h'] = 200;

                return $saved;
            }),
            'invalid range' => $this->edit('cloud-scenes.json', static function ($saved) {
                $saved['scenes']['blue']['value']['scene_num'] = 9;

                return $saved;
            }),
            'permissions' => chmod($this->directory.'/calibration.json', 0644),
        };
        $client = $this->client();
        $client->expects($this->never())->method('sendCommands');
        $this->expectExceptionMessage('configuration_error');
        $this->driver($client)->execute(['operation' => 'dark_anchor'], $this->white(), 10);
    }

    public static function invalidConfiguration(): array
    {
        return array_map(static fn ($value) => [$value], ['device', 'checksum', 'unconfirmed', 'unverified white', 'changed paired unit', 'invalid range', 'permissions']);
    }

    public function test_unmapped_alias_is_rejected_without_guessing_scene_number(): void
    {
        $client = $this->client();
        $client->expects($this->never())->method('sendCommands');
        $this->expectExceptionMessage('preset_not_configured');
        $this->driver($client)->execute(['operation' => 'scene', 'color' => 'green', 'mythosSessionId' => 'session-1'], $this->white(true), 10);
    }

    public function test_calibration_interpolation_has_exact_endpoints_and_monotonic_interior(): void
    {
        $files = new CloudLightingFiles($this->directory, $this->deviceId);
        $this->assertSame(['brightness' => 10, 'temperature' => 0], $files->white(1, 0));
        $this->assertSame(['brightness' => 901, 'temperature' => 197], $files->white(90, 20));
        $this->assertSame(['brightness' => 1000, 'temperature' => 332], $files->white(100, 33));
        $previous = 0;
        for ($percent = 1; $percent <= 100; $percent++) {
            $value = $files->white($percent, 0)['brightness'];
            $this->assertGreaterThan($previous, $value);
            $previous = $value;
        }
    }

    private function capturedFunctions(): array
    {
        return json_decode(file_get_contents($this->directory.'/cloud-functions-inspection.json'), true, flags: JSON_THROW_ON_ERROR)['response']['result']['functions'];
    }

    private function reboundModel(): array
    {
        $range = static fn (int $min): array => ['type' => 'value', 'min' => $min, 'max' => 1000, 'step' => 1, 'scale' => 0];
        $properties = [];
        foreach ([20 => ['switch_led', ['type' => 'bool']],
            21 => ['work_mode', ['type' => 'enum', 'range' => ['white', 'colour', 'scene', 'music']]],
            22 => ['bright_value', $range(10)], 23 => ['temp_value', $range(0)],
            24 => ['colour_data', ['type' => 'string', 'maxlen' => 255]],
            25 => ['scene_data', ['type' => 'string', 'maxlen' => 255]],
            28 => ['control_data', ['type' => 'string', 'maxlen' => 255]],
            35 => ['switch_gradient', ['type' => 'raw', 'maxlen' => 128]]] as $id => [$code, $spec]) {
            $properties[] = ['abilityId' => $id, 'code' => $code, 'accessMode' => $id === 28 ? 'wr' : 'rw', 'typeSpec' => $spec];
        }

        return ['services' => [['code' => '', 'properties' => $properties]]];
    }

    public function test_new_device_id_reuses_exact_profiles_and_scenes_without_rewriting_the_capture(): void
    {
        $original = new CloudLightingFiles($this->directory, $this->deviceId);
        $bytes = array_map('file_get_contents', glob($this->directory.'/*.json'));
        $client = $this->createMock(TuyaCloudClient::class);
        $client->method('deviceId')->willReturn('rebound_device_456');
        $functions = array_reverse($this->capturedFunctions());
        foreach ($functions as &$function) {
            $values = json_decode($function['values'], true, flags: JSON_THROW_ON_ERROR);
            if (isset($values['range'])) {
                $values['range'] = array_reverse($values['range']);
            }
            $function['values'] = array_reverse($values, true);
        }
        unset($function);
        $client->expects($this->once())->method('readFunctions')->willReturn($functions);
        $client->expects($this->once())->method('readModel')->willReturn($this->reboundModel());
        $client->expects($this->never())->method('sendCommands');
        $files = new CloudLightingFiles($this->directory, 'rebound_device_456', $client);
        $this->assertSame($this->deviceId, $files->sourceDeviceId);
        $this->assertSame($this->deviceId, CloudLightingFiles::capturedDeviceId($this->directory));
        $this->assertSame($original->profiles, $files->profiles);
        $this->assertSame($original->scenes, $files->scenes);
        $this->assertSame($original->schema, $files->schema);
        $this->assertSame($bytes, array_map('file_get_contents', glob($this->directory.'/*.json')));
    }

    public function test_new_id_without_live_client_remains_strictly_rejected(): void
    {
        $this->expectExceptionMessage('configuration_error');
        new CloudLightingFiles($this->directory, 'rebound_device_456');
    }

    public function test_rebound_cloud_driver_preflights_once_and_keeps_the_exact_white_payload(): void
    {
        $client = $this->createMock(TuyaCloudClient::class);
        $client->method('deviceId')->willReturn('rebound_device_456');
        $client->expects($this->once())->method('readFunctions')->willReturn($this->capturedFunctions());
        $client->expects($this->once())->method('readModel')->willReturn($this->reboundModel());
        $client->expects($this->exactly(2))->method('sendCommands')->with([
            ['code' => 'switch_led', 'value' => true], ['code' => 'bright_value_v2', 'value' => 901],
            ['code' => 'temp_value_v2', 'value' => 197], ['code' => 'work_mode', 'value' => 'white'],
        ])->willReturn(['sentAt' => 100000]);
        $client->expects($this->exactly(2))->method('readProperties')->willReturn($this->report([22 => 901, 23 => 197]));
        $driver = $this->driver($client);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $result = $driver->execute(['operation' => 'white', 'brightnessPct' => 90, 'temperaturePct' => 20,
                'commit' => true], $this->white(), 50);
            $this->assertSame('cloud_readback', $result['completion']);
        }
    }

    #[DataProvider('incompatibleRebound')]
    public function test_new_id_with_incompatible_schema_or_native_map_never_writes(string $failure): void
    {
        $functions = $this->capturedFunctions();
        $model = $this->reboundModel();
        if ($failure === 'range') {
            $functions[2]['values'] = json_encode(['min' => 10, 'max' => 255, 'scale' => 0, 'step' => 1], JSON_THROW_ON_ERROR);
        } elseif ($failure === 'type') {
            $functions[2]['type'] = 'String';
        } elseif ($failure === 'missing') {
            array_pop($functions);
        } elseif ($failure === 'duplicate') {
            $functions[] = $functions[2];
        } elseif ($failure === 'native dp') {
            $model['services'][0]['properties'][2]['abilityId'] = 122;
        } elseif ($failure === 'native range') {
            $model['services'][0]['properties'][2]['typeSpec']['max'] = 255;
        } elseif ($failure === 'native access') {
            $model['services'][0]['properties'][6]['accessMode'] = 'ro';
        } elseif ($failure === 'native length') {
            $model['services'][0]['properties'][5]['typeSpec']['maxlen'] = 10;
        }
        $client = $this->createMock(TuyaCloudClient::class);
        $client->method('deviceId')->willReturn('rebound_device_456');
        $client->expects($this->once())->method('readFunctions')->willReturn($functions);
        $client->expects(str_starts_with($failure, 'native') ? $this->once() : $this->never())
            ->method('readModel')->willReturn($model);
        $client->expects($this->never())->method('readProperties');
        $client->expects($this->never())->method('sendCommands');
        $this->expectExceptionMessage('configuration_error');
        $this->driver($client)->execute(['operation' => 'dark_anchor'], $this->white(), 10);
    }

    public static function incompatibleRebound(): array
    {
        return array_map(static fn (string $failure): array => [$failure], [
            'range', 'type', 'missing', 'duplicate', 'native dp', 'native range', 'native access', 'native length',
        ]);
    }

    public function test_mixed_capture_ids_fail_before_rebinding_network_checks(): void
    {
        $this->edit('scene-presets.json', static fn (array $saved): array => array_replace($saved, ['device_id' => 'unrelated_capture']));
        $client = $this->createMock(TuyaCloudClient::class);
        $client->method('deviceId')->willReturn('rebound_device_456');
        $client->expects($this->never())->method('readFunctions');
        $client->expects($this->never())->method('readModel');
        $client->expects($this->never())->method('sendCommands');
        $this->expectExceptionMessage('configuration_error');
        $this->driver($client)->execute(['operation' => 'dark_anchor'], $this->white(), 10);
    }
}
