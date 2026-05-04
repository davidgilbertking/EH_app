<?php

use App\Http\Controllers\AudioController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\StateController;
use App\Http\Controllers\YellowSignIconController;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

Route::get('/ui/yellow-sign-icon.png', YellowSignIconController::class)
    ->name('ui.yellowSignIcon');

Route::get('/debug/version', static function () {
    $head = trim((string) @shell_exec('git rev-parse --short HEAD 2>/dev/null'));
    $manifestPath = public_path('build/manifest.json');
    $manifest = [];
    if (File::exists($manifestPath)) {
        $raw = File::get($manifestPath);
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $manifest = $decoded;
        }
    }
    $appAsset = $manifest['resources/js/app.js']['file'] ?? null;
    $appAssetPath = $appAsset ? public_path("build/{$appAsset}") : null;
    return response()->json([
        'git_head' => $head !== '' ? $head : null,
        'manifest_exists' => File::exists($manifestPath),
        'manifest_mtime' => File::exists($manifestPath) ? @date(DATE_ATOM, File::lastModified($manifestPath)) : null,
        'app_asset' => $appAsset,
        'app_asset_exists' => $appAssetPath ? File::exists($appAssetPath) : false,
        'app_asset_mtime' => ($appAssetPath && File::exists($appAssetPath))
            ? @date(DATE_ATOM, File::lastModified($appAssetPath))
            : null,
    ]);
});

Route::middleware('auth')->group(function () {
    // ---- Pages ----
    Route::get('/', [PageController::class, 'home'])->name('home');

    Route::prefix('encounters')->name('encounters.')->group(function () {
        Route::get('/', [PageController::class, 'contacts'])->name('index');
        Route::get('/general', [PageController::class, 'general'])->name('general');
        Route::get('/general/city', [PageController::class, 'generalCity'])->name('general.city');
        Route::get('/general/wilderness', [PageController::class, 'wilderness'])->name('general.wilderness');
        Route::get('/general/sea', [PageController::class, 'generalSea'])->name('general.sea');
        Route::get('/restriction', [PageController::class, 'obstruction'])->name('restriction');
        Route::get('/named-cities', [PageController::class, 'bigCity'])->name('namedCities');
        Route::get('/other-world', [PageController::class, 'otherWorld'])->name('otherWorld');
        Route::get('/other-world/past', [PageController::class, 'past'])->name('otherWorld.past');
        Route::get('/other-world/future', [PageController::class, 'future'])->name('otherWorld.future');
        Route::get('/defeated', [PageController::class, 'defeated'])->name('defeated');
        Route::get('/quest', [PageController::class, 'expedition'])->name('quest');
        Route::get('/quest/expedition', [PageController::class, 'expeditions'])->name('quest.expedition');
        Route::get('/side-boards', [PageController::class, 'addMap'])->name('sideBoards');
        Route::get('/side-boards/antarctica', [PageController::class, 'antarctica'])->name('sideBoards.antarctica');
        Route::get('/side-boards/egypt', [PageController::class, 'egypt'])->name('sideBoards.egypt');
        Route::get('/side-boards/dreamlands', [PageController::class, 'dreamlands'])->name('sideBoards.dreamlands');
    });

    Route::prefix('other')->name('other.')->group(function () {
        Route::get('/', [PageController::class, 'special'])->name('index');
        Route::get('/disaster', [PageController::class, 'disaster'])->name('disaster');
        Route::get('/disaster/city', [PageController::class, 'disasterCity'])->name('disaster.city');
        Route::get('/disaster/weather', [PageController::class, 'disasterWeather'])->name('disaster.weather');
        Route::get('/disaster/location', [PageController::class, 'disasterLocation'])->name('disaster.location');
        Route::get('/investigators', [PageController::class, 'investigators'])->name('investigators');
        Route::get('/ancient-ones', [PageController::class, 'ancientOnes'])->name('ancientOnes');
    });

    // ---- Legacy UI paths ----
    Route::get('/contacts/{path?}', static function (?string $path) {
        $target = '/encounters'.($path ? '/'.$path : '');
        $query = request()->getQueryString();
        return redirect($query ? "{$target}?{$query}" : $target);
    })->where('path', '.*');
    Route::get('/special/{path?}', static function (?string $path) {
        $target = '/other'.($path ? '/'.$path : '');
        $query = request()->getQueryString();
        return redirect($query ? "{$target}?{$query}" : $target);
    })->where('path', '.*');
    Route::get('/encounters/add-map/{path?}', static function (?string $path) {
        $target = '/encounters/side-boards'.($path ? '/'.$path : '');
        $query = request()->getQueryString();
        return redirect($query ? "{$target}?{$query}" : $target);
    })->where('path', '.*');

    // ---- Audio ----
    Route::get('/audio/folder/{slug}/random', [AudioController::class, 'pick'])
        ->where('slug', '.*')
        ->name('audio.pick');
    Route::get('/audio/track/{track}/stream', [AudioController::class, 'stream'])
        ->name('audio.stream');

    // ---- State ----
    Route::post('/state/ancient-one', [StateController::class, 'setAncientOne'])->name('state.ancientOne');
    Route::post('/state/blobs', [StateController::class, 'setBlobs'])->name('state.blobs.set');
    Route::delete('/state/blobs', [StateController::class, 'clearBlobs'])->name('state.blobs.clear');

    // ---- Profile ----
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
