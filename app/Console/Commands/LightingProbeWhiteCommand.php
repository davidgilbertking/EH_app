<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class LightingProbeWhiteCommand extends Command
{
    protected $signature = 'lighting:probe-white
        {from : Source profile: action or dark}
        {to : Target profile: action or dark}
        {--expect-device-id= : Exact ID of the calibrated physical lamp}
        {--native-mode=gradient : Diagnostic DP28 mode: gradient or direct}
        {--tick-ms= : Override the diagnostic command interval}
        {--curve= : Override the diagnostic curve: smoothstep, linear, or perceptual}
        {--require-source : Require the existing exact source profile without resetting it}
        {--receive-responses : Read lamp replies and confirm the final stored target before disconnecting}';

    protected $description = 'Run one physical white-fade diagnostic using lighting configuration';

    public function handle(): int
    {
        $from = $this->argument('from');
        $to = $this->argument('to');
        $id = $this->option('expect-device-id');
        $nativeMode = $this->option('native-mode');
        if (! in_array($from, ['action', 'dark'], true)
            || ! in_array($to, ['action', 'dark'], true) || $from === $to
            || ! is_string($id) || ! preg_match('/\A[A-Za-z0-9_-]{6,128}\z/', $id)) {
            $this->error('Choose action → dark or dark → action and provide --expect-device-id.');

            return self::INVALID;
        }

        if (! in_array($nativeMode, ['gradient', 'direct'], true)) {
            $this->error('Choose --native-mode=gradient or --native-mode=direct.');

            return self::INVALID;
        }

        $duration = (int) config($to === 'dark' ? 'lighting.white_fade_down_ms' : 'lighting.white_fade_up_ms');
        $tickOption = $this->option('tick-ms');
        if ($tickOption !== null && (! is_string($tickOption) || ! preg_match('/\A[0-9]+\z/', $tickOption))) {
            $this->error('The diagnostic command interval must be an integer in milliseconds.');

            return self::INVALID;
        }
        $tick = (int) ($tickOption ?? config('lighting.tick_ms'));
        $curve = $this->option('curve') ?? config('lighting.curve');
        if ($duration < 500 || $duration > 30000 || $tick < 100 || $tick > 500
            || intdiv($duration, $tick) < 5 || intdiv($duration, $tick) > 300
            || ! in_array($curve, ['smoothstep', 'linear', 'perceptual'], true)) {
            $this->error('Invalid diagnostic timing: duration 500–30000ms, tick 100–500ms, 5–300 frames, smoothstep, linear, or perceptual.');

            return self::INVALID;
        }

        $command = [
            storage_path('app/private/lighting/venv/bin/python'),
            base_path('scripts/lighting/timed_white_probe.py'),
            '--from-profile', $from, '--to-profile', $to, '--expect-device-id', $id,
            '--duration-ms', (string) $duration, '--tick-ms', (string) $tick, '--curve', $curve,
            '--native-mode', $nativeMode,
        ];
        if ($this->option('receive-responses')) {
            $command[] = '--receive-responses';
        }
        if ($this->option('require-source')) {
            $command[] = '--require-source';
        }
        $sourceAction = $this->option('require-source') ? 'require existing' : 'establish';
        $this->info("Physical diagnostic: {$sourceAction} {$from}, then schedule {$to} over {$duration}ms ({$curve}, tick {$tick}ms, native {$nativeMode}).");
        $child = null;
        $cancelled = false;
        $this->trap([SIGINT, SIGTERM], function () use (&$child, &$cancelled) {
            $cancelled = true;
            if ($child !== null && $child->running()) {
                $child->signal(SIGTERM);
            }
        });

        try {
            if ($cancelled) {
                return self::FAILURE;
            }
            $child = Process::path(base_path())->env(['PYTHONDONTWRITEBYTECODE' => '1'])
                ->timeout(50)->start($command);
            if ($cancelled && $child->running()) {
                $child->signal(SIGTERM);
            }
            $result = $child->wait();
            $this->line(trim($result->output()));

            return ! $cancelled && $result->successful() ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable) {
            $this->error('Physical diagnostic stopped; inspect the private lighting report before repeating.');

            return self::FAILURE;
        } finally {
            if ($child !== null && $child->running()) {
                $child->stop(0.2, SIGTERM);
            }
            $this->untrap();
        }
    }
}
