<?php

namespace Tests\Feature;

use App\Lighting\LightingControl;
use App\Lighting\LightingDeviceBinding;
use App\Lighting\LightingExecutor;
use App\Models\LightingState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LightingDeviceBindingTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->directory = sys_get_temp_dir().'/eh-binding-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        file_put_contents($this->directory.'/cloud-functions-inspection.json', json_encode([
            'device_id' => 'captured_device_123', 'response' => ['success' => true, 'result' => ['functions' => []]],
        ], JSON_THROW_ON_ERROR));
        chmod($this->directory.'/cloud-functions-inspection.json', 0600);
        config(['lighting.driver' => 'cloud', 'lighting.cloud.device_id' => 'captured_device_123',
            'lighting.cloud.private_directory' => $this->directory,
            'lighting.cloud.native_transitions' => false, 'lighting.cloud.continuous_transitions' => false]);
    }

    protected function tearDown(): void
    {
        unlink($this->directory.'/cloud-functions-inspection.json');
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_legacy_same_device_keeps_every_existing_command_and_profile_file(): void
    {
        $this->oldMailbox();
        $before = LightingState::findOrFail(1)->getAttributes();
        $bytes = file_get_contents($this->directory.'/cloud-functions-inspection.json');

        app(LightingDeviceBinding::class)->synchronize();

        $after = LightingState::findOrFail(1)->getAttributes();
        $this->assertSame('captured_device_123', $after['cloud_device_id']);
        unset($before['cloud_device_id'], $after['cloud_device_id'], $before['status_version'], $after['status_version']);
        $this->assertSame($before, $after);
        $this->assertSame($bytes, file_get_contents($this->directory.'/cloud-functions-inspection.json'));
        $this->assertDatabaseMissing('lighting_events', ['operation' => 'device_binding_changed']);
        Http::assertNothingSent();
    }

    public function test_repeated_start_for_same_binding_does_not_touch_mailbox(): void
    {
        $this->oldMailbox();
        app(LightingDeviceBinding::class)->synchronize();
        $before = LightingState::findOrFail(1)->getAttributes();

        app(LightingDeviceBinding::class)->synchronize();

        $this->assertSame($before, LightingState::findOrFail(1)->getAttributes());
        Http::assertNothingSent();
    }

    #[DataProvider('executors')]
    public function test_changed_id_discards_old_commands_before_any_executor_can_recover(bool $native, bool $continuous): void
    {
        $user = User::factory()->create();
        config(['lighting.allowed_user_ids' => [$user->id]]);
        $control = app(LightingControl::class);
        $lease = $control->control($user->id, 'old-browser-session', true, null);
        $this->oldMailbox();
        $revision = LightingState::findOrFail(1)->revision;
        config(['lighting.cloud.device_id' => 'reconnected_device_456',
            'lighting.cloud.native_transitions' => $native, 'lighting.cloud.continuous_transitions' => $continuous]);

        $this->artisan('lighting:work', ['--once' => true])->assertSuccessful();

        $state = LightingState::findOrFail(1);
        $this->assertSame('reconnected_device_456', $state->cloud_device_id);
        $this->assertFalse($state->enabled);
        $this->assertSame($revision + 1, $state->revision);
        $this->assertTrue($state->color_barrier);
        foreach (['epoch_hash', 'owner_user_id', 'owner_session_hash', 'control_generation', 'control_expires_ms',
            'desired_target', 'intent_id', 'observed', 'transition', 'native_effect', 'read_revision',
            'applied_revision', 'dark_since_ms', 'error'] as $field) {
            $this->assertNull($state->$field, $field);
        }
        $this->assertNotNull($state->worker_seen_ms);
        $this->assertDatabaseHas('lighting_events', ['operation' => 'device_binding_changed']);
        $this->assertDatabaseHas('lighting_events', ['operation' => 'control_acquired']);
        $result = $control->intent($user->id, 'old-browser-session', [
            'intentId' => (string) Str::uuid(), 'controlEpoch' => $lease['controlEpoch'],
            'clientSeq' => 1, 'target' => ['kind' => 'white', 'profile' => 'action'],
        ]);
        $this->assertSame('control_lost', $result['error']);
        Http::assertNothingSent();
    }

    public static function executors(): array
    {
        return ['cloud' => [false, false], 'native' => [true, false], 'continuous' => [true, true]];
    }

    public function test_subsequent_changes_use_last_binding_including_return_to_original_id(): void
    {
        $this->oldMailbox();
        app(LightingDeviceBinding::class)->synchronize();
        config(['lighting.cloud.device_id' => 'reconnected_device_456']);
        app(LightingDeviceBinding::class)->synchronize();
        $this->oldMailbox();
        config(['lighting.cloud.device_id' => 'captured_device_123']);

        app(LightingDeviceBinding::class)->synchronize();

        $state = LightingState::findOrFail(1);
        $this->assertSame('captured_device_123', $state->cloud_device_id);
        $this->assertNull($state->native_effect);
        $this->assertFalse($state->enabled);
        $this->assertSame(2, DB::table('lighting_events')->where('operation', 'device_binding_changed')->count());
    }

    public function test_unconfigured_idle_worker_keeps_heartbeat_without_opening_profiles(): void
    {
        config(['lighting.cloud.device_id' => null, 'lighting.cloud.private_directory' => '/missing-binding-fixture']);

        $this->artisan('lighting:work', ['--once' => true])->assertSuccessful();

        $this->assertNotNull(LightingState::findOrFail(1)->worker_seen_ms);
        Http::assertNothingSent();
    }

    public function test_missing_legacy_identity_blocks_recovery_of_old_work(): void
    {
        $this->oldMailbox();
        config(['lighting.cloud.private_directory' => '/missing-binding-fixture']);
        $coordinator = $this->createMock(LightingExecutor::class);
        $coordinator->expects($this->never())->method('recover');
        $coordinator->expects($this->never())->method('tick');
        $this->app->instance(LightingExecutor::class, $coordinator);

        $this->artisan('lighting:work', ['--once' => true])->assertFailed();

        $this->assertNotNull(LightingState::findOrFail(1)->native_effect);
        Http::assertNothingSent();
    }

    public function test_another_live_worker_blocks_binding_change_until_restart(): void
    {
        $this->oldMailbox();
        config(['lighting.cloud.device_id' => 'reconnected_device_456']);
        $connection = DB::connection();
        $identity = $connection->getDriverName().':'.$connection->getConfig('host').':'.$connection->getDatabaseName();
        $lock = fopen(storage_path('framework/lighting-worker-'.hash('sha256', $identity).'.lock'), 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $this->artisan('lighting:work', ['--once' => true])->assertFailed();
            $this->assertNull(LightingState::findOrFail(1)->cloud_device_id);
            $this->assertNotNull(LightingState::findOrFail(1)->native_effect);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $this->artisan('lighting:work', ['--once' => true])->assertSuccessful();
        $this->assertSame('reconnected_device_456', LightingState::findOrFail(1)->cloud_device_id);
        Http::assertNothingSent();
    }

    public function test_mock_worker_does_not_rebind_cloud_mailbox(): void
    {
        config(['lighting.driver' => 'mock']);
        $before = LightingState::findOrFail(1)->getAttributes();

        app(LightingDeviceBinding::class)->synchronize();

        $this->assertSame($before, LightingState::findOrFail(1)->getAttributes());
    }

    private function oldMailbox(): void
    {
        LightingState::findOrFail(1)->update([
            'enabled' => true, 'revision' => 20, 'applied_revision' => 19,
            'desired_target' => ['kind' => 'mythos', 'color' => 'blue', 'mythosSessionId' => 'old-mythos'],
            'observed' => ['mode' => 'white', 'brightnessPct' => 70],
            'transition' => ['old-transition' => true],
            'native_effect' => ['engine' => 'continuous_v1', 'pending' => ['old-write' => true]],
            'read_revision' => 20, 'dark_since_ms' => 123, 'error' => 'outcome_unknown',
            'intent_id' => (string) Str::uuid(), 'stage' => 'queued',
        ]);
    }
}
