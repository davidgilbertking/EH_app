<?php

namespace Tests\Feature;

use App\Lighting\CloudContinuousCoordinator;
use App\Lighting\CloudNativeCoordinator;
use App\Lighting\Drivers\CloudLightingDriver;
use App\Lighting\Drivers\LightingDriver;
use App\Lighting\Drivers\MockLightingDriver;
use App\Lighting\Drivers\NativeCloudLightingDriver;
use App\Lighting\LightingCoordinator;
use App\Lighting\LightingExecutor;
use App\Models\LightingState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LightingExecutorBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_simulation_never_resolves_a_physical_executor(): void
    {
        Http::preventStrayRequests();
        config(['lighting.driver' => 'mock', 'lighting.cloud.native_transitions' => true,
            'lighting.cloud.continuous_transitions' => true]);
        $this->assertInstanceOf(MockLightingDriver::class, app(LightingDriver::class));
        $this->assertInstanceOf(LightingCoordinator::class, app(LightingExecutor::class));
        Http::assertNothingSent();
    }

    public function test_native_cloud_requires_both_explicit_settings(): void
    {
        Http::preventStrayRequests();
        config(['lighting.driver' => 'cloud', 'lighting.cloud.native_transitions' => false]);
        $this->assertInstanceOf(CloudLightingDriver::class, app(LightingDriver::class));
        $this->assertInstanceOf(LightingCoordinator::class, app(LightingExecutor::class));
        config(['lighting.cloud.native_transitions' => true]);
        $this->assertInstanceOf(NativeCloudLightingDriver::class, app(LightingDriver::class));
        $this->assertInstanceOf(CloudNativeCoordinator::class, app(LightingExecutor::class));
        Http::assertNothingSent();
    }

    public function test_starting_native_worker_without_an_intent_only_updates_heartbeat(): void
    {
        Http::preventStrayRequests();
        config(['lighting.driver' => 'cloud', 'lighting.cloud.native_transitions' => true,
            'lighting.cloud.private_directory' => '/nonexistent-lighting-test-fixture']);
        $this->artisan('lighting:work', ['--once' => true])->assertSuccessful();
        $state = LightingState::findOrFail(1);
        $this->assertNotNull($state->worker_seen_ms);
        $this->assertFalse($state->enabled);
        $this->assertNull($state->desired_target);
        Http::assertNothingSent();
    }

    public function test_continuous_candidate_replaces_power_choreography_only_when_explicitly_enabled(): void
    {
        Http::preventStrayRequests();
        config(['lighting.driver' => 'cloud', 'lighting.cloud.native_transitions' => true,
            'lighting.cloud.continuous_transitions' => true]);
        $this->assertInstanceOf(CloudContinuousCoordinator::class, app(LightingExecutor::class));
        $capabilities = app(LightingDriver::class)->capabilities();
        $this->assertTrue($capabilities['continuousTransitions']);
        $this->assertTrue($capabilities['visualVerificationRequired']);
        $this->assertFalse($capabilities['onboardFades']);
        $this->assertFalse($capabilities['nativeSwitchGradient']);
        Http::assertNothingSent();
    }

    public function test_onboard_candidate_is_reported_without_sending_commands(): void
    {
        Http::preventStrayRequests();
        config(['lighting.driver' => 'cloud', 'lighting.cloud.continuous_transitions' => true,
            'lighting.cloud.onboard_fades' => true]);
        $this->assertInstanceOf(CloudContinuousCoordinator::class, app(LightingExecutor::class));
        $this->assertTrue(app(LightingDriver::class)->capabilities()['onboardFades']);
        Http::assertNothingSent();
    }

    public function test_continuous_worker_without_control_sends_nothing(): void
    {
        Http::preventStrayRequests();
        config(['lighting.driver' => 'cloud', 'lighting.cloud.continuous_transitions' => true,
            'lighting.cloud.private_directory' => '/nonexistent-lighting-test-fixture']);
        $this->artisan('lighting:work', ['--once' => true])->assertSuccessful();
        $this->assertNotNull(LightingState::findOrFail(1)->worker_seen_ms);
        Http::assertNothingSent();
    }
}
