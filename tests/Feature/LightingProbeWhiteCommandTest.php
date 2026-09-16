<?php

namespace Tests\Feature;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class LightingProbeWhiteCommandTest extends TestCase
{
    public function test_probe_uses_resolved_direction_settings_and_an_argument_array(): void
    {
        Process::fake();
        Process::preventStrayProcesses();
        config(['lighting.white_fade_up_ms' => 3500, 'lighting.white_fade_down_ms' => 4500,
            'lighting.tick_ms' => 100, 'lighting.curve' => 'smoothstep']);

        foreach ([['dark', 'action', '3500'], ['action', 'dark', '4500']] as [$from, $to, $duration]) {
            $this->artisan('lighting:probe-white', [
                'from' => $from, 'to' => $to, '--expect-device-id' => 'fixture_lamp_123',
            ])->assertSuccessful();
            Process::assertRan(fn (PendingProcess $process) => $process->command === [
                storage_path('app/private/lighting/venv/bin/python'),
                base_path('scripts/lighting/timed_white_probe.py'),
                '--from-profile', $from, '--to-profile', $to, '--expect-device-id', 'fixture_lamp_123',
                '--duration-ms', $duration, '--tick-ms', '100', '--curve', 'smoothstep',
                '--native-mode', 'gradient',
            ] && $process->timeout === 50 && $process->environment['PYTHONDONTWRITEBYTECODE'] === '1');
        }
        $this->assertSame('mock', config('lighting.driver'));
    }

    public function test_invalid_device_or_timing_never_starts_a_process(): void
    {
        Process::fake();
        $this->artisan('lighting:probe-white', ['from' => 'dark', 'to' => 'action',
            '--expect-device-id' => 'bad;command'])->assertExitCode(2);
        config(['lighting.white_fade_up_ms' => 4000, 'lighting.tick_ms' => 10]);
        $this->artisan('lighting:probe-white', ['from' => 'dark', 'to' => 'action',
            '--expect-device-id' => 'fixture_lamp_123'])->assertExitCode(2);
        Process::assertNothingRan();
    }

    public function test_transport_failure_is_returned_without_retrying(): void
    {
        Process::fake(['*' => Process::result(output: '{"ok":false,"error":"write_outcome_unknown"}', exitCode: 1)]);
        config(['lighting.white_fade_up_ms' => 4000, 'lighting.tick_ms' => 100, 'lighting.curve' => 'linear']);
        $this->artisan('lighting:probe-white', ['from' => 'dark', 'to' => 'action',
            '--expect-device-id' => 'fixture_lamp_123'])->assertFailed();
        Process::assertRanTimes(fn () => true, 1);
    }

    public function test_direct_variant_is_explicit_and_unknown_mode_never_starts_process(): void
    {
        Process::fake();
        config(['lighting.white_fade_up_ms' => 4000, 'lighting.tick_ms' => 100, 'lighting.curve' => 'smoothstep']);
        $this->artisan('lighting:probe-white', ['from' => 'dark', 'to' => 'action',
            '--expect-device-id' => 'fixture_lamp_123', '--native-mode' => 'unsupported'])->assertExitCode(2);
        Process::assertNothingRan();
        $this->artisan('lighting:probe-white', ['from' => 'dark', 'to' => 'action',
            '--expect-device-id' => 'fixture_lamp_123', '--native-mode' => 'direct'])->assertSuccessful();
        Process::assertRanTimes(fn (PendingProcess $process) => array_slice($process->command, -2) === ['--native-mode', 'direct'], 1);
    }

    public function test_reply_receiving_variant_is_passed_to_the_diagnostic(): void
    {
        Process::fake();
        config(['lighting.white_fade_up_ms' => 4000, 'lighting.tick_ms' => 100, 'lighting.curve' => 'smoothstep']);
        $this->artisan('lighting:probe-white', ['from' => 'dark', 'to' => 'action',
            '--expect-device-id' => 'fixture_lamp_123', '--native-mode' => 'direct',
            '--receive-responses' => true])->assertSuccessful();
        Process::assertRanTimes(fn (PendingProcess $process) => array_slice($process->command, -3) === ['--native-mode', 'direct', '--receive-responses'], 1);
    }

    public function test_slow_rise_uses_configured_duration_and_explicit_diagnostic_options(): void
    {
        Process::fake();
        Process::preventStrayProcesses();
        config(['lighting.white_fade_up_ms' => 20000, 'lighting.tick_ms' => 100, 'lighting.curve' => 'smoothstep']);
        $this->artisan('lighting:probe-white', ['from' => 'dark', 'to' => 'action',
            '--expect-device-id' => 'fixture_lamp_123', '--tick-ms' => '300',
            '--curve' => 'perceptual', '--require-source' => true,
            '--receive-responses' => true])->assertSuccessful();
        Process::assertRanTimes(fn (PendingProcess $process) => $process->command === [
            storage_path('app/private/lighting/venv/bin/python'), base_path('scripts/lighting/timed_white_probe.py'),
            '--from-profile', 'dark', '--to-profile', 'action', '--expect-device-id', 'fixture_lamp_123',
            '--duration-ms', '20000', '--tick-ms', '300', '--curve', 'perceptual',
            '--native-mode', 'gradient', '--receive-responses', '--require-source',
        ], 1);
        $this->assertSame('smoothstep', config('lighting.curve'));
        $this->assertSame(100, config('lighting.tick_ms'));
    }

    public function test_invalid_diagnostic_overrides_do_not_start_a_process(): void
    {
        Process::fake();
        config(['lighting.white_fade_up_ms' => 20000, 'lighting.tick_ms' => 100, 'lighting.curve' => 'smoothstep']);
        foreach ([['--tick-ms' => '300junk'], ['--tick-ms' => '0'], ['--curve' => 'unsupported']] as $options) {
            $this->artisan('lighting:probe-white', array_merge(['from' => 'dark', 'to' => 'action',
                '--expect-device-id' => 'fixture_lamp_123'], $options))->assertExitCode(2);
        }
        config(['lighting.white_fade_up_ms' => 30001]);
        $this->artisan('lighting:probe-white', ['from' => 'dark', 'to' => 'action',
            '--expect-device-id' => 'fixture_lamp_123'])->assertExitCode(2);
        Process::assertNothingRan();
    }
}
