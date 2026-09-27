<?php

namespace App\Lighting\Drivers;

use Throwable;

/** Validated local calibration and explicit LAN/cloud scene pairs; no discovery. */
class CloudLightingFiles
{
    public readonly array $schema;

    public readonly array $profiles;

    public readonly array $colours;

    public readonly array $scenes;

    private readonly array $calibrationProfiles;

    private const TYPES = ['switch_led' => 'Boolean', 'work_mode' => 'Enum', 'bright_value_v2' => 'Integer',
        'temp_value_v2' => 'Integer', 'control_data' => 'Json', 'scene_data_v2' => 'Json'];

    public function __construct(string $directory, string $deviceId)
    {
        $document = self::readJson($directory.'/cloud-functions-inspection.json');
        if (($document['device_id'] ?? null) !== $deviceId || ($document['response']['success'] ?? null) !== true) {
            self::fail();
        }
        $schema = [];
        foreach ($document['response']['result']['functions'] ?? [] as $entry) {
            $code = $entry['code'] ?? null;
            if (! is_string($code) || (! isset(self::TYPES[$code]) && $code !== 'colour_data_v2')) {
                continue;
            }
            if (isset($schema[$code]) || ($entry['type'] ?? null) !== (self::TYPES[$code] ?? 'Json')) {
                self::fail();
            }
            $schema[$code] = self::object($entry['values'] ?? null);
        }
        if (array_diff_key(self::TYPES, $schema) !== [] || ! in_array('white', $schema['work_mode']['range'] ?? [], true)
            || ! in_array('scene', $schema['work_mode']['range'] ?? [], true)
            || ! in_array('gradient', $schema['control_data']['change_mode']['range'] ?? [], true)) {
            self::fail();
        }
        foreach (['h', 's', 'v'] as $field) {
            self::range(0, $schema['control_data'][$field] ?? null);
        }
        $this->schema = $schema;
        $saved = self::readJson($directory.'/calibration.json');
        self::identity($saved, $deviceId);
        $profiles = [];
        foreach (['dark' => [1, 0], 'action' => [100, 33], 'encounters' => [90, 20]] as $name => [$brightness, $temperature]) {
            $profile = $saved['white_profiles'][$name] ?? null;
            if (! is_array($profile) || ($profile['user_confirmed'] ?? null) !== true || ($profile['replay_verified'] ?? null) !== true
                || ($profile['ui'] ?? null) != ['brightness' => $brightness, 'temperature' => $temperature]) {
                self::fail();
            }
            $dps = $profile['dps'] ?? [];
            if (count($dps) !== 4 || ($dps['20'] ?? null) !== true || ($dps['21'] ?? null) !== 'white') {
                self::fail();
            }
            self::range($dps['22'] ?? null, $schema['bright_value_v2']);
            self::range($dps['23'] ?? null, $schema['temp_value_v2']);
            $profiles[$name] = ['brightnessPct' => (float) $brightness, 'temperaturePct' => (float) $temperature,
                'brightness' => $dps['22'], 'temperature' => $dps['23']];
        }
        if ($profiles['dark']['brightness'] !== 10 || $profiles['dark']['temperature'] !== 0) {
            self::fail();
        }
        // Captured targets can share slider percentages while retaining different
        // raw values. They must never become interpolation calibration anchors.
        $this->calibrationProfiles = $profiles;
        [$this->profiles, $this->colours] = $this->loadCaptures($directory, $deviceId, $profiles);
        $this->scenes = $this->loadScenes($directory, $deviceId);
    }

    public static function readJson(string $path): array
    {
        try {
            clearstatcache(true, $path);
            if (! is_file($path) || is_link($path) || (fileperms($path) & 0077) !== 0
                || filesize($path) > 1048576 || (function_exists('posix_geteuid') && fileowner($path) !== posix_geteuid())) {
                self::fail();
            }
            $data = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($data)) {
                self::fail();
            }

            return $data;
        } catch (Throwable) {
            self::fail();
        }
    }

    public static function object(mixed $value): array
    {
        if (is_string($value)) {
            try {
                $value = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                self::fail();
            }
        }
        if (! is_array($value)) {
            self::fail();
        }

        return $value;
    }

    public static function range(mixed $value, mixed $limits): void
    {
        if (! is_int($value) || ! is_array($limits)) {
            self::fail();
        }
        foreach (['min', 'max', 'scale', 'step'] as $field) {
            if (! is_int($limits[$field] ?? null)) {
                self::fail();
            }
        }
        if ($limits['scale'] !== 0 || $limits['step'] < 1 || $value < $limits['min'] || $value > $limits['max']
            || ($value - $limits['min']) % $limits['step'] !== 0) {
            self::fail();
        }
    }

    public function white(float $brightness, float $temperature): array
    {
        if (! is_finite($brightness) || ! is_finite($temperature) || $brightness < 1 || $brightness > 100
            || $temperature < 0 || $temperature > 100) {
            self::fail();
        }
        $raw = ['brightness' => (int) round($this->interpolate($brightness, 'brightness', false)),
            'temperature' => (int) round($this->interpolate($temperature, 'temperature', false))];
        self::range($raw['brightness'], $this->schema['bright_value_v2']);
        self::range($raw['temperature'], $this->schema['temp_value_v2']);

        return $raw;
    }

    public function whitePercentages(int $brightness, int $temperature): array
    {
        self::range($brightness, $this->schema['bright_value_v2']);
        self::range($temperature, $this->schema['temp_value_v2']);

        return ['brightnessPct' => $this->interpolate($brightness, 'brightness', true),
            'temperaturePct' => $this->interpolate($temperature, 'temperature', true)];
    }

    private function interpolate(float $value, string $field, bool $inverse): float
    {
        $points = [];
        foreach ($this->calibrationProfiles as $profile) {
            $points[] = [$profile[$field.'Pct'], $profile[$field]];
        }
        if ($field === 'temperature') {
            $points[] = [100, $this->schema['temp_value_v2']['max']];
        }
        usort($points, fn ($a, $b) => $a[0] <=> $b[0]);
        $x = $inverse ? 1 : 0;
        $y = 1 - $x;
        foreach ($points as $index => $point) {
            if ($value === (float) $point[$x]) {
                return (float) $point[$y];
            }
            if ($index > 0) {
                $previous = $points[$index - 1];
                if ($point[0] <= $previous[0] || $point[1] <= $previous[1]) {
                    self::fail();
                }
                if ($value < $point[$x] && $value > $previous[$x]) {
                    return $previous[$y] + ($point[$y] - $previous[$y]) * ($value - $previous[$x]) / ($point[$x] - $previous[$x]);
                }
            }
        }
        self::fail();
    }

    private function loadCaptures(string $directory, string $deviceId, array $profiles): array
    {
        $path = $directory.'/preset-captures.json';
        if (! file_exists($path) && ! is_link($path)) {
            return [$profiles, []];
        }
        $saved = self::readJson($path);
        $configured = config('lighting.presets', []);
        if (($saved['schema_version'] ?? null) !== 1 || ($saved['device_id'] ?? null) !== $deviceId
            || ! is_array($saved['presets'] ?? null) || ! is_array($configured)) {
            self::fail();
        }
        $colours = [];
        foreach ($saved['presets'] as $alias => $capture) {
            $preset = $configured[$alias] ?? null;
            $kind = $capture['kind'] ?? null;
            if (! is_string($alias) || ! is_array($capture) || ! is_array($preset)
                || ! in_array($kind, ['white', 'colour'], true) || ($preset['kind'] ?? null) !== $kind
                || ! self::sameFields($capture['ui'] ?? null, $preset['ui'] ?? null)
                || ($capture['user_confirmed'] ?? null) !== true || ! is_bool($capture['replay_verified'] ?? null)
                || ! is_bool($capture['integrated'] ?? null)) {
                self::fail();
            }
            $ui = $capture['ui'];
            $fields = $kind === 'white' ? ['brightness', 'temperature'] : ['hue', 'brightness', 'balance'];
            if (count($ui) !== count($fields)) {
                self::fail();
            }
            foreach ($fields as $field) {
                self::range($ui[$field] ?? null, ['min' => $field === 'brightness' ? 1 : 0,
                    'max' => $field === 'hue' ? 360 : 100, 'scale' => 0, 'step' => 1]);
            }
            $dps = $capture['dps'] ?? null;
            if (! is_array($dps) || count($dps) !== ($kind === 'white' ? 4 : 3)
                || ($dps['20'] ?? null) !== true || ($dps['21'] ?? null) !== $kind) {
                self::fail();
            }
            $this->validateCaptureSource($capture, $alias, $profiles);
            if ($kind === 'white') {
                self::range($dps['22'] ?? null, $this->schema['bright_value_v2']);
                self::range($dps['23'] ?? null, $this->schema['temp_value_v2']);
                $profile = ['brightnessPct' => (float) $ui['brightness'], 'temperaturePct' => (float) $ui['temperature'],
                    'brightness' => $dps['22'], 'temperature' => $dps['23']];
                if (isset($profiles[$alias]) && $profiles[$alias] !== $profile) {
                    self::fail();
                }
                $profiles[$alias] = $profile;
            } else {
                $raw = $dps['24'] ?? null;
                if (! in_array('colour', $this->schema['work_mode']['range'] ?? [], true)
                    || ! is_string($raw) || ! preg_match('/\A[0-9a-fA-F]{12}\z/D', $raw)) {
                    self::fail();
                }
                $hsv = [];
                foreach (['h', 's', 'v'] as $index => $field) {
                    $hsv[$field] = hexdec(substr($raw, $index * 4, 4));
                    // DP24 uses the colour/scene scale (0..1000), never the
                    // unrelated control_data scale (0..255).
                    self::range($hsv[$field], $this->schema['colour_data_v2'][$field]
                        ?? $this->schema['scene_data_v2']['scene_units'][$field] ?? null);
                }
                if ($hsv['h'] > 360 || $hsv['s'] > 1000 || $hsv['v'] < 10 || $hsv['v'] > 1000
                    || ! self::sameFields($capture['hsv'] ?? null, $hsv)) {
                    self::fail();
                }
                $colours[$alias] = $hsv;
            }
        }

        return [$profiles, $colours];
    }

    private function validateCaptureSource(array $capture, string $alias, array $profiles): void
    {
        if (! is_string($capture['source_snapshot'] ?? null)
            || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]*\.json\z/D', $capture['source_snapshot'])
            || ! is_string($capture['captured_at'] ?? null)
            || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/D', $capture['captured_at'])
            || strtotime($capture['captured_at']) === false) {
            self::fail();
        }
        if (($capture['source'] ?? null) === 'existing_calibration') {
            if (! isset($profiles[$alias]) || ($capture['kind'] ?? null) !== 'white'
                || ($capture['source_calibration'] ?? null) !== 'calibration.json'
                || ($capture['replay_verified'] ?? null) !== true) {
                self::fail();
            }

            return;
        }
        if (($capture['source'] ?? null) !== 'cloud_properties' || ! is_array($capture['reported_times'] ?? null)
            || count($capture['reported_times']) !== count($capture['dps'])) {
            self::fail();
        }
        foreach ($capture['dps'] as $dp => $value) {
            $time = $capture['reported_times'][$dp] ?? null;
            if (! is_int($time) || $time < 0 || $time > (strtotime($capture['captured_at']) + 1) * 1000) {
                self::fail();
            }
        }
    }

    private static function sameFields(mixed $actual, mixed $expected): bool
    {
        if (! is_array($actual) || ! is_array($expected)) {
            return false;
        }
        ksort($actual);
        ksort($expected);

        return $actual === $expected;
    }

    private function loadScenes(string $directory, string $deviceId): array
    {
        if (! file_exists($directory.'/cloud-scenes.json')) {
            return [];
        }
        $mapping = self::readJson($directory.'/cloud-scenes.json');
        if (($mapping['schema_version'] ?? null) !== 1 || ($mapping['device_id'] ?? null) !== $deviceId || ! is_array($mapping['scenes'] ?? null)) {
            self::fail();
        }
        $saved = self::readJson($directory.'/scene-presets.json');
        self::identity($saved, $deviceId);
        $scenes = [];
        foreach ($mapping['scenes'] as $alias => $entry) {
            $preset = $saved['presets'][$alias] ?? null;
            $raw = $preset['dps']['25'] ?? null;
            if (! in_array($alias, ['green', 'yellow', 'blue'], true) || ! is_array($entry) || ($entry['mapping_verified'] ?? null) !== true
                || ! is_string($raw) || ! preg_match('/\A[0-9a-fA-F]{28,210}\z/D', $raw) || (strlen($raw) - 2) % 26 !== 0
                || ($preset['user_confirmed'] ?? null) !== true || ($preset['dps']['21'] ?? null) !== 'scene'
                || ($preset['sha256'] ?? null) !== hash('sha256', $raw) || ($entry['source_sha256'] ?? null) !== $preset['sha256']
                || ($entry['source_header'] ?? null) !== hexdec(substr($raw, 0, 2))) {
                self::fail();
            }
            $value = self::object($entry['value'] ?? null);
            self::range($value['scene_num'] ?? null, $this->schema['scene_data_v2']['scene_num'] ?? null);
            $units = $value['scene_units'] ?? null;
            if (count($value) !== 2 || ! is_array($units) || ! array_is_list($units) || count($units) !== intdiv(strlen($raw) - 2, 26)) {
                self::fail();
            }
            foreach ($units as $index => $unit) {
                $hex = substr($raw, 2 + $index * 26, 26);
                $mode = ['static', 'jump', 'gradient'][hexdec(substr($hex, 4, 2))] ?? null;
                if (! is_array($unit) || count($unit) !== 8 || $mode === null || ($unit['unit_change_mode'] ?? null) !== $mode
                    || ! in_array($mode, $this->schema['scene_data_v2']['scene_units']['unit_change_mode']['range'] ?? [], true)) {
                    self::fail();
                }
                foreach (['unit_switch_duration' => [0, 2], 'unit_gradient_duration' => [2, 2], 'h' => [6, 4],
                    's' => [10, 4], 'v' => [14, 4], 'bright' => [18, 4], 'temperature' => [22, 4]] as $field => [$start, $length]) {
                    self::range($unit[$field] ?? null, $this->schema['scene_data_v2']['scene_units'][$field] ?? null);
                    if ($unit[$field] !== hexdec(substr($hex, $start, $length))) {
                        self::fail();
                    }
                }
            }
            $scenes[$alias] = ['value' => $value, 'source_sha256' => $preset['sha256'], 'raw' => $raw];
        }

        return $scenes;
    }

    private static function identity(array $saved, string $deviceId): void
    {
        if (($saved['schema_version'] ?? null) !== 1 || ($saved['device_id'] ?? null) !== $deviceId || (string) ($saved['protocol'] ?? '') !== '3.5') {
            self::fail();
        }
    }

    private static function fail(): never
    {
        throw new TuyaCloudException('configuration_error');
    }
}
