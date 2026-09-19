<?php

namespace App\Lighting;

use App\Lighting\Drivers\CloudLightingFiles;
use App\Lighting\Drivers\TuyaCloudException;
use App\Models\LightingState;

/** Bind the durable mailbox before a worker can recover any device commands. */
class LightingDeviceBinding
{
    public function __construct(private LightingStore $store) {}

    public function synchronize(): void
    {
        if (config('lighting.driver') !== 'cloud') {
            return;
        }

        $this->store->atomic(function (LightingState $state) {
            $deviceId = config('lighting.cloud.device_id');
            $hasDeviceState = $state->enabled || $state->desired_target !== null
                || $state->observed !== null || $state->transition !== null
                || $state->native_effect !== null || $state->applied_revision !== null;

            if (! is_string($deviceId) || ! preg_match('/\A[A-Za-z0-9_-]{6,128}\z/D', $deviceId)) {
                // An unconfigured idle worker has always been allowed to heartbeat.
                if (! $hasDeviceState && $state->cloud_device_id === null) {
                    return;
                }

                throw new TuyaCloudException('configuration_error');
            }

            $previous = $state->cloud_device_id;
            if ($previous === null && $hasDeviceState) {
                // Adopt a legacy mailbox without disturbing its existing lamp.
                $previous = CloudLightingFiles::capturedDeviceId(config('lighting.cloud.private_directory'));
            }
            $state->cloud_device_id = $deviceId;
            if ($previous === null || $previous === $deviceId) {
                return;
            }

            // An old target, pending write or browser lease must never follow a
            // changed address. History stays intact; a new gesture starts fresh.
            $state->enabled = false;
            $state->owner_user_id = $state->owner_session_hash = null;
            $state->epoch_hash = $state->control_generation = null;
            $state->control_expires_ms = null;
            $state->client_seq = 0;
            $state->revision++;
            $state->applied_revision = null;
            $state->desired_target = $state->intent_id = null;
            $state->observed = $state->transition = $state->native_effect = null;
            $state->read_revision = $state->dark_since_ms = null;
            $state->color_barrier = true;
            $state->stage = 'idle';
            $state->error = null;
            $this->store->event($state, 'device_binding_changed');
        });
    }
}
