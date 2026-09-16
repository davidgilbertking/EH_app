<?php

namespace App\Console\Commands;

use App\Lighting\Drivers\CloudLightingFiles;
use App\Lighting\Drivers\TuyaCloudClient;
use App\Lighting\Drivers\TuyaCloudException;
use Illuminate\Console\Command;
use Throwable;

/** Explicit commissioning only; never called by an HTTP request or by the worker. */
class LightingProbeCloudCommand extends Command
{
    protected $signature = 'lighting:probe-cloud {operation=status : status, scene, dark, or white} {--expect-device-id=} {--alias=blue} {--profile=action} {--duration-ms=8000}';

    protected $description = 'Run one bounded Cloud commissioning probe for the explicitly selected lamp';

    public function handle(): int
    {
        $writes = 0;
        $writeTimings = [];
        $expected = null;
        $started = hrtime(true);
        $client = new TuyaCloudClient(config('lighting.cloud'));
        try {
            if ($this->option('expect-device-id') !== $client->deviceId()) {
                throw new TuyaCloudException('configuration_error');
            }
            $operation = $this->argument('operation');
            if (! in_array($operation, ['status', 'scene', 'dark', 'white'], true)) {
                throw new TuyaCloudException('configuration_error');
            }
            $before = $client->readProperties();
            $dps = $this->dps($before);
            $send = function (array $commands) use ($client, &$writes, &$writeTimings): array {
                // Count before sending: a timeout can leave the physical result unknown.
                $writes++;

                $start = hrtime(true);
                $ack = $client->sendCommands($commands);
                $writeTimings[] = array_merge($ack, ['elapsed_ms' => (int) round((hrtime(true) - $start) / 1000000)]);

                return $ack;
            };
            if ($operation !== 'status') {
                $files = new CloudLightingFiles(config('lighting.cloud.private_directory'), $client->deviceId());
                if (($dps[20]['value'] ?? null) !== true) {
                    throw new TuyaCloudException('unsupported_transition');
                }
                if ($operation === 'scene') {
                    $this->requireDark($dps);
                    $scene = $files->scenes[$this->option('alias')] ?? null;
                    if ($scene === null) {
                        throw new TuyaCloudException('preset_not_configured');
                    }
                    $send([['code' => 'scene_data_v2', 'value' => $scene['value']], ['code' => 'work_mode', 'value' => 'scene']]);
                    $expected = [20 => true, 21 => 'scene', 25 => $scene['source_sha256']];
                } elseif ($operation === 'dark') {
                    // Unknown RGB scenes are not touched by the commissioning command.
                    if (($dps[21]['value'] ?? null) !== 'scene'
                        || ! in_array(hash('sha256', (string) ($dps[25]['value'] ?? '')), array_column($files->scenes, 'source_sha256'), true)) {
                        throw new TuyaCloudException('unsupported_transition');
                    }
                    $send($this->realtime(10, 0));
                    usleep(800000);
                    $send($this->whiteCommit($files->profiles['dark']));
                    $expected = [20 => true, 21 => 'white', 22 => 10, 23 => 0];
                } else {
                    $this->requireDark($dps);
                    $profile = $files->profiles[$this->option('profile')] ?? null;
                    $duration = filter_var($this->option('duration-ms'), FILTER_VALIDATE_INT);
                    if (! in_array($this->option('profile'), ['action', 'encounters'], true)
                        || $profile === null || $duration === false || $duration < 1000 || $duration > 30000) {
                        throw new TuyaCloudException('configuration_error');
                    }
                    $rampStarted = hrtime(true);
                    while (true) {
                        $elapsed = (hrtime(true) - $rampStarted) / 1000000;
                        $fraction = min(1, $elapsed / $duration);
                        $eased = $fraction ** 2.2;
                        $send($this->realtime((int) round(10 + ($profile['brightness'] - 10) * $eased), (int) round($profile['temperature'] * $eased)));
                        if ($fraction >= 1) {
                            break;
                        }
                        // No backlog: each next value uses actual elapsed monotonic time.
                        usleep(300000);
                    }
                    $send($this->whiteCommit($profile));
                    $expected = [20 => true, 21 => 'white', 22 => $profile['brightness'], 23 => $profile['temperature']];
                }
                usleep(800000);
            }
            $after = $operation === 'status' ? $before : $client->readProperties();
            $summary = $this->summary($after);
            $matches = true;
            foreach ($expected ?? [] as $dp => $value) {
                $matches = $matches && ($summary[$dp]['value'] ?? null) === $value;
            }
            $report = ['ok' => $matches, 'operation' => $operation, 'write_attempts' => $writes,
                'elapsed_ms' => (int) round((hrtime(true) - $started) / 1000000),
                'before' => $this->summary($before), 'after' => $summary, 'write_timings' => $writeTimings,
                'endpoint_reported' => $expected === null ? null : $matches,
                'visual_confirmation_required' => $writes > 0];
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return $matches ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $error) {
            $this->line(json_encode(['ok' => false, 'error' => $error instanceof TuyaCloudException ? $error->getMessage() : 'configuration_error',
                'vendor_code' => $error instanceof TuyaCloudException ? $error->vendorCode : null,
                'write_attempts' => $writes, 'lighting_changed' => $writes === 0 ? false : 'unknown'], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
    }

    private function realtime(int $brightness, int $temperature): array
    {
        return [['code' => 'control_data', 'value' => ['change_mode' => 'gradient', 'h' => 0, 's' => 0, 'v' => 0,
            'bright' => $brightness, 'temperature' => $temperature]]];
    }

    private function whiteCommit(array $profile): array
    {
        return [['code' => 'switch_led', 'value' => true], ['code' => 'work_mode', 'value' => 'white'],
            ['code' => 'bright_value_v2', 'value' => $profile['brightness']], ['code' => 'temp_value_v2', 'value' => $profile['temperature']]];
    }

    private function requireDark(array $dps): void
    {
        if (($dps[21]['value'] ?? null) !== 'white' || ($dps[22]['value'] ?? null) !== 10 || ($dps[23]['value'] ?? null) !== 0) {
            throw new TuyaCloudException('unsupported_transition');
        }
    }

    private function dps(array $response): array
    {
        $result = [];
        foreach ($response['properties'] as $property) {
            if (isset($property['dp_id'])) {
                $result[(int) $property['dp_id']] = $property;
            }
        }

        return $result;
    }

    private function summary(array $response): array
    {
        $dps = $this->dps($response);
        $result = [];
        foreach ([20, 21, 22, 23, 25, 28] as $dp) {
            if (isset($dps[$dp])) {
                $result[$dp] = ['value' => $dp === 25 ? hash('sha256', (string) $dps[$dp]['value']) : $dps[$dp]['value'],
                    'reported_at' => $dps[$dp]['time'] ?? null];
            }
        }

        return $result;
    }
}
