<?php

namespace Tests\Feature;

use App\Lighting\LightingControl;
use App\Lighting\LightingCoordinator;
use App\Models\LightingState;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LightingAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_allowlist_denies_access_even_to_account_four(): void
    {
        $owner = User::factory()->create(['id' => 4]);
        $this->assertSame([], config('lighting.allowed_user_ids'));
        $this->actingAs($owner)->getJson('/lighting/status')->assertForbidden();
        $this->postJson('/lighting/control', ['enabled' => true])->assertForbidden();
        $this->get('/mythos')->assertRedirect('/');
        $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page->where('lighting.canControl', false));
        $this->assertSame(0, LightingState::findOrFail(1)->revision);
    }

    public function test_page_permission_and_mythos_access_follow_the_configured_account(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        config()->set('lighting.allowed_user_ids', [(string) $owner->id]);
        config()->set('lighting.scene_fade_out_ms', 2750);

        $this->actingAs($owner)->get('/mythos')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Mythos/Index')->where('lighting.canControl', true)
            ->where('lighting.sceneFadeOutMs', 2750));
        $this->getJson('/lighting/status')->assertOk();

        $this->actingAs($other)->get('/')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Home')->where('lighting.canControl', false));
        $this->get('/encounters')->assertOk();
        $this->get('/other')->assertOk();
        $this->get('/mythos')->assertRedirect('/');
    }

    public function test_other_account_cannot_read_take_over_release_or_submit_an_intent(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        config()->set('lighting.allowed_user_ids', [$owner->id]);
        $control = app(LightingControl::class);
        $epoch = $control->control($owner->id, 'owner-session', true, null)['controlEpoch'];
        $intent = [
            'intentId' => (string) Str::uuid(), 'controlEpoch' => $epoch,
            'clientSeq' => 1, 'target' => ['kind' => 'white', 'profile' => 'action'],
        ];
        $control->intent($owner->id, 'owner-session', $intent);
        $before = LightingState::findOrFail(1)->getAttributes();
        $events = DB::table('lighting_events')->count();

        $this->actingAs($other)->getJson('/lighting/status')->assertForbidden()->assertJsonMissingPath('desiredTarget');
        $this->postJson('/lighting/control', ['enabled' => true])->assertForbidden();
        $this->postJson('/lighting/control', ['enabled' => false, 'controlEpoch' => $epoch])->assertForbidden();
        $this->postJson('/lighting/intents', $intent)->assertForbidden();
        // Authorization runs before payload validation, including stale browser clients.
        $this->postJson('/lighting/intents', [])->assertForbidden();

        $this->assertSame($before, LightingState::findOrFail(1)->getAttributes());
        $this->assertDatabaseCount('lighting_events', $events);
        $this->assertDatabaseCount('lighting_intents', 1);
    }

    public static function controlOperations(): array
    {
        return [['control'], ['intent']];
    }

    #[DataProvider('controlOperations')]
    public function test_control_service_also_denies_unauthorized_callers(string $operation): void
    {
        $other = User::factory()->create();
        $control = app(LightingControl::class);
        try {
            if ($operation === 'control') {
                $control->control($other->id, 'other-session', true, null);
            } else {
                $control->intent($other->id, 'other-session', []);
            }
            $this->fail('An unlisted account must not access the lighting service.');
        } catch (AuthorizationException) {
            $this->assertSame(0, LightingState::findOrFail(1)->revision);
            $this->assertDatabaseCount('lighting_events', 0);
            $this->assertDatabaseCount('lighting_intents', 0);
        }
    }

    public function test_worker_cancels_an_existing_lease_when_its_owner_loses_access(): void
    {
        $owner = User::factory()->create();
        config()->set('lighting.allowed_user_ids', [$owner->id]);
        $control = app(LightingControl::class);
        $epoch = $control->control($owner->id, 'owner-session', true, null)['controlEpoch'];
        $control->intent($owner->id, 'owner-session', [
            'intentId' => (string) Str::uuid(), 'controlEpoch' => $epoch,
            'clientSeq' => 1, 'target' => ['kind' => 'white', 'profile' => 'action'],
        ]);
        config()->set('lighting.allowed_user_ids', []);

        $this->assertFalse(app(LightingCoordinator::class)->tick());
        $state = LightingState::findOrFail(1);
        $this->assertFalse($state->enabled);
        $this->assertNull($state->owner_user_id);
        $this->assertNull($state->desired_target);
        $this->assertNull($state->observed);
        $this->assertSame('cancelled', $state->stage);
        $this->assertDatabaseHas('lighting_events', ['operation' => 'control_access_revoked']);
    }
}
