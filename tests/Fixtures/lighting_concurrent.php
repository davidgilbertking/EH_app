<?php

use App\Lighting\LightingControl;
use App\Lighting\LightingStore;
use App\Models\LightingState;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// Isolated subprocess fixture: the test supplies a disposable SQLite path.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config()->set('database.default', 'sqlite');
config()->set('database.connections.sqlite.database', $argv[1]);
config()->set('database.connections.sqlite.url', null);
config()->set('database.connections.sqlite.busy_timeout', 5000);
config()->set('app.key', 'concurrency-test-only-not-a-credential');
config()->set('lighting.allowed_user_ids', [1]);
DB::purge();

$control = app(LightingControl::class);
if ($argv[2] === 'initialize') {
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_09_16_000001_create_lighting_tables.php';
    $migration->up();
    echo json_encode($control->control(1, 'test-session', true, null), JSON_THROW_ON_ERROR);
    exit;
}
if ($argv[2] === 'status') {
    $state = LightingState::findOrFail(1);
    echo json_encode(['sequence' => $state->client_seq, 'revision' => $state->revision, 'target' => $state->desired_target]);
    exit;
}
if ($argv[2] === 'initialize-cache') {
    Schema::create('cache', function ($table) {
        $table->string('key')->primary();
        $table->mediumText('value');
        $table->integer('expiration');
    });
    Cache::store('database')->put('lighting-race-counter', 0, 60);
    exit;
}
if ($argv[2] === 'increment-cache') {
    // Pause after DatabaseStore's SELECT while its transaction is still open.
    // With DEFERRED, a worker can obtain the write reservation in this window,
    // making the rate limiter's later UPDATE fail with SQLITE_BUSY.
    DB::listen(function ($query) use ($argv) {
        if (str_starts_with($query->sql, 'select') && str_contains($query->sql, '"cache"')) {
            file_put_contents($argv[3], 'cache-read');
            usleep(350000);
        }
    });
    echo json_encode(['counter' => Cache::store('database')->increment('lighting-race-counter')]);
    exit;
}
if ($argv[2] === 'worker-write') {
    app(LightingStore::class)->atomic(function ($state) {
        $state->worker_seen_ms = LightingStore::now();
    });
    echo json_encode(['written' => true]);
    exit;
}
$intent = [
    'intentId' => (string) Str::uuid(), 'controlEpoch' => $argv[3],
    'clientSeq' => (int) $argv[4], 'target' => ['kind' => 'white', 'profile' => $argv[4] === '2' ? 'encounters' : 'action'],
];
if ($argv[2] === 'hold-and-accept') {
    $result = app(LightingStore::class)->atomic(function () use ($control, $intent, $argv) {
        file_put_contents($argv[5], 'locked');
        usleep(350000);

        return $control->intent(1, 'test-session', $intent);
    });
} else {
    $result = $control->intent(1, 'test-session', $intent);
}
echo json_encode($result, JSON_THROW_ON_ERROR);
