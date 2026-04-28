<?php

use App\Http\Controllers\AudioController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\StateController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    // ---- Pages ----
    Route::get('/', [PageController::class, 'home'])->name('home');

    Route::prefix('contacts')->name('contacts.')->group(function () {
        Route::get('/', [PageController::class, 'contacts'])->name('index');
        Route::get('/general', [PageController::class, 'general'])->name('general');
        Route::get('/general/city', [PageController::class, 'generalCity'])->name('general.city');
        Route::get('/general/wilderness', [PageController::class, 'wilderness'])->name('general.wilderness');
        Route::get('/general/sea', [PageController::class, 'generalSea'])->name('general.sea');
        Route::get('/obstruction', [PageController::class, 'obstruction'])->name('obstruction');
        Route::get('/big-city', [PageController::class, 'bigCity'])->name('bigCity');
        Route::get('/outer-world', [PageController::class, 'outerWorld'])->name('outerWorld');
        Route::get('/outer-world/past', [PageController::class, 'past'])->name('outerWorld.past');
        Route::get('/outer-world/future', [PageController::class, 'future'])->name('outerWorld.future');
        Route::get('/defeated', [PageController::class, 'defeated'])->name('defeated');
        Route::get('/expedition', [PageController::class, 'expedition'])->name('expedition');
        Route::get('/expedition/expeditions', [PageController::class, 'expeditions'])->name('expedition.expeditions');
        Route::get('/add-map', [PageController::class, 'addMap'])->name('addMap');
        Route::get('/add-map/antarctica', [PageController::class, 'antarctica'])->name('addMap.antarctica');
        Route::get('/add-map/egypt', [PageController::class, 'egypt'])->name('addMap.egypt');
        Route::get('/add-map/dreamlands', [PageController::class, 'dreamlands'])->name('addMap.dreamlands');
    });

    Route::prefix('special')->name('special.')->group(function () {
        Route::get('/', [PageController::class, 'special'])->name('index');
        Route::get('/disaster', [PageController::class, 'disaster'])->name('disaster');
        Route::get('/disaster/city', [PageController::class, 'disasterCity'])->name('disaster.city');
        Route::get('/disaster/weather', [PageController::class, 'disasterWeather'])->name('disaster.weather');
        Route::get('/disaster/location', [PageController::class, 'disasterLocation'])->name('disaster.location');
        Route::get('/investigators', [PageController::class, 'investigators'])->name('investigators');
        Route::get('/ancient-ones', [PageController::class, 'ancientOnes'])->name('ancientOnes');
    });

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
