<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class LightingConcurrencyTest extends TestCase
{
    public function test_database_rate_limiter_can_increment_while_worker_writes(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'eh-lighting-cache-');
        $gate = $database.'.gate';
        $processes = [];
        $process = fn (array $arguments) => new Process([PHP_BINARY, base_path('tests/Fixtures/lighting_concurrent.php'), $database, ...$arguments], base_path(), ['APP_ENV' => 'testing'], timeout: 10);
        try {
            $process(['initialize'])->mustRun();
            $process(['initialize-cache'])->mustRun();
            $cache = $process(['increment-cache', $gate]);
            $processes[] = $cache;
            $cache->start();
            $deadline = microtime(true) + 5;
            while (! file_exists($gate) && microtime(true) < $deadline && $cache->isRunning()) {
                usleep(1000);
            }
            $this->assertFileExists($gate);
            $worker = $process(['worker-write']);
            $processes[] = $worker;
            $worker->start();
            $cache->wait();
            $worker->wait();
            $this->assertSame(0, $cache->getExitCode(), $cache->getErrorOutput());
            $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
            $this->assertSame(1, json_decode($cache->getOutput(), true)['counter']);
            $this->assertTrue(json_decode($worker->getOutput(), true)['written']);
        } finally {
            foreach ($processes as $child) {
                if ($child->isRunning()) {
                    $child->stop();
                }
            }
            foreach ([$database, $gate, $database.'-wal', $database.'-shm', $database.'-journal'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function test_file_backed_sqlite_serializes_simultaneous_admission_and_rejects_late_sequence(): void
    {
        $database = tempnam(sys_get_temp_dir(), 'eh-lighting-sqlite-');
        $gate = $database.'.gate';
        $processes = [];
        $process = fn (array $arguments) => new Process([PHP_BINARY, base_path('tests/Fixtures/lighting_concurrent.php'), $database, ...$arguments], base_path(), ['APP_ENV' => 'testing'], timeout: 10);
        try {
            $initialize = $process(['initialize']);
            $initialize->mustRun();
            $epoch = json_decode($initialize->getOutput(), true, 512, JSON_THROW_ON_ERROR)['controlEpoch'];
            $newer = $process(['hold-and-accept', $epoch, '2', $gate]);
            $processes[] = $newer;
            $newer->start();
            $deadline = microtime(true) + 5;
            while (! file_exists($gate) && microtime(true) < $deadline && $newer->isRunning()) {
                usleep(1000);
            }
            $this->assertFileExists($gate);
            $older = $process(['accept', $epoch, '1']);
            $processes[] = $older;
            $older->start();
            $newer->wait();
            $older->wait();
            $this->assertSame(0, $newer->getExitCode(), $newer->getErrorOutput().$newer->getOutput());
            $this->assertSame(0, $older->getExitCode(), $older->getErrorOutput().$older->getOutput());
            $this->assertTrue(json_decode($newer->getOutput(), true)['accepted']);
            $this->assertSame('stale', json_decode($older->getOutput(), true)['error']);
            $status = $process(['status']);
            $status->mustRun();
            $state = json_decode($status->getOutput(), true);
            $this->assertSame(2, $state['sequence']);
            $this->assertSame(2, $state['revision']); // Control acquisition + only one accepted intent.
            $this->assertSame('encounters', $state['target']['profile']);
        } finally {
            foreach ($processes as $child) {
                if ($child->isRunning()) {
                    $child->stop();
                }
            }
            foreach ([$database, $gate, $database.'-wal', $database.'-shm', $database.'-journal'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
