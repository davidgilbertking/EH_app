<?php

namespace App\Lighting;

use App\Lighting\Drivers\NativeCloudLightingDriver;
use App\Lighting\Drivers\TuyaCloudClient;
use App\Lighting\Drivers\TuyaCloudException;
use App\Models\LightingState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Experimental, continuously powered DP28/DP25 transitions. Cloud receipts describe
 * acceptance, never physical output. A running scene's first-unit RGB is only an
 * approximation: its instantaneous phase cannot be read from the stored scene.
 */
class CloudContinuousCoordinator implements LightingExecutor
{
    private NativeCloudLightingDriver $reader;

    public function __construct(private LightingStore $store, private TuyaCloudClient $client, ?NativeCloudLightingDriver $reader = null)
    {
        // Share the client/model/token cache; configuration files load lazily.
        $this->reader = $reader ?? new NativeCloudLightingDriver($client);
    }

    public function recover(): void
    {
        $this->store->atomic(function (LightingState $state) {
            $state->read_revision = null;
            $effect = $state->native_effect;
            if (($effect['engine'] ?? null) === 'continuous_v1') {
                $effect['needsBoundary'] = true;
                $state->native_effect = $effect;
            }
        });
    }

    public function tick(?int $now = null): bool
    {
        $now ??= LightingStore::now();
        $db = DB::connection();
        $identity = $db->getDriverName().':'.$db->getConfig('host').':'.$db->getDatabaseName();
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
        if (config('lighting.driver') !== 'cloud' || ! config('lighting.cloud.continuous_transitions', false)) {
            return false;
        }
        $effect = $state['native_effect'];
        try {
            if ($effect !== null && ($effect['engine'] ?? null) !== 'continuous_v1') {
                throw new TuyaCloudException('unsupported_transition');
            }
            // Reconcile even after release, but never recreate an attempted POST.
            if (($effect['pending'] ?? null) !== null) {
                return $this->reconcile($effect, $now);
            }
            if (! $this->active($state, $now) || $state['error'] !== null) {
                return false;
            }
            if ($effect === null) {
                return $state['revision'] === $state['applied_revision'] ? false : $this->begin($state, $now);
            }
            // A restart is not a new gesture. Leave a completed target idle,
            // including old journals whose recovery changed complete to plan.
            if ($this->sameIntent($state, $effect) && $state['revision'] === $state['applied_revision']) {
                return false;
            }
            if (! $this->sameIntent($state, $effect) || $effect['needsBoundary']) {
                if ($this->canRebaseCompleted($state, $effect)) {
                    $snapshot = $this->reader->readSnapshot();
                    if (! $this->matches($snapshot, $this->sourceValues($effect['snapshot']))) {
                        return $this->rebaseCompleted($state, $effect, $snapshot, $now);
                    }
                } else {
                    $snapshot = $this->boundary($effect);
                }
                $sameTarget = $effect['target'] === $state['desired_target'];
                $sameOwner = $effect['generation'] === $state['control_generation'];
                $wasComplete = $effect['revision'] <= $state['applied_revision'];
                $encounter = LightingTransitionPolicy::isEncounter($effect['target'])
                    || LightingTransitionPolicy::isEncounter($state['desired_target'])
                    || $this->isEncounterOutput($effect['output'])
                    || (! $wasComplete && ($effect['encounterTransition'] ?? false));
                if (! $sameTarget || ! $sameOwner || $effect['needsBoundary']) {
                    $effect['encounterMinimum'] = false;
                }
                $effect['encounterTransition'] = $encounter;
                $effect['revision'] = $state['revision'];
                $effect['generation'] = $state['control_generation'];
                $effect['target'] = $state['desired_target'];
                $effect['snapshot'] = $snapshot;
                // Rebase from last accepted channels, not old DP22/23 or elapsed
                // frames missed during downtime. Preserve an endpoint settle wait.
                if ((! $sameTarget || ! $sameOwner || $effect['needsBoundary'])
                    && ! in_array($effect['phase'], $encounter ? ['commit_wait'] : ['commit_wait', 'onboard_wait'], true)) {
                    $effect['phase'] = 'plan';
                    if ($encounter) {
                        // A qualified native program can be stopped by a direct
                        // minimum endpoint; its original fade wait is unnecessary.
                        $effect['notBefore'] = $now;
                    }
                }
                $effect['needsBoundary'] = false;
                $this->save($effect, true);

                return true;
            }
            if ($now < $effect['notBefore']) {
                return false;
            }

            return match ($effect['phase']) {
                'plan' => $this->plan($effect, $now),
                'fade' => $this->fade($effect, $now),
                'onboard_start' => $this->startOnboard($effect, $now),
                'onboard_wait' => $this->finishOnboard($effect, $now),
                'settle' => $this->afterFade($effect, $now),
                'commit_wait' => $this->finishCommit($effect, $now),
                'complete' => $this->complete($effect, $now),
                default => throw new TuyaCloudException('configuration_error'),
            };
        } catch (Throwable $error) {
            $this->fail($effect, $error, $state);

            return false;
        }
    }

    private function begin(array $state, int $now): bool
    {
        if (in_array($state['observed']['completion'] ?? null, ['cloud_pending', 'cloud_accepted', 'transport_uncertain'], true)) {
            // A foreign/lost DP28 journal cannot be reconstructed from the shadow.
            throw new TuyaCloudException('unsupported_transition');
        }
        $snapshot = $this->reader->readSnapshot();
        $effect = $this->effectFromSnapshot($state, $snapshot, $now);
        $this->store->atomic(function (LightingState $fresh) use ($effect, $state, $now) {
            if (! $this->sameIntent($fresh->toArray(), $effect) || ! $this->active($fresh->toArray(), $now)
                || $fresh->native_effect !== null) {
                return;
            }
            $fresh->native_effect = $effect;
            $fresh->read_revision = $state['revision'];
        });

        return true;
    }

    private function canRebaseCompleted(array $state, array $effect): bool
    {
        return $state['revision'] > $state['applied_revision']
            && $effect['revision'] <= $state['applied_revision']
            && $effect['pending'] === null
            && in_array($effect['phase'], ['complete', 'plan'], true)
            && in_array($effect['output']['kind'], ['white', 'scene', 'colour'], true);
    }

    private function rebaseCompleted(array $state, array $previous, array $snapshot, int $now): bool
    {
        // Only an explicit new intent may adopt externally changed output after
        // a durably completed effect. Never discard a transient/unknown command.
        $effect = $this->effectFromSnapshot($state, $snapshot, $now);

        return $this->store->atomic(function (LightingState $fresh) use ($previous, $effect, $now) {
            if ($fresh->native_effect !== $previous || ! $this->sameIntent($fresh->toArray(), $effect)
                || ! $this->active($fresh->toArray(), $now) || $fresh->error !== null
                || ! $this->canRebaseCompleted($fresh->toArray(), $previous)) {
                return false;
            }
            $fresh->native_effect = $effect;
            $fresh->read_revision = $effect['revision'];
            $this->applyObservation($fresh, $effect, $now, false);
            $fresh->observed = array_replace($fresh->observed, ['quality' => 'reported', 'completion' => 'cloud_readback']);

            return true;
        });
    }

    private function effectFromSnapshot(array $state, array $snapshot, int $now): array
    {
        $values = $snapshot['values'];
        if ($values[20] !== true) {
            throw new TuyaCloudException('unsupported_transition');
        }
        if ($values[21] === 'white') {
            $output = ['kind' => 'white', 'channels' => $this->whiteChannels($values[22], $values[23]), 'approximate' => false];
        } elseif ($values[21] === 'colour') {
            $output = ['kind' => 'colour', 'channels' => NativeCloudLightingDriver::colourChannels($snapshot), 'approximate' => false];
        } else {
            $color = $this->knownScene($values);
            $output = ['kind' => 'scene', 'channels' => $this->sceneChannels($color), 'color' => $color,
                'mythosSessionId' => null, 'approximate' => true];
        }

        return ['engine' => 'continuous_v1', 'id' => (string) Str::uuid(), 'revision' => $state['revision'],
            'generation' => $state['control_generation'], 'target' => $state['desired_target'],
            'phase' => 'plan', 'snapshot' => $snapshot, 'output' => $output, 'pending' => null,
            'notBefore' => $now, 'needsBoundary' => false,
            'encounterTransition' => LightingTransitionPolicy::isEncounter($state['desired_target']) || $this->isEncounterOutput($output),
            'encounterMinimum' => false];
    }

    private function plan(array $effect, int $now): bool
    {
        $output = $effect['output'];
        $target = $effect['target'];
        $channels = $output['channels'];
        if ($target['kind'] === 'colour') {
            $colour = $this->colourChannels($target['profile']);
            if ($output['kind'] === 'colour' && $channels === $colour
                && $this->matches($effect['snapshot'], [20 => true, 21 => 'colour',
                    24 => sprintf('%04x%04x%04x', $colour['h'], $colour['s'], $colour['v'])])) {
                return $this->complete($effect, $now);
            }
        }
        if ($target['kind'] === 'white' && $output['kind'] === 'white'
            && $channels === $this->profileChannels($target['profile'])
            && $this->matchesWhite($effect['snapshot'], $target['profile'])) {
            return $this->complete($effect, $now);
        }
        if ($output['kind'] === 'scene' && $target['kind'] === 'mythos'
            && $target['color'] === $output['color'] && $target['mythosSessionId'] === ($output['mythosSessionId'] ?? null)) {
            return $this->complete($effect, $now);
        }
        if ($effect['encounterTransition'] ?? false) {
            return $this->planEncounter($effect, $now);
        }
        if ($output['kind'] === 'onboard' && $channels['v'] === 0) {
            // End the temporary native white program before a software white
            // rise. Its repeated minimum target must not compete with DP28.
            return $this->commit($effect, ['operation' => 'white', 'profile' => 'dark'], $now);
        }
        if ($output['kind'] === 'scene') {
            // Stop the autonomous scene before fading. Its exact instantaneous
            // hue is unavailable: retain the existing first-unit approximation.
            $effect['snapshot'] = $this->boundary($effect);
            $raw = sprintf('%04x%04x%04x', $channels['h'], $channels['s'], $channels['v']);
            $expected = array_replace($this->sourceValues($effect['snapshot']), [21 => 'colour', 24 => $raw]);

            return $this->dispatch($effect, ['operation' => 'static_colour', 'channels' => $channels,
                'expected' => $expected, 'freshFields' => [21, 24]], $now);
        }
        if ($channels['v'] > 0) {
            if ($channels['v'] === 10 && $channels['bright'] === 0) {
                return $this->startFade($effect, 'rgb_bridge', $this->profileChannels('dark'), 0, $now);
            }
            $to = $channels;
            $to['v'] = min(10, $channels['v']);
            $to['bright'] = $to['temperature'] = 0;

            return $this->startFade($effect, 'rgb_down', $to, $this->duration('scene_fade_out_ms', 4000), $now);
        }
        $profile = $target['kind'] === 'white' ? $target['profile'] : 'dark';
        $white = $this->profileChannels($profile);
        if ($channels !== $white) {
            $direction = $white['bright'] > $channels['bright'] ? 'up' : 'down';
            $fraction = max(abs($white['bright'] - $channels['bright']) / 990, abs($white['temperature'] - $channels['temperature']) / 1000);
            $duration = (int) round($this->duration('white_fade_'.$direction.'_ms', $direction === 'up' ? 12000 : 4000) * $fraction);

            return $this->startFade($effect, $profile === 'dark' ? 'dark' : 'white', $white, $duration, $now);
        }
        if ($output['kind'] !== 'white' || ! $this->matchesWhite($effect['snapshot'], $profile)) {
            return $this->commit($effect, ['operation' => 'white', 'profile' => $profile], $now);
        }
        if ($target['kind'] === 'white' || ($target['kind'] === 'mythos' && $target['color'] === null)) {
            return $this->complete($effect, $now);
        }

        $anchor = $target['kind'] === 'colour' ? $colour : $this->sceneChannels($target['color']);
        $anchor['v'] = 10;

        // Let one native gradient transfer the minimum white output to minimum
        // RGB. Interpolating raw white 10..0 would issue subminimum 1..9 values.
        return $this->startFade($effect, $target['kind'] === 'colour' ? 'colour_anchor' : 'scene_anchor', $anchor, 0, $now);
    }

    private function planEncounter(array $effect, int $now): bool
    {
        $output = $effect['output'];
        $channels = $output['channels'];
        $target = $effect['target'];
        $destination = match ($target['kind']) {
            'white' => $this->profileChannels($target['profile']),
            'colour' => $this->colourChannels($target['profile']),
            default => $target['color'] === null ? $this->profileChannels('dark') : $this->sceneChannels($target['color']),
        };
        if (! ($effect['encounterMinimum'] ?? false)) {
            if ($channels['v'] > 0) {
                $minimum = array_replace($channels, ['v' => 10, 'bright' => 0, 'temperature' => 0]);
                if ($channels !== $minimum || $output['kind'] !== 'colour' || $effect['snapshot']['values'][21] !== 'colour') {
                    // DP24 stops a breathing/native scene and drops the current
                    // hue directly to 1%; DP28 always encodes a device gradient.
                    return $this->commit($effect, ['operation' => 'static_colour', 'channels' => $minimum], $now);
                }
            } elseif ($output['kind'] !== 'white' || ! $this->matchesWhite($effect['snapshot'], 'dark')
                || $channels !== $this->profileChannels('dark')) {
                return $this->commit($effect, ['operation' => 'white', 'profile' => 'dark'], $now);
            }
            $effect['encounterMinimum'] = true;
        }
        if ($target['kind'] === 'mythos' && $target['color'] === null) {
            if ($output['kind'] !== 'white' || $channels !== $destination) {
                return $this->commit($effect, ['operation' => 'white', 'profile' => 'dark'], $now);
            }

            return $this->complete($effect, $now);
        }
        $white = $target['kind'] === 'white';
        $anchor = array_replace($destination, [$white ? 'bright' : 'v' => 10]);
        if ($channels !== $anchor || $output['kind'] !== ($white ? 'white' : 'colour')) {
            return $this->commit($effect, ['operation' => $white ? 'white_anchor' : 'static_colour', 'channels' => $anchor], $now);
        }

        return $this->startFade($effect, $white ? 'white' : ($target['kind'] === 'colour' ? 'colour' : 'scene'),
            $destination, LightingTransitionPolicy::encounterFadeInMs(), $now);
    }

    private function startFade(array $effect, string $purpose, array $to, int $duration, int $now): bool
    {
        $effect['phase'] = 'fade';
        if (! ($effect['encounterTransition'] ?? false) && config('lighting.cloud.onboard_fades', false) && $duration > 0
            && in_array($purpose, ['dark', 'rgb_down', 'scene', 'colour'], true)) {
            $effect['phase'] = 'onboard_start';
        }
        $effect['fade'] = ['purpose' => $purpose, 'from' => $effect['output']['channels'], 'to' => $to,
            'startedAt' => $now, 'duration' => max(0, $duration)];
        $effect['notBefore'] = max($effect['notBefore'], $now);
        $this->save($effect, true);

        return true;
    }

    private function startOnboard(array $effect, int $now): bool
    {
        $effect['snapshot'] = $this->boundary($effect);
        $program = NativeFadeProgram::build($effect['fade']['to'], $this->reader->scenes(),
            (int) config('lighting.cloud.native_fade_timing_byte', 30));
        $wait = (int) config('lighting.cloud.native_fade_wait_ms', 5000);
        if ($wait < 400 || $wait > 30000) {
            throw new TuyaCloudException('configuration_error');
        }
        $expected = array_replace($this->sourceValues($effect['snapshot']), [21 => 'scene', 25 => $program['raw']]);
        unset($expected[24]);

        return $this->dispatch($effect, ['operation' => 'native_fade', 'expected' => $expected, 'freshFields' => [25],
            'waitMs' => $wait, 'target' => $effect['target'], 'channels' => $effect['fade']['to'],
            'commands' => [['code' => 'scene_data_v2', 'value' => $program['value']],
                ['code' => 'work_mode', 'value' => 'scene']]], $now);
    }

    private function finishOnboard(array $effect, int $now): bool
    {
        $effect['snapshot'] = $this->boundary($effect);
        // Only the held endpoint is recorded, after the original durable wait.
        // The ramp's instantaneous output and actual duration remain unverified.
        $effect['output'] = ['kind' => 'onboard', 'channels' => $effect['onboard']['channels'],
            'approximate' => $effect['output']['approximate']];
        $effect['phase'] = $effect['target'] === $effect['onboard']['target'] ? 'settle' : 'plan';
        $effect['notBefore'] = $now;
        $this->save($effect, true);
        $this->observe($effect, $now, false);

        return true;
    }

    private function fade(array $effect, int $now): bool
    {
        $fade = $effect['fade'];
        $progress = $fade['duration'] === 0 ? 1.0 : min(1.0, max(0.0, ($now - $fade['startedAt']) / $fade['duration']));
        $p = config('lighting.curve', 'smoothstep') === 'linear' ? $progress : $progress * $progress * (3 - 2 * $progress);
        if (config('lighting.cloud.white_up_curve', 'perceptual') === 'perceptual') {
            $sameWhite = $fade['purpose'] === 'white' && $fade['from']['v'] === 0 && $fade['to']['v'] === 0;
            if (in_array($fade['purpose'], ['scene', 'colour'], true) || ($sameWhite && $fade['to']['bright'] > $fade['from']['bright'])) {
                $p = $progress ** 2.2;
            } elseif (in_array($fade['purpose'], ['dark', 'rgb_down'], true)
                || ($sameWhite && $fade['to']['bright'] < $fade['from']['bright'])) {
                // Time-reverse the accepted rise, rather than delaying the
                // brightness drop until the last part of the transition.
                $p = 1 - (1 - $progress) ** 2.2;
            }
        }
        $channels = [];
        foreach ($fade['to'] as $field => $value) {
            $channels[$field] = (int) round($fade['from'][$field] + ($value - $fade['from'][$field]) * $p);
        }
        // Introduce/fade the chosen hue; do not sweep through unrelated hues.
        if ($fade['from']['v'] === 0 && $channels['v'] > 0) {
            $channels['h'] = $fade['to']['h'];
            $channels['s'] = $fade['to']['s'];
        } elseif ($fade['to']['v'] === 0 && $channels['v'] > 0) {
            $channels['h'] = $fade['from']['h'];
            $channels['s'] = $fade['from']['s'];
        }
        if (($channels['bright'] > 0 && $channels['bright'] < 10)
            || ($channels['v'] > 0 && $channels['v'] < 10)
            || ($channels['bright'] === 0 && $channels['v'] === 0)) {
            throw new TuyaCloudException('unsupported_transition');
        }

        return $this->dispatch($effect, ['operation' => 'realtime', 'channels' => $channels, 'final' => $progress >= 1], $now);
    }

    private function afterFade(array $effect, int $now): bool
    {
        return match ($effect['fade']['purpose']) {
            // A single native gradient connects the two valid 1% endpoints.
            // Raw crossfade intermediates like V5/white5 can mean both LEDs OFF.
            'rgb_down' => $this->startFade($effect, 'rgb_bridge', $this->profileChannels('dark'), 0, $now),
            'scene_anchor' => $this->startFade($effect, 'scene', $this->sceneChannels($effect['target']['color']),
                $this->duration('scene_fade_in_ms', 4000), $now),
            'colour_anchor' => $this->startFade($effect, 'colour', $this->colourChannels($effect['target']['profile']),
                $this->duration('scene_fade_in_ms', 4000), $now),
            'dark', 'rgb_bridge' => $this->commit($effect, ['operation' => 'white', 'profile' => 'dark'], $now),
            'white' => $this->commit($effect, ['operation' => 'white', 'profile' => $effect['target']['profile']], $now),
            'scene' => $this->commit($effect, ['operation' => 'scene', 'color' => $effect['target']['color']], $now),
            'colour' => $this->commit($effect, ['operation' => 'static_colour', 'profile' => $effect['target']['profile']], $now),
            default => throw new TuyaCloudException('configuration_error'),
        };
    }

    private function commit(array $effect, array $descriptor, int $now): bool
    {
        $snapshot = $this->boundary($effect);
        $effect['snapshot'] = $snapshot;
        $expected = $this->sourceValues($snapshot);
        if (in_array($descriptor['operation'], ['white', 'white_anchor'], true)) {
            $profile = $descriptor['operation'] === 'white'
                ? ($this->reader->profiles()[$descriptor['profile']] ?? throw new TuyaCloudException('preset_not_configured'))
                : ['brightness' => $descriptor['channels']['bright'], 'temperature' => $descriptor['channels']['temperature']];
            unset($expected[24], $expected[25]);
            $expected = array_replace($expected, [21 => 'white', 22 => $profile['brightness'], 23 => $profile['temperature']]);
            $descriptor['commands'] = [['code' => 'bright_value_v2', 'value' => $profile['brightness']],
                ['code' => 'temp_value_v2', 'value' => $profile['temperature']], ['code' => 'work_mode', 'value' => 'white']];
            $descriptor['freshFields'] = [21, 22, 23];
        } elseif ($descriptor['operation'] === 'static_colour') {
            $channels = isset($descriptor['profile']) ? $this->colourChannels($descriptor['profile']) : $descriptor['channels'];
            unset($expected[25]);
            $expected = array_replace($expected, [21 => 'colour',
                24 => sprintf('%04x%04x%04x', $channels['h'], $channels['s'], $channels['v'])]);
            $descriptor['channels'] = $channels;
            $descriptor['freshFields'] = [21, 24];
        } else {
            $scene = $this->reader->scenes()[$descriptor['color']] ?? throw new TuyaCloudException('preset_not_configured');
            unset($expected[24]);
            $expected = array_replace($expected, [21 => 'scene', 25 => $scene['raw']]);
            $descriptor['commands'] = [['code' => 'scene_data_v2', 'value' => $scene['value']], ['code' => 'work_mode', 'value' => 'scene']];
            $descriptor['freshFields'] = [21, 25];
        }
        $descriptor['expected'] = $expected;

        return $this->dispatch($effect, $descriptor, $now);
    }

    private function dispatch(array $effect, array $descriptor, int $now): bool
    {
        $pending = ['id' => (string) Str::uuid(), 'descriptor' => $descriptor, 'attemptedAt' => $now,
            'beforeTimes' => $effect['snapshot']['times'], 'deadline' => $now + 15000, 'nextReadAt' => $now, 'receipt' => null];
        $armed = $this->store->atomic(function (LightingState $state) use ($effect, $pending, $now) {
            if (! $this->ownsEffect($state, $effect) || ! $this->sameIntent($state->toArray(), $effect)
                || ! $this->active($state->toArray(), $now)) {
                return false;
            }
            $effect['pending'] = $pending;
            $state->native_effect = $effect;
            $state->stage = $this->stage($effect);
            $state->color_barrier = true;
            $state->dark_since_ms = null;
            $state->observed = array_merge($state->observed ?? [], ['quality' => 'commanded', 'completion' => 'cloud_pending',
                'outputSettled' => false, 'physicalConfirmed' => false]);
            if (($pending['descriptor']['readbackRetries'] ?? 0) > 0) {
                if (in_array($state->error, ['transport_timeout', 'offline'], true)) {
                    $state->error = null;
                }
                $this->store->event($state, 'continuous_endpoint_retry', $effect['target']);
            }

            return true;
        });
        if (! $armed) {
            return false;
        }
        $fresh = $this->store->state()->toArray();
        if (! $this->sameIntent($fresh, $effect) || ! $this->active($fresh, max($now, LightingStore::now()))) {
            $this->clearAttempt($effect, $pending['id']); // This process knows no POST ran.

            return false;
        }
        try {
            $receipt = match ($descriptor['operation']) {
                'realtime' => $this->client->sendRealtime($descriptor['channels']),
                'static_colour' => $this->client->sendStaticColour($descriptor['channels']),
                default => $this->client->sendCommands($descriptor['commands']),
            };
            if (! is_int($receipt['sentAt'] ?? null)) {
                throw new TuyaCloudException('transport_timeout', writeOutcomeUnknown: true);
            }
            $this->store->atomic(function (LightingState $state) use ($effect, $pending, $receipt) {
                $saved = $state->native_effect;
                if ($this->ownsEffect($state, $effect) && ($saved['pending']['id'] ?? null) === $pending['id']) {
                    $saved['pending']['receipt'] = $receipt;
                    $state->native_effect = $saved;
                }
            });
        } catch (Throwable $error) {
            $knownPrewrite = $error instanceof TuyaCloudException && ! $error->writeOutcomeUnknown;
            if ($knownPrewrite) {
                $this->clearAttempt($effect, $pending['id']);
            }
            $this->fail($effect, $error, $knownPrewrite ? $fresh : null);
        }

        return true;
    }

    private function reconcile(array $effect, int $now): bool
    {
        $pending = $effect['pending'];
        $descriptor = $pending['descriptor'];
        if ($descriptor['operation'] === 'realtime') {
            if ($pending['receipt'] === null) {
                // Stored brightness cannot confirm DP28, even after restart.
                throw new TuyaCloudException('transport_timeout', writeOutcomeUnknown: true);
            }
            $effect['output'] = ['kind' => 'realtime', 'channels' => $descriptor['channels'],
                'approximate' => $effect['output']['approximate']];
            $effect['pending'] = null;
            // Pace start-to-start. A slow round trip already consumed its share
            // of the frame interval; do not add another 300 ms after the ACK.
            $effect['notBefore'] = max($now, $pending['receipt']['sentAt'] + $this->frameInterval());
            if ($descriptor['final']) {
                $effect['phase'] = 'settle';
                $effect['notBefore'] = max($effect['notBefore'], $now + $this->settleMargin());
            }
            $this->save($effect);
            $this->observe($effect, $now, false);

            return true;
        }
        if ($now < $pending['nextReadAt']) {
            return false;
        }
        // Back off even when reads fail or the confirmation deadline has passed.
        $effect['pending']['nextReadAt'] = $now + max(300, (int) config('lighting.cloud.poll_interval_ms', 500));
        $this->save($effect);
        $snapshot = $this->reader->readSnapshot();
        $sentAt = $pending['receipt']['sentAt'] ?? $pending['attemptedAt'];
        $fresh = false;
        foreach ($descriptor['freshFields'] as $dp) {
            $fresh = $fresh || (($snapshot['times'][$dp] ?? 0) >= $sentAt && ($snapshot['times'][$dp] ?? 0) > ($pending['beforeTimes'][$dp] ?? 0));
        }
        if (! $fresh || ! $this->matches($snapshot, $descriptor['expected'])) {
            if ($now >= $pending['deadline']) {
                return $this->recoverExpiredEndpoint($effect, $snapshot, $now);
            }

            return false;
        }
        $effect['snapshot'] = $snapshot;
        $effect['pending'] = null;
        if ($descriptor['operation'] === 'native_fade') {
            // DP25 readback qualifies this exact program, including an unknown
            // POST outcome. It never proves the LEDs reached the target.
            $acceptedAt = max($pending['attemptedAt'], $snapshot['times'][25], $pending['receipt']['acceptedAt'] ?? $sentAt);
            $effect['onboard'] = ['target' => $descriptor['target'], 'channels' => $descriptor['channels'],
                'programRaw' => $descriptor['expected'][25], 'until' => $acceptedAt + $descriptor['waitMs'],
                'attemptedAt' => $pending['attemptedAt'], 'descriptor' => $descriptor, 'receipt' => $pending['receipt'],
                'qualifiedAt' => $now, 'proof' => $snapshot];
            $effect['phase'] = 'onboard_wait';
            $effect['notBefore'] = $effect['onboard']['until'];
            $this->store->atomic(function (LightingState $state) use ($effect, $pending) {
                if ($this->ownsEffect($state, $effect) && ($state->native_effect['pending']['id'] ?? null) === $pending['id']) {
                    $state->native_effect = $effect;
                    if (in_array($state->error, ['transport_timeout', 'offline'], true)) {
                        $state->error = null;
                    }
                }
            });
            $this->observe($effect, $now, false);

            return true;
        }
        $effect['phase'] = 'commit_wait';
        $effect['committed'] = $descriptor;
        $effect['notBefore'] = $now + $this->settleMargin();
        if (in_array($descriptor['operation'], ['white', 'white_anchor'], true)) {
            $effect['output'] = ['kind' => 'white',
                'channels' => $descriptor['operation'] === 'white' ? $this->profileChannels($descriptor['profile']) : $descriptor['channels'],
                'approximate' => false];
        } elseif ($descriptor['operation'] === 'static_colour') {
            $effect['output'] = ['kind' => 'colour', 'channels' => $descriptor['channels'],
                'approximate' => isset($descriptor['profile']) ? false : $effect['output']['approximate']];
        } else {
            $effect['output'] = ['kind' => 'scene', 'channels' => $this->sceneChannels($descriptor['color']),
                'color' => $descriptor['color'], 'mythosSessionId' => $effect['target']['mythosSessionId'], 'approximate' => true];
        }
        $this->store->atomic(function (LightingState $state) use ($effect, $pending) {
            if ($this->ownsEffect($state, $effect) && ($state->native_effect['pending']['id'] ?? null) === $pending['id']) {
                $state->native_effect = $effect;
                if (in_array($state->error, ['transport_timeout', 'offline'], true)) {
                    $state->error = null;
                }
            }
        });

        return true;
    }

    private function recoverExpiredEndpoint(array $effect, array $snapshot, int $now): bool
    {
        $pending = $effect['pending'];
        $descriptor = $pending['descriptor'];
        // Only acknowledged, absolute white/RGB endpoints are repeatable. Never
        // replay an unknown POST, a realtime frame, or an autonomous program.
        if ($pending['receipt'] === null || ! in_array($descriptor['operation'], ['white', 'white_anchor', 'static_colour'], true)) {
            throw new TuyaCloudException('transport_timeout', writeOutcomeUnknown: true);
        }
        $compatible = $this->matches($snapshot, $this->sourceValues($effect['snapshot']))
            || $this->matches($snapshot, $descriptor['expected']);
        $retry = $this->store->atomic(function (LightingState $state) use ($effect, $pending, $descriptor, $snapshot, $now, $compatible) {
            if (! $this->ownsEffect($state, $effect) || ($state->native_effect['pending']['id'] ?? null) !== $pending['id']) {
                return null;
            }
            $current = $state->toArray();
            $active = $this->active($current, $now);
            $sameIntent = $this->sameIntent($current, $effect);
            $recoverable = in_array($state->error, [null, 'transport_timeout', 'offline'], true);
            if ($active && $sameIntent && $recoverable && $compatible && ($descriptor['readbackRetries'] ?? 0) < 1) {
                $effect['snapshot'] = $snapshot;
                // dispatch replaces the old journal atomically with the retry.
                // A crash before that point must retain the original attempt.

                return $effect;
            }

            // Retain the failed attempt for diagnosis, but do not leave every
            // future gesture queued behind an already acknowledged old endpoint.
            // A fresh snapshot is an interpolation origin, not physical proof.
            $current['desired_target'] ??= $effect['target'];
            $replacement = $this->effectFromSnapshot($current, $snapshot, $now);
            $replacement['encounterTransition'] = $replacement['encounterTransition']
                || ($effect['encounterTransition'] ?? false) || LightingTransitionPolicy::isEncounter($effect['target']);
            $replacement['unconfirmedEndpoint'] = $pending;
            $state->native_effect = $replacement;
            $state->observed = $this->reader->observationFromSnapshot($snapshot, $now);
            $state->color_barrier = true;
            $state->dark_since_ms = null;
            if ($active && $recoverable) {
                $state->error = $sameIntent ? 'transport_timeout' : null;
                $state->stage = $sameIntent ? 'error' : 'queued';
            }
            $this->store->event($state, 'continuous_endpoint_unconfirmed', $effect['target']);

            return null;
        });
        if ($retry === null) {
            return true;
        }
        $descriptor['readbackRetries'] = ($descriptor['readbackRetries'] ?? 0) + 1;

        return $this->commit($retry, $descriptor, $now);
    }

    private function finishCommit(array $effect, int $now): bool
    {
        $effect['snapshot'] = $this->boundary($effect);
        $effect['phase'] = 'plan';
        if (($effect['committed']['profile'] ?? null) === 'dark') {
            $effect['notBefore'] = $now + max(0, (int) config('lighting.dark_hold_ms', 150));
            if ($effect['encounterTransition'] ?? false) {
                $effect['encounterMinimum'] = true;
            }
        }
        $this->save($effect);
        $this->observe($effect, $now, true);

        return true;
    }

    private function complete(array $effect, int $now): bool
    {
        return $this->store->atomic(function (LightingState $state) use ($effect, $now) {
            if (! $this->ownsEffect($state, $effect) || ! $this->sameIntent($state->toArray(), $effect)
                || ! $this->active($state->toArray(), $now) || $state->applied_revision === $state->revision) {
                return false;
            }
            $effect['phase'] = 'complete';
            $state->native_effect = $effect;
            $state->applied_revision = $state->revision;
            $state->stage = match ($effect['target']['kind']) {
                'white' => 'white',
                'colour' => 'colour',
                default => $effect['target']['color'] === null ? 'dark' : 'scene',
            };
            $this->store->event($state, 'continuous_completed', $effect['target']);
            $this->applyObservation($state, $effect, $now, true);

            return true;
        });
    }

    private function boundary(array $effect): array
    {
        $snapshot = $this->reader->readSnapshot();
        if (! $this->matches($snapshot, $this->sourceValues($effect['snapshot']))) {
            throw new TuyaCloudException('unsupported_transition');
        }

        return $snapshot;
    }

    private function sourceValues(array $snapshot): array
    {
        $values = $snapshot['values'];
        if ($values[21] !== 'scene') {
            unset($values[25]);
        }
        if ($values[21] !== 'colour') {
            unset($values[24]);
        }

        return $values;
    }

    private function save(array $effect, bool $requireCurrent = false): void
    {
        $this->store->atomic(function (LightingState $state) use ($effect, $requireCurrent) {
            if ($this->ownsEffect($state, $effect) && (! $requireCurrent || $this->sameIntent($state->toArray(), $effect))) {
                $state->native_effect = $effect;
            }
        });
    }

    private function clearAttempt(array $effect, string $attempt): void
    {
        $this->store->atomic(function (LightingState $state) use ($effect, $attempt) {
            $saved = $state->native_effect;
            if ($this->ownsEffect($state, $effect) && ($saved['pending']['id'] ?? null) === $attempt) {
                $saved['pending'] = null;
                $state->native_effect = $saved;
            }
        });
    }

    private function observe(array $effect, int $now, bool $settled): void
    {
        $this->store->atomic(function (LightingState $state) use ($effect, $now, $settled) {
            if ($this->ownsEffect($state, $effect)) {
                $this->applyObservation($state, $effect, $now, $settled);
            }
        });
    }

    private function applyObservation(LightingState $state, array $effect, int $now, bool $settled): void
    {
        $output = $effect['output'];
        $channels = $output['channels'];
        $state->observed = ['driver' => 'cloud', 'mode' => $output['kind'], 'quality' => $settled ? 'reported' : 'commanded',
            'completion' => $settled ? 'cloud_readback' : 'cloud_accepted', 'outputSettled' => $settled,
            'physicalConfirmed' => false, 'observedAt' => $now, 'realtimeChannels' => $channels,
            'scenePhaseApproximate' => $output['approximate'], 'rgbSuppressed' => $channels['v'] === 0];
        if ($output['kind'] === 'white') {
            $state->observed = array_merge($state->observed, $this->reader->observationFromSnapshot($effect['snapshot'], $now),
                ['outputSettled' => $settled, 'completion' => $settled ? 'cloud_readback' : 'cloud_accepted']);
        } elseif ($output['kind'] === 'scene') {
            $state->observed = array_merge($state->observed, ['color' => $output['color'],
                'mythosSessionId' => $output['mythosSessionId'] ?? null]);
        } elseif ($output['kind'] === 'colour') {
            $colourObservation = ['hsv' => array_intersect_key($channels, array_flip(['h', 's', 'v'])), 'profile' => null];
            foreach ($this->reader->colours() as $alias => $colour) {
                if ($colourObservation['hsv'] === $colour) {
                    $colourObservation['profile'] = $alias;
                    break;
                }
            }
            $state->observed = array_merge($state->observed, $colourObservation);
        }
        $dark = $settled && $output['kind'] === 'white' && $channels === $this->profileChannels('dark');
        $state->color_barrier = ! $dark;
        $state->dark_since_ms = $dark ? $now : null;
    }

    private function fail(?array $effect, Throwable $error, ?array $origin = null): void
    {
        $code = in_array($error->getMessage(), ['offline', 'transport_timeout', 'preset_not_configured', 'unsupported_transition'], true)
            ? $error->getMessage() : 'configuration_error';
        $this->store->atomic(function (LightingState $state) use ($effect, $origin, $code) {
            if (($effect !== null && ! $this->ownsEffect($state, $effect))
                || ($origin !== null && ($state->revision !== $origin['revision'] || $state->control_generation !== $origin['control_generation']))) {
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

    private function ownsEffect(LightingState $state, array $effect): bool
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

    private function matchesWhite(array $snapshot, string $profile): bool
    {
        $channels = $this->profileChannels($profile);

        return $this->matches($snapshot, [20 => true, 21 => 'white', 22 => $channels['bright'], 23 => $channels['temperature']]);
    }

    private function whiteChannels(int $brightness, int $temperature): array
    {
        return ['h' => 0, 's' => 0, 'v' => 0, 'bright' => $brightness, 'temperature' => $temperature];
    }

    private function profileChannels(string $name): array
    {
        $profile = $this->reader->profiles()[$name] ?? throw new TuyaCloudException('preset_not_configured');

        return $this->whiteChannels($profile['brightness'], $profile['temperature']);
    }

    private function colourChannels(string $name): array
    {
        $colour = $this->reader->colours()[$name] ?? throw new TuyaCloudException('preset_not_configured');

        return $colour + ['bright' => 0, 'temperature' => 0];
    }

    private function isEncounterOutput(array $output): bool
    {
        if ($output['kind'] === 'white') {
            foreach ($this->reader->profiles() as $alias => $profile) {
                if ($output['channels'] === $this->whiteChannels($profile['brightness'], $profile['temperature'])
                    && LightingTransitionPolicy::isEncounter(['kind' => 'white', 'profile' => $alias])) {
                    return true;
                }
            }
        } elseif ($output['kind'] === 'colour') {
            foreach ($this->reader->colours() as $alias => $colour) {
                if ($output['channels'] === $colour + ['bright' => 0, 'temperature' => 0]
                    && LightingTransitionPolicy::isEncounter(['kind' => 'colour', 'profile' => $alias])) {
                    return true;
                }
            }
        }

        return false;
    }

    private function knownScene(array $values): string
    {
        if ($values[21] === 'scene') {
            foreach ($this->reader->scenes() as $color => $scene) {
                if (($values[25] ?? null) === $scene['raw']) {
                    return $color;
                }
            }
        }
        throw new TuyaCloudException('unsupported_transition');
    }

    private function sceneChannels(string $color): array
    {
        $scene = $this->reader->scenes()[$color] ?? throw new TuyaCloudException('preset_not_configured');
        $unit = $scene['value']['scene_units'][0];
        if ($unit['v'] !== 1000 || $unit['bright'] !== 0 || $unit['temperature'] !== 0) {
            throw new TuyaCloudException('unsupported_transition');
        }

        return ['h' => $unit['h'], 's' => $unit['s'], 'v' => $unit['v'], 'bright' => 0, 'temperature' => 0];
    }

    private function duration(string $key, int $default): int
    {
        return max(0, min(30000, (int) config('lighting.'.$key, $default)));
    }

    private function frameInterval(): int
    {
        return max(300, (int) config('lighting.cloud.frame_interval_ms', 300));
    }

    private function settleMargin(): int
    {
        return max(300, (int) config('lighting.cloud.settle_margin_ms', 400));
    }

    private function stage(array $effect): string
    {
        return match ($effect['fade']['purpose'] ?? '') {
            'scene_anchor', 'scene' => 'applying_scene',
            'colour_anchor', 'colour' => 'applying_colour',
            'white' => 'fading_white_up',
            default => 'fading_to_dark',
        };
    }
}
