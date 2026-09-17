<?php

namespace App\Lighting;

use App\Lighting\Drivers\NativeCloudLightingDriver;
use App\Lighting\Drivers\TuyaCloudException;
use App\Models\LightingState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Durable, one-write-at-a-time cloud choreography. No sleep or write retries. */
class CloudNativeCoordinator implements LightingExecutor
{
    public function __construct(private LightingStore $store, private NativeCloudLightingDriver $driver) {}

    public function recover(): void
    {
        $this->store->atomic(function (LightingState $state) {
            // An attempted command survives restart, control release and new intent.
            // Only fresh readback can resolve it; never recreate its POST.
            $state->read_revision = null;
        });
    }

    public function tick(?int $now = null): bool
    {
        $now ??= LightingStore::now();
        $connection = DB::connection();
        $identity = $connection->getDriverName().':'.$connection->getConfig('host').':'.$connection->getDatabaseName();
        $lock = fopen(storage_path('framework/lighting-sender-'.hash('sha256', $identity).'.lock'), 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            return false;
        }
        try {
            return $this->step($now);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function step(int $now): bool
    {
        $state = $this->store->atomic(function (LightingState $state) use ($now) {
            $state->worker_seen_ms = $now;
            $accessRevoked = $state->enabled && ! LightingAccess::allows($state->owner_user_id);
            if ($accessRevoked || ($state->enabled && $state->control_expires_ms <= $now)) {
                $state->enabled = false;
                $state->epoch_hash = $state->control_generation = null;
                $state->owner_user_id = $state->owner_session_hash = null;
                $state->control_expires_ms = null;
                $state->desired_target = $state->transition = null;
                $state->revision++;
                $state->stage = 'cancelled';
                $state->error = 'control_lost';
                $this->store->event($state, $accessRevoked ? 'control_access_revoked' : 'control_expired');
            }

            return $state->toArray();
        });
        if (config('lighting.driver') !== 'cloud' || ! config('lighting.cloud.native_transitions', false)) {
            return false;
        }
        $effect = $state['native_effect'];
        try {
            // Even with no owner, reconciliation is read-only and cannot enable light.
            if ($effect !== null && ($effect['pending'] ?? null) !== null) {
                return $this->reconcile($effect, $now);
            }
            if ($effect !== null && ! $this->sameIntent($state, $effect) && $this->active($state, $now)
                && $effect['target'] === $state['desired_target'] && $effect['flow'] !== 'barrier') {
                // Repeated intent for the same destination adopts the running effect.
                $effect['revision'] = $state['revision'];
                $effect['generation'] = $state['control_generation'];
                $this->saveEffect($effect);
            }
            if ($effect !== null && ($effect['wait'] ?? null) !== null) {
                if (($effect['wait']['interruptibleOn'] ?? false) && ! $this->sameIntent($state, $effect)
                    && $this->active($state, $now) && config('lighting.cloud.native_interruptions', false)) {
                    $effect['flow'] = 'barrier';
                    $effect['revision'] = $state['revision'];
                    $effect['generation'] = $state['control_generation'];
                    $effect['target'] = $state['desired_target'];
                    $effect['profile'] = 'dark';
                    $effect['index'] = 0;
                    $effect['wait'] = null;
                    $effect['steps'] = $this->barrierSteps(true, false);
                    $this->saveEffect($effect);

                    return true;
                }

                return $this->finishWait($effect, $now);
            }
            if (! $this->active($state, $now) || $state['error'] !== null) {
                return false;
            }
            if ($effect !== null && ! $this->sameIntent($state, $effect)) {
                if ($effect['flow'] === 'barrier') {
                    // The shared Dark destination does not carry an old scene or ON target.
                    $effect['revision'] = $state['revision'];
                    $effect['generation'] = $state['control_generation'];
                    $effect['target'] = $state['desired_target'];
                    $this->saveEffect($effect);
                } else {
                    $this->saveEffect(null, $effect['id']);

                    return true;
                }
            }
            if ($effect === null) {
                if ($state['applied_revision'] === $state['revision']) {
                    return false;
                }

                return $this->begin($state, $now);
            }
            if ($effect['flow'] === 'white_fade') {
                return $this->fade($effect, $now);
            }
            if (! isset($effect['steps'][$effect['index']])) {
                return $this->complete($effect, $now);
            }

            return $this->dispatch($effect, $effect['steps'][$effect['index']], $now);
        } catch (Throwable $error) {
            $this->fail($effect, $error, $state);

            return false;
        }
    }

    private function begin(array $state, int $now): bool
    {
        $snapshot = $this->driver->readSnapshot();
        $values = $snapshot['values'];
        $target = $state['desired_target'];
        $profile = $target['kind'] === 'white' ? $target['profile'] : 'dark';
        $profiles = $this->driver->profiles();
        if (! isset($profiles[$profile])) {
            throw new TuyaCloudException('preset_not_configured');
        }
        $effect = ['id' => (string) Str::uuid(), 'revision' => $state['revision'],
            'generation' => $state['control_generation'], 'target' => $target,
            'flow' => 'barrier', 'index' => 0, 'steps' => [], 'pending' => null, 'wait' => null,
            'snapshot' => $snapshot, 'profile' => $profile];
        $margin = max(0, (int) config('lighting.cloud.settle_margin_ms', 400));
        if ($values[20] && $values[21] === 'scene' && $target['kind'] === 'mythos' && $target['color'] !== null
            && ($values[25] ?? null) === ($this->driver->scenes()[$target['color']]['raw'] ?? null)
            && ($state['observed']['outputSettled'] ?? false) === true
            && ($state['observed']['completion'] ?? null) === 'cloud_readback'
            && ($state['observed']['mythosSessionId'] ?? null) === $target['mythosSessionId']) {
            $effect['flow'] = 'target';
        } elseif ($values[20] === false || $values[21] !== 'white'
            || ($target['kind'] === 'mythos' && ! $this->matchesWhite($snapshot, $profiles['dark']))) {
            if ($values[20] && $values[21] !== 'white'
                && ($values[21] !== 'scene' || ! $this->knownScene($values[25] ?? null))) {
                throw new TuyaCloudException('unsupported_transition');
            }
            $effect['steps'] = $this->barrierSteps($values[20], $values[21] === 'scene');
        } elseif ($this->matchesWhite($snapshot, $profiles['dark'])
            && ($state['observed']['completion'] ?? null) !== 'cloud_accepted') {
            if ($target['kind'] === 'white') {
                $effect['flow'] = 'native_white';
                $up = max(0, (int) config('lighting.white_fade_up_ms', 12000));
                $effect['steps'] = [$this->gradientStep($up, 800),
                    ['operation' => 'power', 'on' => false, 'waitMs' => 800 + $margin],
                    ['operation' => 'prepare_white', 'profile' => $profile],
                    ['operation' => 'power', 'on' => true, 'waitMs' => $up + $margin, 'interruptibleOn' => true]];
            } elseif ($state['dark_since_ms'] !== null && ! $state['color_barrier']
                && ($state['observed']['outputSettled'] ?? false) === true) {
                $effect['flow'] = 'target';
                $effect['steps'] = $target['color'] === null ? [] : [['operation' => 'scene', 'color' => $target['color']]];
                if ($now < $state['dark_since_ms'] + config('lighting.dark_hold_ms', 150)) {
                    $effect['wait'] = ['notBefore' => $state['dark_since_ms'] + config('lighting.dark_hold_ms', 150),
                        'expected' => $this->whiteValues($profiles['dark'], true)];
                }
            } else {
                $effect['steps'] = [['operation' => 'endpoint_white', 'profile' => 'dark', 'waitMs' => 800 + $margin]];
            }
        } else {
            $effect['flow'] = 'white_fade';
            $origin = ($state['observed']['mode'] ?? null) === 'white'
                && ($state['observed']['completion'] ?? null) === 'cloud_accepted'
                ? $state['observed'] : $this->driver->observationFromSnapshot($snapshot, $now);
            $end = $profiles[$profile];
            $direction = $end['brightnessPct'] >= $origin['brightnessPct'] ? 'up' : 'down';
            $fraction = max(abs($end['brightnessPct'] - $origin['brightnessPct']) / 99,
                abs($end['temperaturePct'] - $origin['temperaturePct']) / 100);
            $effect['fade'] = ['startedAt' => $now, 'duration' => (int) round(config('lighting.white_fade_'.$direction.'_ms', 4000) * $fraction),
                'from' => $origin, 'to' => $end, 'nextAt' => $now, 'endpoint' => false];
        }
        $this->store->atomic(function (LightingState $fresh) use ($state, $effect, $snapshot, $now) {
            if (! $this->current($fresh, $state, $now) || $fresh->native_effect !== null) {
                return;
            }
            $fresh->native_effect = $effect;
            if (($fresh->observed['completion'] ?? null) !== 'cloud_accepted') {
                $fresh->observed = $this->driver->observationFromSnapshot($snapshot, $now);
            }
            $fresh->read_revision = $fresh->revision;
            $fresh->stage = 'queued';
            if ($snapshot['values'][21] !== 'white' || ! $snapshot['values'][20]) {
                $fresh->color_barrier = true;
                $fresh->dark_since_ms = null;
            }
        });

        return true;
    }

    private function dispatch(array $effect, array $descriptor, int $now): bool
    {
        $snapshot = $this->driver->readSnapshot();
        $source = $effect['snapshot']['values'];
        if (($source[21] ?? null) !== 'scene') {
            unset($source[25]);
        }
        if (! $this->matches($snapshot, $source)) {
            throw new TuyaCloudException('unsupported_transition');
        }
        if ($descriptor['operation'] === 'gradient'
            && $snapshot['values'][35] === ['onMs' => $descriptor['onMs'], 'offMs' => $descriptor['offMs']]) {
            $effect['index']++;
            $effect['snapshot'] = $snapshot;
            $this->saveEffect($effect);

            return true;
        }
        $expected = $snapshot['values'];
        unset($expected[25]); // An inactive stored scene is not evidence of current RGB.
        [$changed, $fresh] = match ($descriptor['operation']) {
            'gradient' => [[35 => ['onMs' => $descriptor['onMs'], 'offMs' => $descriptor['offMs']]], [35]],
            'power' => [[20 => $descriptor['on']], [20]],
            'prepare_white', 'endpoint_white' => [[21 => 'white', 22 => $this->driver->profiles()[$descriptor['profile']]['brightness'],
                23 => $this->driver->profiles()[$descriptor['profile']]['temperature']], [21, 22, 23]],
            'scene' => [[21 => 'scene', 25 => $this->driver->scenes()[$descriptor['color']]['raw']], [21, 25]],
            'realtime_white' => [[], []],
            default => throw new TuyaCloudException('configuration_error'),
        };
        $expected = array_replace($expected, $changed);
        $pending = ['id' => (string) Str::uuid(), 'descriptor' => $descriptor,
            'expected' => $expected, 'freshFields' => $fresh, 'beforeTimes' => $snapshot['times'],
            'attemptedAt' => $now, 'deadline' => $now + 15000, 'receipt' => null];
        $armed = $this->store->atomic(function (LightingState $state) use ($effect, $pending, $now) {
            if (! $this->currentEffect($state, $effect) || ! $this->sameIntent($state->toArray(), $effect)
                || ! $this->active($state->toArray(), $now)) {
                return false;
            }
            $saved = $state->native_effect;
            $saved['pending'] = $pending;
            $state->native_effect = $saved;
            $state->stage = $this->stage($effect, $pending['descriptor']);
            $state->observed = array_merge($state->observed ?? [], ['quality' => 'commanded', 'outputSettled' => false,
                'completion' => 'cloud_pending', 'physicalConfirmed' => false]);
            if ($pending['descriptor']['operation'] === 'scene') {
                $state->color_barrier = true;
                $state->dark_since_ms = null;
            }

            return true;
        });
        if (! $armed) {
            return false;
        }
        $freshState = $this->store->state();
        if (! $this->sameIntent($freshState->toArray(), $effect) || ! $this->active($freshState->toArray(), max($now, LightingStore::now()))) {
            // This process knows send has not run; clear only this prepared attempt.
            $this->saveEffect(null, $effect['id']);

            return false;
        }
        try {
            $receipt = $this->driver->issue($descriptor + ['sourceSnapshot' => $snapshot]);
            if (! is_int($receipt['sentAt'] ?? null)) {
                throw new TuyaCloudException('transport_timeout', writeOutcomeUnknown: true);
            }
            $this->store->atomic(function (LightingState $state) use ($effect, $pending, $receipt) {
                $saved = $state->native_effect;
                if (($saved['id'] ?? null) !== $effect['id'] || ($saved['pending']['id'] ?? null) !== $pending['id']) {
                    return;
                }
                // Save the receipt even if an HTTP intent changed ownership meanwhile.
                $saved['pending']['receipt'] = $receipt;
                $state->native_effect = $saved;
            });
        } catch (Throwable $error) {
            if ($error instanceof TuyaCloudException && ! $error->writeOutcomeUnknown) {
                // Driver/client explicitly failed before POST. A later explicit
                // intent may try again; this tick still ends in an error.
                $this->store->atomic(function (LightingState $state) use ($effect, $pending) {
                    $saved = $state->native_effect;
                    if (($saved['id'] ?? null) === $effect['id'] && ($saved['pending']['id'] ?? null) === $pending['id']) {
                        $saved['pending'] = null;
                        $state->native_effect = $saved;
                    }
                });
            }
            $this->fail($effect, $error, null,
                $error instanceof TuyaCloudException && ! $error->writeOutcomeUnknown);
        }

        return true;
    }

    private function reconcile(array $effect, int $now): bool
    {
        $pending = $effect['pending'];
        $realtime = $pending['descriptor']['operation'] === 'realtime_white';
        if ($realtime) {
            if ($pending['receipt'] === null) {
                throw new TuyaCloudException('transport_timeout', writeOutcomeUnknown: true);
            }
            // An ACK proves acceptance only. DP22/23 can still contain the old target.
            $effect['pending'] = null;
            $effect['fade']['nextAt'] = $now + max(300, (int) config('lighting.cloud.frame_interval_ms', 300));
            $this->store->atomic(function (LightingState $state) use ($effect, $pending, $now) {
                if (! $this->currentEffect($state, $effect)) {
                    return;
                }
                $state->native_effect = $effect;
                $state->observed = array_merge($state->observed ?? [], [
                    'mode' => 'white', 'brightnessPct' => $pending['descriptor']['brightnessPct'],
                    'temperaturePct' => $pending['descriptor']['temperaturePct'], 'rgbSuppressed' => true,
                    'quality' => 'commanded', 'outputSettled' => false, 'completion' => 'cloud_accepted',
                    'physicalConfirmed' => false, 'observedAt' => $now,
                ]);
            });

            return true;
        }
        $snapshot = $this->driver->readSnapshot();
        $sentAt = $pending['receipt']['sentAt'] ?? $pending['attemptedAt'];
        $fresh = false;
        foreach ($pending['freshFields'] as $dp) {
            $fresh = $fresh || (($snapshot['times'][$dp] ?? 0) >= $sentAt
                && ($snapshot['times'][$dp] ?? 0) > ($pending['beforeTimes'][$dp] ?? 0));
        }
        if (! $fresh || ! $this->matches($snapshot, $pending['expected'])) {
            if ($now >= $pending['deadline']) {
                throw new TuyaCloudException('transport_timeout', writeOutcomeUnknown: true);
            }

            return false;
        }
        $effect['snapshot'] = $snapshot;
        $effect['pending'] = null;
        $effect['index']++;
        if (($pending['descriptor']['waitMs'] ?? 0) > 0) {
            $effect['wait'] = ['notBefore' => $now + $pending['descriptor']['waitMs'], 'expected' => $pending['expected'],
                'interruptibleOn' => $pending['descriptor']['interruptibleOn'] ?? false];
        }
        if ($effect['flow'] === 'white_fade') {
            $effect['fade']['endpoint'] = true;
        }
        $this->saveEffect($effect, null, true);

        return true;
    }

    private function finishWait(array $effect, int $now): bool
    {
        if ($now < $effect['wait']['notBefore']) {
            return false;
        }
        $snapshot = $this->driver->readSnapshot();
        if (! $this->matches($snapshot, $effect['wait']['expected'])) {
            throw new TuyaCloudException('unsupported_transition');
        }
        $effect['snapshot'] = $snapshot;
        $effect['wait'] = null;
        $this->saveEffect($effect);

        return true;
    }

    private function fade(array $effect, int $now): bool
    {
        $fade = $effect['fade'];
        if ($fade['endpoint']) {
            return $this->complete($effect, $now);
        }
        if ($now < $fade['nextAt']) {
            return false;
        }
        $progress = $fade['duration'] === 0 ? 1.0 : min(1, max(0, ($now - $fade['startedAt']) / $fade['duration']));
        if ($progress >= 1) {
            return $this->dispatch($effect, ['operation' => 'endpoint_white', 'profile' => $effect['profile'],
                'waitMs' => 800 + max(0, (int) config('lighting.cloud.settle_margin_ms', 400))], $now);
        }
        $p = $progress * $progress * (3 - 2 * $progress);

        return $this->dispatch($effect, ['operation' => 'realtime_white',
            'brightnessPct' => $fade['from']['brightnessPct'] + ($fade['to']['brightnessPct'] - $fade['from']['brightnessPct']) * $p,
            'temperaturePct' => $fade['from']['temperaturePct'] + ($fade['to']['temperaturePct'] - $fade['from']['temperaturePct']) * $p], $now);
    }

    private function complete(array $effect, int $now): bool
    {
        $snapshot = $effect['snapshot'];
        $dark = $this->matchesWhite($snapshot, $this->driver->profiles()['dark']);
        $this->store->atomic(function (LightingState $state) use ($effect, $snapshot, $dark, $now) {
            if (! $this->currentEffect($state, $effect)) {
                return;
            }
            $state->native_effect = null;
            $observed = $this->driver->observationFromSnapshot($snapshot, $now);
            $state->observed = array_merge($observed, ['quality' => 'confirmed', 'outputSettled' => true,
                'completion' => 'cloud_readback', 'physicalConfirmed' => false]);
            if ($dark) {
                $state->color_barrier = false;
                $state->dark_since_ms = $now;
                $state->stage = 'dark';
            }
            if (! $this->sameIntent($state->toArray(), $effect) || ! $this->active($state->toArray(), $now)) {
                return;
            }
            $target = $state->desired_target;
            $targetReached = $target['kind'] === 'white'
                ? $this->matchesWhite($snapshot, $this->driver->profiles()[$target['profile']])
                : ($target['color'] === null ? $dark
                    : $snapshot['values'][20] && $snapshot['values'][21] === 'scene'
                        && ($snapshot['values'][25] ?? null) === $this->driver->scenes()[$target['color']]['raw']);
            if ($targetReached) {
                $state->applied_revision = $state->revision;
                $state->stage = $target['kind'] === 'white' ? 'white' : ($dark ? 'dark' : 'scene');
                if ($target['kind'] === 'mythos') {
                    $state->observed = array_merge($state->observed, ['mythosSessionId' => $target['mythosSessionId']]);
                }
            }
            $this->store->event($state, 'native_completed', $target);
        });

        return true;
    }

    private function saveEffect(?array $effect, ?string $previousId = null, bool $clearResolvedError = false): void
    {
        $this->store->atomic(function (LightingState $state) use ($effect, $previousId, $clearResolvedError) {
            $id = $previousId ?? $effect['id'];
            if (($state->native_effect['id'] ?? null) !== $id) {
                return;
            }
            $state->native_effect = $effect;
            if ($clearResolvedError && in_array($state->error, ['transport_timeout', 'offline', 'configuration_error'], true)) {
                $state->error = null;
            }
        });
    }

    private function fail(?array $effect, Throwable $error, ?array $origin = null, bool $onlyCurrentIntent = false): void
    {
        $code = in_array($error->getMessage(), ['offline', 'transport_timeout', 'preset_not_configured', 'unsupported_transition'], true)
            ? $error->getMessage() : 'configuration_error';
        $this->store->atomic(function (LightingState $state) use ($effect, $code, $origin, $onlyCurrentIntent) {
            if ($effect !== null && ! $this->currentEffect($state, $effect)) {
                return;
            }
            if ($onlyCurrentIntent && $effect !== null && ! $this->sameIntent($state->toArray(), $effect)) {
                return;
            }
            if ($effect === null && $origin !== null
                && ($state->revision !== $origin['revision'] || $state->control_generation !== $origin['control_generation'])) {
                return;
            }
            $state->error = $code;
            $state->stage = $code === 'offline' ? 'offline' : 'error';
            $state->color_barrier = true;
            $state->dark_since_ms = null;
            $state->observed = array_merge($state->observed ?? [], ['quality' => 'unknown', 'outputSettled' => false,
                'completion' => 'transport_uncertain', 'physicalConfirmed' => false]);
        });
    }

    private function active(array $state, int $now): bool
    {
        return $state['enabled'] && LightingAccess::allows($state['owner_user_id'])
            && $state['control_expires_ms'] > $now && $state['desired_target'] !== null;
    }

    private function sameIntent(array $state, array $effect): bool
    {
        return $state['revision'] === $effect['revision'] && $state['control_generation'] === $effect['generation'];
    }

    private function current(LightingState $state, array $before, int $now): bool
    {
        return $this->active($state->toArray(), $now) && $state->revision === $before['revision']
            && $state->control_generation === $before['control_generation'];
    }

    private function currentEffect(LightingState $state, array $effect): bool
    {
        return ($state->native_effect['id'] ?? null) === $effect['id'];
    }

    private function matches(array $snapshot, array $expected): bool
    {
        foreach ($expected as $dp => $value) {
            if (($snapshot['values'][$dp] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    private function matchesWhite(array $snapshot, array $profile): bool
    {
        return $this->matches($snapshot, $this->whiteValues($profile, true));
    }

    private function whiteValues(array $profile, bool $on): array
    {
        return [20 => $on, 21 => 'white', 22 => $profile['brightness'], 23 => $profile['temperature']];
    }

    private function knownScene(mixed $raw): bool
    {
        foreach ($this->driver->scenes() as $scene) {
            if ($raw === $scene['raw']) {
                return true;
            }
        }

        return false;
    }

    private function gradientStep(int $on, int $off): array
    {
        return ['operation' => 'gradient', 'onMs' => $on, 'offMs' => $off];
    }

    private function barrierSteps(bool $on, bool $scene): array
    {
        $down = max(0, (int) config($scene ? 'lighting.scene_fade_out_ms' : 'lighting.white_fade_down_ms', 4000));
        $margin = max(0, (int) config('lighting.cloud.settle_margin_ms', 400));
        $steps = [$this->gradientStep(800, $down)];
        if ($on) {
            $steps[] = ['operation' => 'power', 'on' => false, 'waitMs' => $down + $margin];
        }
        $steps[] = ['operation' => 'prepare_white', 'profile' => 'dark'];
        $steps[] = ['operation' => 'power', 'on' => true, 'waitMs' => 800 + $margin];

        return $steps;
    }

    private function stage(array $effect, array $descriptor): string
    {
        if ($descriptor['operation'] === 'scene') {
            return 'applying_scene';
        }

        return $effect['flow'] === 'barrier' || $effect['profile'] === 'dark' ? 'fading_to_dark' : 'fading_white_up';
    }
}
