<?php

namespace App\Providers;

use App\Lighting\Drivers\CloudLightingDriver;
use App\Lighting\Drivers\LightingDriver;
use App\Lighting\Drivers\MockLightingDriver;
use App\Lighting\Drivers\TuyaCloudClient;
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
                return new CloudLightingDriver(
                    new TuyaCloudClient(config('lighting.cloud')),
                );
            }

            return new MockLightingDriver;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
    }
}
