<?php

namespace App\Providers;

use App\Lighting\CloudNativeCoordinator;
use App\Lighting\Drivers\CloudLightingDriver;
use App\Lighting\Drivers\LightingDriver;
use App\Lighting\Drivers\MockLightingDriver;
use App\Lighting\Drivers\NativeCloudLightingDriver;
use App\Lighting\Drivers\TuyaCloudClient;
use App\Lighting\LightingCoordinator;
use App\Lighting\LightingExecutor;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LightingDriver::class, function () {
            if (config('lighting.driver') === 'cloud') {
                if (config('lighting.cloud.native_transitions')) {
                    return new NativeCloudLightingDriver(new TuyaCloudClient(config('lighting.cloud')));
                }

                return new CloudLightingDriver(
                    new TuyaCloudClient(config('lighting.cloud')),
                );
            }

            return new MockLightingDriver;
        });
        $this->app->bind(LightingExecutor::class, function ($app) {
            return $app->make(config('lighting.driver') === 'cloud' && config('lighting.cloud.native_transitions')
                ? CloudNativeCoordinator::class : LightingCoordinator::class);
        });
        $this->app->bind(NativeCloudLightingDriver::class, fn () => new NativeCloudLightingDriver(
            new TuyaCloudClient(config('lighting.cloud')),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
    }
}
