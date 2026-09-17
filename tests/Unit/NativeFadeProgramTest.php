<?php

namespace Tests\Unit;

use App\Lighting\Drivers\TuyaCloudException;
use App\Lighting\NativeFadeProgram;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NativeFadeProgramTest extends TestCase
{
    private function scenes(string $header = '07', int $number = 8): array
    {
        $raw = $header.'37370200C703E803E800000000';

        return ['blue' => ['raw' => $raw, 'source_sha256' => hash('sha256', $raw), 'value' => [
            'scene_num' => $number, 'scene_units' => [['unit_switch_duration' => 55, 'unit_gradient_duration' => 55,
                'unit_change_mode' => 'gradient', 'h' => 199, 's' => 1000, 'v' => 1000, 'bright' => 0, 'temperature' => 0]],
        ]]];
    }

    private function dark(): array
    {
        return ['h' => 0, 's' => 0, 'v' => 0, 'bright' => 10, 'temperature' => 0];
    }

    public function test_dark_is_exactly_two_identical_nonzero_targets_without_bright_source_or_return(): void
    {
        $captured = $this->scenes();
        $before = $captured;
        $program = NativeFadeProgram::build($this->dark(), $captured, 30);
        $this->assertSame(['value', 'raw'], array_keys($program));
        $this->assertSame('07'.str_repeat('1e1e02000000000000000a0000', 2), $program['raw']);
        $this->assertSame(54, strlen($program['raw']));
        $this->assertSame(8, $program['value']['scene_num']);
        $units = $program['value']['scene_units'];
        $this->assertCount(2, $units);
        $this->assertSame($units[0], $units[1]);
        $this->assertSame(['unit_switch_duration' => 30, 'unit_gradient_duration' => 30, 'unit_change_mode' => 'gradient']
            + $this->dark(), $units[0]);
        $this->assertSame($before, $captured);
    }

    public function test_native_rgb_preserves_full_1000_range_and_explicit_captured_pair_without_id_arithmetic(): void
    {
        $channels = ['h' => 199, 's' => 1000, 'v' => 1000, 'bright' => 0, 'temperature' => 0];
        // The generator consumes a previously verified opaque mapping. It must
        // not invent scene_num by adding one to the native header.
        $program = NativeFadeProgram::build($channels, $this->scenes('0A', 4), 1);
        $this->assertSame('0A'.str_repeat('01010200c703e803e800000000', 2), $program['raw']);
        $this->assertSame(4, $program['value']['scene_num']);
        foreach ($channels as $field => $value) {
            $this->assertSame($value, $program['value']['scene_units'][0][$field]);
        }
    }

    public function test_mixed_minimum_and_maximum_raw_timing_are_allowed_without_duration_conversion(): void
    {
        $program = NativeFadeProgram::build(['h' => 360, 's' => 1000, 'v' => 10, 'bright' => 10, 'temperature' => 1000], $this->scenes(), 100);
        $unit = $program['value']['scene_units'][0];
        $this->assertSame(100, $unit['unit_switch_duration']);
        $this->assertSame(100, $unit['unit_gradient_duration']);
        $this->assertSame('646402016803e8000a000a03e8', substr($program['raw'], 2, 26));
    }

    #[DataProvider('invalidTargets')]
    public function test_invalid_or_physically_zero_channels_fail_closed(array $replace): void
    {
        $this->expectException(TuyaCloudException::class);
        $this->expectExceptionMessage('configuration_error');
        NativeFadeProgram::build(array_replace($this->dark(), $replace), $this->scenes(), 30);
    }

    public static function invalidTargets(): array
    {
        return [
            'all off' => [['v' => 0, 'bright' => 0]],
            'both below lamp minimum' => [['v' => 5, 'bright' => 5]],
            'rgb below minimum despite white' => [['v' => 9]],
            'white below minimum despite rgb' => [['v' => 1000, 'bright' => 1]],
            'negative' => [['s' => -1]],
            'hue out of range' => [['h' => 361]],
            'rgb out of range' => [['v' => 1001]],
            'temperature out of range' => [['temperature' => 1001]],
            'float' => [['bright' => 10.0]],
            'numeric string' => [['h' => '0']],
            'boolean' => [['s' => false]],
            'unexpected power' => [['switch_led' => true]],
        ];
    }

    #[DataProvider('invalidTiming')]
    public function test_timing_byte_rejects_coercion_and_values_outside_raw_range(mixed $byte): void
    {
        $this->expectException(TuyaCloudException::class);
        NativeFadeProgram::build($this->dark(), $this->scenes(), $byte);
    }

    public static function invalidTiming(): array
    {
        return [[0], [101], [-1], [30.0], ['30'], [true], [null]];
    }

    public function test_missing_scene_mapping_is_not_guessed(): void
    {
        $this->expectException(TuyaCloudException::class);
        $this->expectExceptionMessage('preset_not_configured');
        NativeFadeProgram::build($this->dark(), [], 30);
    }

    public function test_tampered_captured_checksum_is_rejected(): void
    {
        $scenes = $this->scenes();
        $scenes['blue']['source_sha256'] = str_repeat('0', 64);
        $this->expectException(TuyaCloudException::class);
        NativeFadeProgram::build($this->dark(), $scenes, 30);
    }

    public function test_cloud_units_must_match_captured_raw_even_with_valid_raw_checksum(): void
    {
        $scenes = $this->scenes();
        $scenes['blue']['value']['scene_units'][0]['h'] = 198;
        $this->expectException(TuyaCloudException::class);
        NativeFadeProgram::build($this->dark(), $scenes, 30);
    }

    public function test_truncated_or_oversized_source_program_is_rejected(): void
    {
        foreach (['07373702', '07'.str_repeat('37370200c703e803e800000000', 9)] as $raw) {
            $scenes = $this->scenes();
            $scenes['blue']['raw'] = $raw;
            $scenes['blue']['source_sha256'] = hash('sha256', $raw);
            try {
                NativeFadeProgram::build($this->dark(), $scenes, 30);
                $this->fail('Malformed source program was accepted.');
            } catch (TuyaCloudException $error) {
                $this->assertSame('configuration_error', $error->getMessage());
            }
        }
    }

    public function test_scene_selection_is_stable_across_manifest_order(): void
    {
        $blue = $this->scenes();
        $green = ['green' => $this->scenes('02', 3)['blue']];
        $this->assertSame(NativeFadeProgram::build($this->dark(), $blue + $green, 30),
            NativeFadeProgram::build($this->dark(), $green + $blue, 30));
    }
}
