<?php

namespace Tests\Feature;

use App\Models\LightingState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExpandedLightingTargetsTest extends TestCase
{
    use RefreshDatabase;

    private string $epoch;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create());
        config(['lighting.allowed_user_ids' => [auth()->id()]]);
        $this->epoch = $this->postJson('/lighting/control', ['enabled' => true])->assertOk()->json('controlEpoch');
        $this->withCookie(config('session.cookie'), session()->getId());
        $this->withCredentials();
    }

    private function intent(array $target): array
    {
        return ['intentId' => (string) Str::uuid(), 'controlEpoch' => $this->epoch,
            'clientSeq' => ++$this->sequence, 'target' => $target];
    }

    public function test_every_catalog_profile_is_accepted_in_its_configured_mode_without_device_commands(): void
    {
        foreach (config('lighting.presets') as $alias => $profile) {
            $target = ['kind' => $profile['kind'], 'profile' => $alias];
            $this->postJson('/lighting/intents', $this->intent($target))->assertAccepted();
            $this->assertSame($target, LightingState::findOrFail(1)->desired_target);
        }
        Http::assertNothingSent();
    }

    public function test_profile_modes_and_logical_target_boundaries_are_enforced(): void
    {
        foreach ([
            ['kind' => 'white', 'profile' => 'detained'],
            ['kind' => 'colour', 'profile' => 'future-1'],
            ['kind' => 'colour', 'profile' => 'future-6'],
            ['kind' => 'colour', 'profile' => 'action-muted'],
            ['kind' => 'colour', 'profile' => 'arbitrary'],
            ['kind' => 'white', 'profile' => 'dark'],
            ['kind' => 'colour', 'profile' => 'detained', 'hsv' => ['h' => 1, 's' => 1, 'v' => 1]],
            ['kind' => 'colour', 'profile' => 'detained', 'color' => 'blue'],
            ['kind' => 'colour', 'profile' => 'detained', 'mythosSessionId' => (string) Str::uuid()],
            ['kind' => 'colour'],
            ['kind' => 'mythos', 'profile' => 'detained', 'color' => 'blue', 'mythosSessionId' => (string) Str::uuid()],
        ] as $target) {
            $this->postJson('/lighting/intents', $this->intent($target))->assertUnprocessable();
        }
        $this->assertDatabaseCount('lighting_intents', 0);
        $this->assertNull(LightingState::findOrFail(1)->desired_target);
        Http::assertNothingSent();
    }

    public function test_colour_profile_retries_keep_idempotency_and_reject_changed_payloads(): void
    {
        $intent = $this->intent(['profile' => 'detained', 'kind' => 'colour']);
        $this->postJson('/lighting/intents', $intent)->assertAccepted();
        $intent['target'] = ['kind' => 'colour', 'profile' => 'detained'];
        $this->postJson('/lighting/intents', $intent)->assertAccepted()->assertJsonPath('duplicate', true);
        $intent['target']['profile'] = 'lost';
        $this->postJson('/lighting/intents', $intent)->assertConflict()->assertJsonPath('error', 'intent_conflict');
        $this->assertDatabaseCount('lighting_intents', 1);
    }
}
