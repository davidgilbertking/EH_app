<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LightingRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lighting.driver' => 'mock']);
        $this->freezeTime();
        $this->actingAs(User::factory()->create());
        config()->set('lighting.allowed_user_ids', [auth()->id()]);
    }

    public function test_status_polling_does_not_consume_write_limits(): void
    {
        for ($read = 0; $read < 120; $read++) {
            $this->getJson('/lighting/status')->assertOk();
        }

        $epoch = $this->postJson('/lighting/control', ['enabled' => true])
            ->assertOk()->assertHeader('X-RateLimit-Remaining', '29')->json('controlEpoch');
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->withCredentials();

        $this->postJson('/lighting/intents', [
            'intentId' => (string) Str::uuid(), 'controlEpoch' => $epoch,
            'clientSeq' => 1, 'target' => ['kind' => 'white', 'profile' => 'action'],
        ])->assertAccepted()->assertHeader('X-RateLimit-Remaining', '119');
    }

    public function test_exhausted_control_limit_does_not_consume_status_or_intent_limits(): void
    {
        $epoch = null;
        for ($write = 0; $write < 30; $write++) {
            $epoch = $this->postJson('/lighting/control', ['enabled' => true])
                ->assertOk()->json('controlEpoch');
            $this->withCookie(config('session.cookie'), session()->getId());
            $this->withCredentials();
        }

        $this->postJson('/lighting/control', ['enabled' => true])->assertTooManyRequests();
        $this->getJson('/lighting/status')->assertOk()->assertHeader('X-RateLimit-Remaining', '179');
        $this->postJson('/lighting/intents', [
            'intentId' => (string) Str::uuid(), 'controlEpoch' => $epoch,
            'clientSeq' => 1, 'target' => ['kind' => 'white', 'profile' => 'action'],
        ])->assertAccepted()->assertHeader('X-RateLimit-Remaining', '119');
    }
}
