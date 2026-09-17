<?php

namespace App\Lighting;

use App\Lighting\Drivers\LightingDriver;
use App\Models\LightingState;
use Illuminate\Support\Facades\DB;
use Throwable;

/** One mailbox, no FIFO jobs, and no timers in the HTTP or browser processes. */
class LightingCoordinator implements LightingExecutor
{
    public function __construct(private LightingStore $store, private LightingPlanner $planner, private LightingDriver $driver) {}

    public function recover(): void
    {
        $this->store->atomic(function (LightingState $state) {
            if ($state->enabled && $state->desired_target !== null && $state->applied_revision !== $state->revision) {
                // A previous process may have died between sending a command and
                // saving its acknowledgement. Read the device before resuming.
                $state->read_revision = null;
            }
        });
    }

    public function tick(?int $now = null): bool
    {
        $now ??= LightingStore::now();
        // OS lock has no TTL to expire while a sender is still alive. This deployment
        // supports a single worker host; a future remote transport needs fencing.
        $connection = DB::connection();
        $identity = $connection->getDriverName().':'.$connection->getConfig('host').':'.$connection->getDatabaseName();
        $handle = fopen(storage_path('framework/lighting-sender-'.hash('sha256', $identity).'.lock'), 'c');
        if ($handle === false || ! flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            return false;
        }
        try {
            return $this->step($now);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function step(int $now): bool
    {
        $snapshot = $this->store->atomic(function (LightingState $state) use ($now) {
            $state->worker_seen_ms = $now;
            $accessRevoked = $state->enabled && ! LightingAccess::allows($state->owner_user_id);
            if ($accessRevoked || ($state->enabled && $state->control_expires_ms <= $now)) {
                $state->enabled = false;
                $state->epoch_hash = null;
                $state->control_generation = null;
                $state->owner_user_id = null;
                $state->owner_session_hash = null;
                $state->control_expires_ms = null;
                $state->revision++;
                $state->desired_target = null;
                $state->transition = null;
                $state->stage = 'cancelled';
                $state->error = 'control_lost';
                $this->store->event($state, $accessRevoked ? 'control_access_revoked' : 'control_expired');
            }
            if (! $state->enabled || $state->desired_target === null || $state->error !== null || $state->applied_revision === $state->revision) {
                return null;
            }

            return $state->toArray();
        });
        if ($snapshot === null) {
            return false;
        }
        if (! in_array(config('lighting.driver'), ['mock', 'cloud'], true)) {
            $this->fail($snapshot, 'not_configured');

            return false;
        }

        try {
            $capabilities = $this->driver->capabilities();
            if (config('lighting.driver') === 'cloud' && ($capabilities['simulated'] ?? true) !== false) {
                $this->fail($snapshot, 'not_configured');

                return false;
            }
            if ($snapshot['read_revision'] !== $snapshot['revision']) {
                $ioStarted = hrtime(true);
                $observed = $this->driver->readState($snapshot['observed'], $now);
                $this->assertObservationSource($observed);
                $observed['observedAt'] = $now + (int) ceil((hrtime(true) - $ioStarted) / 1000000);
                $this->store->atomic(function (LightingState $state) use ($snapshot, $observed) {
                    if (! $this->current($state, $snapshot)) {
                        return;
                    }
                    $fields = array_flip(['mode', 'brightnessPct', 'temperaturePct', 'sceneLevel', 'color', 'rgbSuppressed']);
                    if (array_intersect_key($observed, $fields) != array_intersect_key($snapshot['observed'] ?? [], $fields)) {
                        // Manual edits and late device effects invalidate the old
                        // interpolation origin. Continue from what is actually read.
                        $state->transition = null;
                        $state->dark_since_ms = null;
                    }
                    $state->observed = $observed;
                    $state->read_revision = $state->revision;
                    // An external scene, or an unknown observation, must be fenced.
                    if (! $this->planner->isWhiteOrigin($observed)) {
                        $state->color_barrier = true;
                    }
                    if (! $this->planner->isDark($observed)) {
                        $state->dark_since_ms = null;
                    } elseif ($state->dark_since_ms === null) {
                        $state->dark_since_ms = $observed['observedAt'];
                    }
                });

                return true;
            }

            $plan = $this->planner->next($snapshot, $now, config('lighting'));
            if (($plan['requiresSafeSceneExit'] ?? false) && ($capabilities['safeSceneExit'] ?? false) !== true) {
                throw new \RuntimeException('unsupported_transition');
            }
            $ready = $this->store->atomic(function (LightingState $state) use ($snapshot, $plan) {
                if (! $this->current($state, $snapshot)) {
                    return false;
                }
                $state->stage = $plan['stage'];
                $state->transition = $plan['transition'];
                if (($plan['command']['operation'] ?? null) === 'scene') {
                    // Commit BEFORE anything that might enable RGB. This survives
                    // process death, timeout, unlink and a newer desired revision.
                    $state->color_barrier = true;
                    $state->dark_since_ms = null;
                }
                if (! isset($plan['command']) && $plan['complete']) {
                    $state->applied_revision = $state->revision;
                }

                return true;
            });
            if (! $ready || ! isset($plan['command'])) {
                return $ready;
            }

            // Re-read after preparation and immediately before the next side effect.
            if (! $this->current($this->store->state(), $snapshot)) {
                return false;
            }
            $ioStarted = hrtime(true);
            $observed = $this->driver->execute($plan['command'], $snapshot['observed'], $now);
            $this->assertObservationSource($observed);
            $completedAt = $now + (int) ceil((hrtime(true) - $ioStarted) / 1000000);
            $observed['observedAt'] = $completedAt;
            $this->store->atomic(function (LightingState $state) use ($snapshot, $plan, $observed, $completedAt) {
                if (! $this->current($state, $snapshot)) {
                    // A late acknowledgement cannot prove the new revision's state.
                    // Preserve its target, barrier and appliedRevision. Next tick reads.
                    $state->read_revision = null;
                    // A late step can still have changed the physical device.
                    // Keep its values only as an unconfirmed origin, never revive
                    // an older stored Dark observation or confirm the new goal.
                    $state->observed = array_merge($observed, [
                        'quality' => 'unknown', 'outputSettled' => false,
                        'completion' => 'late_acknowledgement',
                    ]);
                    $late = new LightingState($snapshot);
                    $late->stage = 'cancelled';
                    $this->store->event($late, 'late_acknowledgement', $plan['command']);

                    return;
                }
                if (($plan['complete'] || ($plan['darkReached'] ?? false))
                    && ! $this->matchesCompletion($plan['command'], $observed)) {
                    throw new \RuntimeException(($plan['darkReached'] ?? false) ? 'unsupported_transition' : 'transport_timeout');
                }
                $state->observed = $observed;
                $state->stage = $plan['afterStage'] ?? $plan['stage'];
                if ($plan['darkReached'] ?? false) {
                    if (! $this->planner->isDark($observed)) {
                        $state->error = 'unsupported_transition';
                        $state->stage = 'error';

                        return;
                    }
                    $state->color_barrier = false;
                    $state->dark_since_ms = $completedAt;
                }
                if ($plan['complete']) {
                    $state->applied_revision = $state->revision;
                }
                $this->store->event($state, $plan['command']['operation'], $plan['command']);
            });

            return true;
        } catch (Throwable $exception) {
            // Never expose driver exception messages, payloads or credentials.
            $code = in_array($exception->getMessage(), ['offline', 'transport_timeout', 'preset_not_configured', 'unsupported_transition', 'configuration_error'], true)
                ? $exception->getMessage() : 'configuration_error';
            $this->fail($snapshot, $code);

            return false;
        }
    }

    private function current(LightingState $state, array $snapshot): bool
    {
        return $state->enabled && $state->revision === $snapshot['revision']
            && LightingAccess::allows($state->owner_user_id)
            && $state->control_expires_ms > LightingStore::now()
            && $state->control_generation === $snapshot['control_generation'];
    }

    private function matchesCompletion(array $command, array $observed): bool
    {
        if (! $this->planner->isSettled($observed)) {
            return false;
        }

        return match ($command['operation']) {
            'dark_anchor' => $this->planner->isDark($observed),
            'white' => ($observed['mode'] ?? null) === 'white'
                && ($observed['rgbSuppressed'] ?? false) === true
                && abs(($observed['brightnessPct'] ?? -1) - $command['brightnessPct']) < 0.00001
                && abs(($observed['temperaturePct'] ?? -1) - $command['temperaturePct']) < 0.00001,
            'scene' => ($observed['mode'] ?? null) === 'scene'
                && ($observed['color'] ?? null) === $command['color']
                && ($observed['mythosSessionId'] ?? null) === $command['mythosSessionId']
                && ($observed['sceneLevel'] ?? 0) >= 1.0,
            default => false,
        };
    }

    private function assertObservationSource(array $observed): void
    {
        if (config('lighting.driver') === 'cloud' && ($observed['quality'] ?? null) === 'simulated') {
            throw new \RuntimeException('configuration_error');
        }
    }

    private function fail(array $snapshot, string $code): void
    {
        $this->store->atomic(function (LightingState $state) use ($snapshot, $code) {
            if (! $this->current($state, $snapshot)) {
                // A timed-out older write may still arrive. The latest target
                // survives, but must reconcile before another physical command.
                $state->read_revision = null;
                $state->color_barrier = true;
                if ($state->observed !== null) {
                    $state->observed = array_merge($state->observed, ['quality' => 'unknown', 'outputSettled' => false, 'completion' => 'transport_uncertain']);
                }

                return;
            }
            $state->error = $code;
            $state->stage = $code === 'offline' ? 'offline' : 'error';
            $state->transition = null;
            $state->read_revision = null;
            $state->color_barrier = true;
            if ($state->observed !== null) {
                $state->observed = array_merge($state->observed, ['quality' => 'unknown', 'outputSettled' => false, 'completion' => 'transport_uncertain']);
            }
            $this->store->event($state, 'failed');
        });
    }
}
