<?php

namespace Tests\Unit;

use App\Lighting\Drivers\CloudLightingFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CloudLightingCapturesTest extends TestCase
{
    private string $directory;

    private const DEVICE = 'capture_fixture_device';

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/eh-captures-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $limits = static fn (int $min, int $max): array => ['min' => $min, 'max' => $max, 'scale' => 0, 'step' => 1];
        $colour = ['h' => $limits(0, 360), 's' => $limits(0, 1000), 'v' => $limits(0, 1000)];
        $schema = [
            ['switch_led', 'Boolean', []], ['work_mode', 'Enum', ['range' => ['white', 'colour', 'scene']]],
            ['bright_value_v2', 'Integer', $limits(10, 1000)], ['temp_value_v2', 'Integer', $limits(0, 1000)],
            ['control_data', 'Json', ['change_mode' => ['range' => ['gradient']], 'h' => $limits(0, 360),
                's' => $limits(0, 255), 'v' => $limits(0, 255)]],
            ['colour_data_v2', 'Json', $colour], ['scene_data_v2', 'Json', ['scene_units' => $colour]],
        ];
        $this->save('cloud-functions-inspection.json', ['device_id' => self::DEVICE, 'response' => ['success' => true,
            'result' => ['functions' => array_map(static fn ($entry) => ['code' => $entry[0], 'type' => $entry[1],
                'values' => json_encode($entry[2], JSON_THROW_ON_ERROR)], $schema)]]]);
        $profiles = [];
        foreach (['dark' => [1, 0, 10, 0], 'action' => [100, 33, 1000, 332], 'encounters' => [90, 20, 901, 197]] as $name => $values) {
            $profiles[$name] = ['ui' => ['brightness' => $values[0], 'temperature' => $values[1]],
                'dps' => ['20' => true, '21' => 'white', '22' => $values[2], '23' => $values[3]],
                'user_confirmed' => true, 'replay_verified' => true];
        }
        $this->save('calibration.json', ['schema_version' => 1, 'device_id' => self::DEVICE, 'protocol' => '3.5',
            'white_profiles' => $profiles]);
        $configured = [
            'antarctica' => ['kind' => 'white', 'ui' => ['brightness' => 90, 'temperature' => 45]],
            'egypt' => ['kind' => 'white', 'ui' => ['brightness' => 90, 'temperature' => 25]],
            'future-1' => ['kind' => 'white', 'ui' => ['brightness' => 20, 'temperature' => 100]],
            'future-6' => ['kind' => 'white', 'ui' => ['brightness' => 15, 'temperature' => 15]],
            'action' => ['kind' => 'white', 'ui' => ['brightness' => 100, 'temperature' => 33]],
            'detained' => ['kind' => 'colour', 'ui' => ['hue' => 25, 'brightness' => 100, 'balance' => 85]],
        ];
        config(['lighting.presets' => $configured]);
        $captures = [];
        foreach (['antarctica' => [898, 451], 'egypt' => [900, 247], 'future-1' => [198, 1000],
            'future-6' => [153, 153], 'action' => [1000, 332]] as $alias => [$brightness, $temperature]) {
            $captures[$alias] = $configured[$alias] + ['dps' => [20 => true, 21 => 'white', 22 => $brightness, 23 => $temperature],
                'reported_times' => [20 => 1000, 21 => 1000, 22 => 1000, 23 => 1000]] + $this->metadata($alias);
        }
        $captures['action']['source'] = 'existing_calibration';
        $captures['action']['source_calibration'] = 'calibration.json';
        $captures['action']['replay_verified'] = true;
        $captures['action']['integrated'] = true;
        unset($captures['action']['reported_times']);
        $captures['detained'] = $configured['detained'] + [
            'dps' => [20 => true, 21 => 'colour', 24 => '0019035203e8'], 'hsv' => ['h' => 25, 's' => 850, 'v' => 1000],
            'reported_times' => [20 => 1000, 21 => 1000, 24 => 1000],
        ] + $this->metadata('detained');
        $this->save('preset-captures.json', ['schema_version' => 1, 'device_id' => self::DEVICE, 'presets' => $captures]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_captured_targets_preserve_exact_raw_values_and_unverified_metadata(): void
    {
        $before = hash_file('sha256', $this->directory.'/preset-captures.json');
        $files = new CloudLightingFiles($this->directory, self::DEVICE);

        $this->assertSame(['brightnessPct' => 90.0, 'temperaturePct' => 45.0, 'brightness' => 898, 'temperature' => 451], $files->profiles['antarctica']);
        $this->assertSame(900, $files->profiles['egypt']['brightness']);
        $this->assertSame(['h' => 25, 's' => 850, 'v' => 1000], $files->colours['detained']);
        $this->assertSame(['brightnessPct' => 20.0, 'temperaturePct' => 100.0, 'brightness' => 198, 'temperature' => 1000], $files->profiles['future-1']);
        $this->assertSame(['brightnessPct' => 15.0, 'temperaturePct' => 15.0, 'brightness' => 153, 'temperature' => 153], $files->profiles['future-6']);
        $this->assertArrayNotHasKey('future-1', $files->colours);
        $this->assertArrayNotHasKey('future-6', $files->colours);
        $this->assertSame($before, hash_file('sha256', $this->directory.'/preset-captures.json'));
    }

    public function test_duplicate_percentages_do_not_change_calibrated_interpolation(): void
    {
        $files = new CloudLightingFiles($this->directory, self::DEVICE);
        $this->assertSame(['brightness' => 10, 'temperature' => 0], $files->white(1, 0));
        $this->assertSame(['brightness' => 901, 'temperature' => 197], $files->white(90, 20));
        $this->assertSame(['brightness' => 1000, 'temperature' => 332], $files->white(100, 33));
        $this->assertSame(['brightnessPct' => 90.0, 'temperaturePct' => 20.0], $files->whitePercentages(901, 197));
        $previous = 0;
        for ($percent = 1; $percent <= 100; $percent++) {
            $raw = $files->white($percent, $percent);
            $this->assertGreaterThan($previous, $raw['brightness']);
            $previous = $raw['brightness'];
        }
    }

    public function test_missing_capture_file_keeps_legacy_configuration_usable(): void
    {
        unlink($this->directory.'/preset-captures.json');
        $files = new CloudLightingFiles($this->directory, self::DEVICE);
        $this->assertSame(['dark', 'action', 'encounters'], array_keys($files->profiles));
        $this->assertSame([], $files->colours);
    }

    public function test_scene_schema_supplies_colour_ranges_for_older_inspections(): void
    {
        $saved = $this->read('cloud-functions-inspection.json');
        $saved['response']['result']['functions'] = array_values(array_filter($saved['response']['result']['functions'],
            static fn ($entry) => $entry['code'] !== 'colour_data_v2'));
        $this->save('cloud-functions-inspection.json', $saved);

        $this->assertSame(['h' => 25, 's' => 850, 'v' => 1000], (new CloudLightingFiles($this->directory, self::DEVICE))->colours['detained']);
    }

    #[DataProvider('invalidCaptures')]
    public function test_invalid_capture_is_rejected(string $change): void
    {
        $saved = $this->read('preset-captures.json');
        switch ($change) {
            case 'device': $saved['device_id'] = 'another_device';
                break;
            case 'version': $saved['schema_version'] = 2;
                break;
            case 'unknown alias': $saved['presets']['unknown'] = $saved['presets']['detained'];
                break;
            case 'scalar capture': $saved['presets']['detained'] = 'invalid';
                break;
            case 'null capture': $saved['presets']['detained'] = null;
                break;
            case 'kind': $saved['presets']['detained']['kind'] = 'scene';
                break;
            case 'mode': $saved['presets']['detained']['dps'][21] = 'white';
                break;
            case 'off': $saved['presets']['detained']['dps'][20] = false;
                break;
            case 'UI value': $saved['presets']['detained']['ui']['brightness'] = 99;
                break;
            case 'UI type': $saved['presets']['detained']['ui']['brightness'] = '100';
                break;
            case 'UI extra field': $saved['presets']['detained']['ui']['temperature'] = 30;
                break;
            case 'scalar UI': $saved['presets']['detained']['ui'] = 'invalid';
                break;
            case 'scalar DPS': $saved['presets']['detained']['dps'] = 'invalid';
                break;
            case 'raw format': $saved['presets']['detained']['dps'][24] = '0019035203e8ff';
                break;
            case 'raw hue': $saved['presets']['detained']['dps'][24] = 'ffff035203e8';
                break;
            case 'raw saturation': $saved['presets']['detained']['dps'][24] = '001903e903e8';
                break;
            case 'raw brightness': $saved['presets']['detained']['dps'][24] = '0019035203e9';
                break;
            case 'raw dark colour':
                $saved['presets']['detained']['dps'][24] = '001903520009';
                $saved['presets']['detained']['hsv']['v'] = 9;
                break;
            case 'HSV mismatch': $saved['presets']['detained']['hsv']['s'] = 849;
                break;
            case 'white range': $saved['presets']['antarctica']['dps'][22] = 9;
                break;
            case 'white temperature range': $saved['presets']['antarctica']['dps'][23] = 1001;
                break;
            case 'white extra field': $saved['presets']['antarctica']['dps'][24] = '0019035203e8';
                break;
            case 'legacy override': $saved['presets']['action']['dps'][23] = 333;
                break;
            case 'unconfirmed': $saved['presets']['detained']['user_confirmed'] = false;
                break;
            case 'verification type': $saved['presets']['detained']['replay_verified'] = 'false';
                break;
            case 'integration type': $saved['presets']['detained']['integrated'] = null;
                break;
            case 'source': $saved['presets']['detained']['source'] = 'guessed';
                break;
            case 'fake calibration': $saved['presets']['detained']['source'] = 'existing_calibration';
                break;
            case 'snapshot path': $saved['presets']['detained']['source_snapshot'] = '../capture.json';
                break;
            case 'capture date': $saved['presets']['detained']['captured_at'] = 'tomorrow';
                break;
            case 'missing report time': unset($saved['presets']['detained']['reported_times'][24]);
                break;
            case 'future report time': $saved['presets']['detained']['reported_times'][24] = PHP_INT_MAX;
                break;
            case 'negative report time': $saved['presets']['detained']['reported_times'][24] = -1;
                break;
        }
        $this->save('preset-captures.json', $saved);
        $this->expectExceptionMessage('configuration_error');
        new CloudLightingFiles($this->directory, self::DEVICE);
    }

    public static function invalidCaptures(): array
    {
        return array_map(static fn ($change) => [$change], ['device', 'version', 'unknown alias', 'scalar capture', 'null capture', 'kind', 'mode', 'off',
            'UI value', 'UI type', 'UI extra field', 'scalar UI', 'scalar DPS', 'raw format', 'raw hue', 'raw saturation', 'raw brightness', 'raw dark colour',
            'HSV mismatch', 'white range', 'white temperature range', 'white extra field', 'legacy override',
            'unconfirmed', 'verification type', 'integration type', 'source', 'fake calibration', 'snapshot path',
            'capture date', 'missing report time', 'future report time', 'negative report time']);
    }

    private function metadata(string $alias): array
    {
        return ['source' => 'cloud_properties', 'source_snapshot' => 'capture-'.$alias.'.json',
            'captured_at' => '2026-09-27T00:00:00+00:00', 'user_confirmed' => true, 'replay_verified' => false, 'integrated' => false];
    }

    private function read(string $name): array
    {
        return json_decode(file_get_contents($this->directory.'/'.$name), true, flags: JSON_THROW_ON_ERROR);
    }

    private function save(string $name, array $value): void
    {
        file_put_contents($this->directory.'/'.$name, json_encode($value, JSON_THROW_ON_ERROR));
        chmod($this->directory.'/'.$name, 0600);
    }
}
