<?php

namespace App\Lighting;

use App\Lighting\Drivers\LightingDriver;
use Illuminate\Support\Facades\DB;

class LightingStatus
{
    public function __construct(private LightingStore $store, private LightingDriver $driver) {}

    public function get(): array
    {
        $state = $this->store->state();
        $now = LightingStore::now();
        $expired = $state->enabled && $state->control_expires_ms <= $now;
        $online = $state->worker_seen_ms !== null && $now - $state->worker_seen_ms <= config('lighting.worker_timeout_ms');
        $pending = $state->enabled && $state->desired_target !== null && $state->revision !== $state->applied_revision;
        $observed = $state->observed;
        if ($observed !== null) {
            $observed['ageMs'] = max(0, $now - ($observed['observedAt'] ?? 0));
            if ($observed['ageMs'] > config('lighting.observation_max_age_ms')) {
                $observed['quality'] = 'unknown';
            }
        }

        return [
            'driver' => config('lighting.driver'), 'simulated' => config('lighting.driver') === 'mock',
            'enabled' => $state->enabled && ! $expired, 'controlGeneration' => $expired ? null : $state->control_generation,
            'controlExpiresAt' => $state->control_expires_ms,
            'revision' => $state->revision, 'appliedRevision' => $state->applied_revision,
            'statusVersion' => $state->status_version, 'serverTimestamp' => $now,
            'stage' => $expired ? 'cancelled' : ($pending && ! $online && $state->error === null ? 'offline' : $state->stage),
            'error' => $expired ? 'control_lost' : ($state->error ?? ($pending && ! $online ? 'worker_unavailable' : null)),
            'target' => $state->desired_target, 'observed' => $observed,
            'darkBarrierPending' => $state->color_barrier,
            'worker' => ['online' => $online, 'lastSeenAt' => $state->worker_seen_ms],
            'capabilities' => in_array(config('lighting.driver'), ['mock', 'cloud'], true) ? $this->driver->capabilities() : [],
            'journal' => DB::table('lighting_events')->orderByDesc('id')->limit(20)->get()->reverse()->values()->map(fn ($event) => [
                'id' => $event->id, 'timestamp' => $event->timestamp_ms,
                'intentId' => $event->intent_id, 'revision' => $event->revision,
                'stage' => $event->stage, 'operation' => $event->operation,
                'target' => $event->target === null ? null : json_decode($event->target, true),
                'simulated' => (bool) $event->simulated,
            ])->all(),
        ];
    }
}
