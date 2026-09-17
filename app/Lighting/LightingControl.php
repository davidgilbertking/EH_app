<?php

namespace App\Lighting;

use App\Models\LightingState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LightingControl
{
    public function __construct(private LightingStore $store) {}

    public function control(int $userId, string $sessionId, bool $enabled, ?string $epoch): array
    {
        LightingAccess::authorize($userId);

        return $this->store->atomic(function (LightingState $state) use ($userId, $sessionId, $enabled, $epoch) {
            if (! $enabled && ! $this->owns($state, $userId, $sessionId, $epoch)) {
                return ['accepted' => false, 'error' => 'control_lost', 'httpStatus' => 409];
            }
            $token = $enabled ? Str::random(64) : null;
            $state->enabled = $enabled;
            $state->owner_user_id = $enabled ? $userId : null;
            $state->owner_session_hash = $enabled ? $this->hash($sessionId) : null;
            $state->epoch_hash = $enabled ? $this->hash($token) : null;
            $state->control_generation = $enabled ? (string) Str::uuid() : null;
            $state->control_expires_ms = $enabled ? LightingStore::now() + max(1000, (int) config('lighting.control_lease_ms')) : null;
            $state->revision++;
            $state->client_seq = 0;
            $state->desired_target = null;
            $state->intent_id = null;
            $state->transition = null;
            $state->stage = $enabled ? 'idle' : 'cancelled';
            $state->error = null;
            $this->store->event($state, $enabled ? 'control_acquired' : 'control_released');

            return ['accepted' => true, 'controlEpoch' => $token, 'controlEpochGeneration' => $state->control_generation, 'httpStatus' => 200];
        });
    }

    public function intent(int $userId, string $sessionId, array $intent): array
    {
        LightingAccess::authorize($userId);

        return $this->store->atomic(function (LightingState $state) use ($userId, $sessionId, $intent) {
            if (! $this->owns($state, $userId, $sessionId, $intent['controlEpoch'])) {
                return ['accepted' => false, 'error' => 'control_lost', 'httpStatus' => 409];
            }
            $fingerprint = hash('sha256', json_encode([$intent['clientSeq'], $intent['target']], JSON_THROW_ON_ERROR));
            $previous = DB::table('lighting_intents')->where('intent_id', $intent['intentId'])->first();
            if ($previous) {
                if ($previous->control_generation !== $state->control_generation || $previous->fingerprint !== $fingerprint) {
                    return ['accepted' => false, 'error' => 'intent_conflict', 'httpStatus' => 409];
                }

                return ['accepted' => true, 'duplicate' => true, 'acceptedRevision' => $previous->revision, 'intentId' => $intent['intentId'], 'httpStatus' => 202];
            }
            if ($intent['clientSeq'] <= $state->client_seq) {
                return ['accepted' => false, 'error' => 'stale', 'httpStatus' => 200];
            }
            $state->client_seq = $intent['clientSeq'];
            $state->revision++;
            $state->intent_id = $intent['intentId'];
            $state->desired_target = $intent['target'];
            $state->error = null;
            // Preserve an in-flight transition and its dark barrier across intents.
            $state->stage = 'queued';
            DB::table('lighting_intents')->insert([
                'intent_id' => $intent['intentId'], 'control_generation' => $state->control_generation,
                'client_seq' => $intent['clientSeq'], 'revision' => $state->revision, 'fingerprint' => $fingerprint,
            ]);
            $this->store->prune('lighting_intents');
            $this->store->event($state, 'intent_accepted', $state->desired_target);

            return ['accepted' => true, 'duplicate' => false, 'acceptedRevision' => $state->revision, 'intentId' => $intent['intentId'], 'httpStatus' => 202];
        });
    }

    public function revokeSession(int $userId, string $sessionId): void
    {
        $this->store->atomic(function (LightingState $state) use ($userId, $sessionId) {
            if ($state->enabled && (int) $state->owner_user_id === $userId && hash_equals($state->owner_session_hash, $this->hash($sessionId))) {
                $state->enabled = false;
                $state->epoch_hash = null;
                $state->control_generation = null;
                $state->control_expires_ms = null;
                $state->owner_user_id = null;
                $state->owner_session_hash = null;
                $state->revision++;
                $state->desired_target = null;
                $state->transition = null;
                $state->stage = 'cancelled';
                $state->error = null;
                $this->store->event($state, 'session_revoked');
            }
        });
    }

    private function owns(LightingState $state, int $userId, string $sessionId, ?string $epoch): bool
    {
        return $state->enabled && $state->control_expires_ms > LightingStore::now()
            && (int) $state->owner_user_id === $userId && $epoch !== null
            && hash_equals($state->owner_session_hash, $this->hash($sessionId))
            && hash_equals($state->epoch_hash, $this->hash($epoch));
    }

    private function hash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
