<?php

namespace App\Lighting;

use App\Lighting\Drivers\TuyaCloudException;

/** Pure target-only DP25 encoding; timing bytes do not promise physical duration. */
final class NativeFadeProgram
{
    private const CHANNELS = ['h' => 360, 's' => 1000, 'v' => 1000, 'bright' => 1000, 'temperature' => 1000];

    /**
     * $capturedScenes must come from CloudLightingFiles/driver->scenes(), whose
     * explicit, verified LAN/cloud pairing is retained without an ID formula.
     * This helper has no file, network, configuration, or clock access.
     *
     * @return array{value: array, raw: string}
     */
    public static function build(array $targetChannels, array $capturedScenes, mixed $timingByte): array
    {
        if (! is_int($timingByte) || $timingByte < 1 || $timingByte > 100
            || count($targetChannels) !== count(self::CHANNELS)
            || array_diff_key($targetChannels, self::CHANNELS) !== []) {
            self::fail();
        }
        foreach (self::CHANNELS as $field => $maximum) {
            $value = $targetChannels[$field] ?? null;
            if (! is_int($value) || $value < 0 || $value > $maximum) {
                self::fail();
            }
        }
        // The lamp's reference conversion clamps each nonzero channel below
        // ten to off. Never construct a nominally nonzero 5/5 dark target.
        foreach (['v', 'bright'] as $field) {
            if ($targetChannels[$field] > 0 && $targetChannels[$field] < 10) {
                self::fail();
            }
        }
        if ($targetChannels['v'] === 0 && $targetChannels['bright'] === 0) {
            self::fail();
        }
        if ($capturedScenes === []) {
            throw new TuyaCloudException('preset_not_configured');
        }
        // Stable selection makes the same input independent of manifest order.
        ksort($capturedScenes);
        $scene = reset($capturedScenes);
        [$header, $number] = self::pair($scene);
        $unit = ['unit_switch_duration' => $timingByte, 'unit_gradient_duration' => $timingByte,
            'unit_change_mode' => 'gradient'];
        foreach (self::CHANNELS as $field => $maximum) {
            $unit[$field] = $targetChannels[$field];
        }
        $rawUnit = self::encodeUnit($unit);

        return ['value' => ['scene_num' => $number, 'scene_units' => [$unit, $unit]],
            'raw' => $header.$rawUnit.$rawUnit];
    }

    /** Validate that the supplied captured JSON units actually encode its raw. */
    private static function pair(mixed $scene): array
    {
        if (! is_array($scene)) {
            self::fail();
        }
        $raw = $scene['raw'] ?? null;
        $value = $scene['value'] ?? null;
        if (! is_string($raw) || ! preg_match('/\A[0-9a-fA-F]{28,210}\z/D', $raw)
            || (strlen($raw) - 2) % 26 !== 0 || ($scene['source_sha256'] ?? null) !== hash('sha256', $raw)
            || ! is_array($value) || count($value) !== 2
            || ! is_int($value['scene_num'] ?? null) || $value['scene_num'] < 1 || $value['scene_num'] > 8
            || ! is_array($value['scene_units'] ?? null) || ! array_is_list($value['scene_units'])
            || count($value['scene_units']) !== intdiv(strlen($raw) - 2, 26)) {
            self::fail();
        }
        foreach ($value['scene_units'] as $index => $unit) {
            if (! is_array($unit) || count($unit) !== 8) {
                self::fail();
            }
            foreach (['unit_switch_duration' => 100, 'unit_gradient_duration' => 100] + self::CHANNELS as $field => $maximum) {
                if (! is_int($unit[$field] ?? null) || $unit[$field] < 0 || $unit[$field] > $maximum) {
                    self::fail();
                }
            }
            if (! in_array($unit['unit_change_mode'] ?? null, ['static', 'jump', 'gradient'], true)
                || strtolower(substr($raw, 2 + $index * 26, 26)) !== self::encodeUnit($unit)) {
                self::fail();
            }
        }

        return [substr($raw, 0, 2), $value['scene_num']];
    }

    private static function encodeUnit(array $unit): string
    {
        $mode = array_search($unit['unit_change_mode'], ['static', 'jump', 'gradient'], true);

        return sprintf('%02x%02x%02x%04x%04x%04x%04x%04x', $unit['unit_switch_duration'],
            $unit['unit_gradient_duration'], $mode, $unit['h'], $unit['s'], $unit['v'], $unit['bright'], $unit['temperature']);
    }

    private static function fail(): never
    {
        throw new TuyaCloudException('configuration_error');
    }
}
