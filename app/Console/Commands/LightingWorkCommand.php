<?php

namespace App\Console\Commands;

use App\Lighting\Drivers\TuyaCloudException;
use App\Lighting\LightingDeviceBinding;
use App\Lighting\LightingExecutor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LightingWorkCommand extends Command
{
    protected $signature = 'lighting:work {--once : Execute one mailbox tick} {--ticks=0 : Stop after N ticks; zero runs continuously}';

    protected $description = 'Run the single-host lighting mailbox executor for the configured driver';

    public function handle(LightingExecutor $coordinator, LightingDeviceBinding $binding): int
    {
        if (DB::connection()->getDriverName() === 'sqlite' && PHP_VERSION_ID < 80400) {
            $this->error('Lighting with SQLite requires PHP 8.4+ for IMMEDIATE transactions. Upgrade PHP before starting this worker.');

            return self::FAILURE;
        }
        // Keep old/new configured workers from alternating between sender ticks
        // during a device rebind. The per-tick sender lock remains unchanged.
        $connection = DB::connection();
        $identity = $connection->getDriverName().':'.$connection->getConfig('host').':'.$connection->getDatabaseName();
        $lock = fopen(storage_path('framework/lighting-worker-'.hash('sha256', $identity).'.lock'), 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            $this->error('A lighting worker is already running for this database, or its lock is unavailable.');

            return self::FAILURE;
        }

        try {
            $binding->synchronize();

            return $this->runWorker($coordinator);
        } catch (TuyaCloudException $exception) {
            $this->error('Lighting device binding: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function runWorker(LightingExecutor $coordinator): int
    {
        $limit = $this->option('once') ? 1 : max(0, (int) $this->option('ticks'));
        $tickMs = max(50, min(1000, (int) config('lighting.tick_ms')));
        $stop = false;
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function () use (&$stop) {
                $stop = true;
            });
            pcntl_signal(SIGINT, function () use (&$stop) {
                $stop = true;
            });
        }
        $this->info('Lighting mailbox worker: '.config('lighting.driver')
            .(config('lighting.driver') === 'mock' ? ' (simulation).' : '.'));
        $coordinator->recover();
        for ($tick = 0; ! $stop && ($limit === 0 || $tick < $limit); $tick++) {
            $coordinator->tick();
            if ($limit === 0 || $tick + 1 < $limit) {
                usleep($tickMs * 1000);
            }
        }

        return self::SUCCESS;
    }
}
