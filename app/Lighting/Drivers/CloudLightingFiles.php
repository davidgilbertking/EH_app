<?php

namespace App\Lighting\Drivers;

use Throwable;

/** Validated local calibration and explicit LAN/cloud scene pairs; no discovery. */
class CloudLightingFiles
{
    /** Capture provenance stays stable when Tuya assigns the lamp a new ID. */
    public readonly string $sourceDeviceId;

    public readonly array $schema;

    public readonly array $profiles;

    public readonly array $scenes;

    private const TYPES = ['switch_led' => 'Boolean', 'work_mode' => 'Enum', 'bright_value_v2' => 'Integer',
        'temp_value_v2' => 'Integer', 'control_data' => 'Json', 'scene_data_v2' => 'Json'];

    public function __construct(string $directory, string $deviceId, ?TuyaCloudClient $client = null)
    {
        $document = self::readJson($directory.'/cloud-functions-inspection.json');
        $this->sourceDeviceId = self::documentDeviceId($document);
        if ($this->sourceDeviceId !== $deviceId && $client === null) {
            self::fail();
        }
        $schema = self::functionSchema($document['response']['result']['functions'] ?? []);
        $this->schema = $schema;
        $saved = self::readJson($directory.'/calibration.json');
        self::identity($saved, $this->sourceDeviceId);
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
        $this->profiles = $profiles;
        $this->scenes = $this->loadScenes($directory, $this->sourceDeviceId);

        // Keep the established ID path entirely local. A new cloud address must
        // support the saved command schema before any driver can use the bundle.
        if ($this->sourceDeviceId !== $deviceId) {
            if ($client->deviceId() !== $deviceId
                || self::canonical(self::functionSchema($client->readFunctions())) !== self::canonical($schema)) {
                self::fail();
            }
            $this->validateReboundModel($client->readModel());
        }
    }

    public static function capturedDeviceId(string $directory): string
    {
        return self::documentDeviceId(self::readJson($directory.'/cloud-functions-inspection.json'));
    }

    private static function documentDeviceId(array $document): string
    {
        $id = $document['device_id'] ?? null;
        if (! is_string($id) || ! preg_match('/\A[A-Za-z0-9_-]{6,128}\z/D', $id)
            || ($document['response']['success'] ?? null) !== true) {
            self::fail();
        }

        return $id;
    }

    private static function functionSchema(mixed $functions): array
    {
        if (! is_array($functions)) {
            self::fail();
        }
        $schema = [];
        foreach ($functions as $entry) {
            $code = $entry['code'] ?? null;
            if (! is_string($code) || ! isset(self::TYPES[$code])) {
                continue;
            }
            if (isset($schema[$code]) || ($entry['type'] ?? null) !== self::TYPES[$code]) {
                self::fail();
            }
            $schema[$code] = self::object($entry['values'] ?? null);
        }
        if (count($schema) !== count(self::TYPES) || ! in_array('white', $schema['work_mode']['range'] ?? [], true)
            || ! in_array('scene', $schema['work_mode']['range'] ?? [], true)
            || ! in_array('gradient', $schema['control_data']['change_mode']['range'] ?? [], true)) {
            self::fail();
        }
        foreach (['h', 's', 'v'] as $field) {
            self::range(0, $schema['control_data'][$field] ?? null);
        }

        return $schema;
    }

    private static function canonical(array $value): array
    {
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = self::canonical($entry);
                if ($key === 'range' && array_is_list($entry)) {
                    sort($value[$key]);
                }
            }
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /** Numeric shadow reads and native writes must keep their established DP map. */
    private function validateReboundModel(array $model): void
    {
        $expected = [20 => ['switch_led', 'bool'], 21 => ['work_mode', 'enum'],
            22 => ['bright_value', 'value'], 23 => ['temp_value', 'value'],
            24 => ['colour_data', 'string'], 25 => ['scene_data', 'string'],
            28 => ['control_data', 'string'], 35 => ['switch_gradient', 'raw']];
        $matches = array_fill_keys(array_keys($expected), 0);
        foreach ($model['services'] ?? [] as $service) {
            foreach ($service['properties'] ?? [] as $property) {
                if (! is_array($property)) {
                    self::fail();
                }
                foreach ($expected as $id => [$code, $type]) {
                    if (($property['abilityId'] ?? null) !== $id && ($property['code'] ?? null) !== $code) {
                        continue;
                    }
                    $spec = $property['typeSpec'] ?? [];
                    if (($service['code'] ?? null) !== '' || ($property['abilityId'] ?? null) !== $id
                        || ($property['code'] ?? null) !== $code || ($spec['type'] ?? null) !== $type
                        || ! in_array($property['accessMode'] ?? null, $id === 28 ? ['wr', 'rw'] : ['rw'], true)) {
                        self::fail();
                    }
                    if ($id === 21) {
                        if (self::canonical(['range' => $spec['range'] ?? null])
                            !== self::canonical(['range' => $this->schema['work_mode']['range']])) {
                            self::fail();
                        }
                    } elseif ($id === 22 || $id === 23) {
                        foreach (['min', 'max', 'scale', 'step'] as $field) {
                            if (($spec[$field] ?? null) !== ($this->schema[$code.'_v2'][$field] ?? null)) {
                                self::fail();
                            }
                        }
                    } elseif (in_array($id, [24, 25, 28, 35], true)) {
                        $minimum = match ($id) {
                            24 => 12,
                            25 => max([54, ...array_map(static fn (array $scene): int => strlen($scene['raw']), $this->scenes)]),
                            28 => 21,
                            35 => 7,
                        };
                        if (! is_int($spec['maxlen'] ?? null) || $spec['maxlen'] < $minimum) {
                            self::fail();
                        }
                    }
                    $matches[$id]++;
                }
            }
        }
        if (array_values($matches) !== array_fill(0, count($expected), 1)) {
            self::fail();
        }
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
        foreach ($this->profiles as $profile) {
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
