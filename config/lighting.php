<?php

return [
    // Empty allowlist disables all account access, including persisted leases.
    'allowed_user_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('LIGHTING_ALLOWED_USER_IDS', ''))))),
    // Physical Cloud transport is opt-in; credentials stay in a private file.
    'driver' => env('LIGHTING_DRIVER', 'mock'),
    // Duration of the full calibrated white range (1% to 100%).
    'white_fade_ms' => (int) env('LIGHTING_WHITE_FADE_MS', 4000),
    'white_fade_down_ms' => (int) env('LIGHTING_WHITE_FADE_DOWN_MS', env('LIGHTING_WHITE_FADE_MS', 4000)),
    'white_fade_up_ms' => (int) env('LIGHTING_WHITE_FADE_UP_MS', env('LIGHTING_WHITE_FADE_MS', 4000)),
    'scene_fade_out_ms' => (int) env('LIGHTING_SCENE_FADE_OUT_MS', 2000),
    'music_delay_multiplier' => (float) env('LIGHTING_MUSIC_DELAY_MULTIPLIER', 1.8),
    'scene_fade_in_ms' => (int) env('LIGHTING_SCENE_FADE_IN_MS', 4000),
    'dark_hold_ms' => (int) env('LIGHTING_DARK_HOLD_MS', 150),
    'curve' => env('LIGHTING_CURVE', 'smoothstep'), // smoothstep | linear
    'tick_ms' => (int) env('LIGHTING_TICK_MS', 100),
    // Cloud ticks can include token refresh plus a device request. Native fade
    // waits themselves run between ticks and never block the heartbeat.
    'worker_timeout_ms' => env('LIGHTING_DRIVER', 'mock') === 'cloud' ? 15000 : 5000,
    'control_lease_ms' => (int) env('LIGHTING_CONTROL_LEASE_MS', 43200000),
    'observation_max_age_ms' => 10000,
    'history_limit' => 256,
    'white_profiles' => [
        'action' => ['brightnessPct' => 100, 'temperaturePct' => 33],
        'encounters' => ['brightnessPct' => 90, 'temperaturePct' => 20],
    ],
    'cloud' => [
        'continuous_transitions' => (bool) env('LIGHTING_CLOUD_CONTINUOUS_TRANSITIONS', false),
        // Local candidate: the lamp interpolates down/RGB fades in DP25.
        // Raw firmware timing and our minimum wait are separately calibrated;
        // neither value promises a measured physical duration on this lamp.
        'onboard_fades' => (bool) env('LIGHTING_CLOUD_ONBOARD_FADES', false),
        'native_fade_timing_byte' => (int) env('LIGHTING_CLOUD_NATIVE_FADE_TIMING_BYTE', 30),
        'native_fade_wait_ms' => (int) env('LIGHTING_CLOUD_NATIVE_FADE_WAIT_MS', 5000),
        'frame_interval_ms' => 300,
        'white_up_curve' => 'perceptual',
        'native_transitions' => (bool) env('LIGHTING_CLOUD_NATIVE_TRANSITIONS', false),
        'native_interruptions' => (bool) env('LIGHTING_CLOUD_NATIVE_INTERRUPTS', false),
        'settle_margin_ms' => 400,
        'device_id' => env('LIGHTING_CLOUD_DEVICE_ID'),
        'credentials_file' => env('LIGHTING_CLOUD_CREDENTIALS_FILE', storage_path('app/private/lighting/cloud-credentials.json')),
        'private_directory' => env('LIGHTING_CLOUD_PRIVATE_DIRECTORY', storage_path('app/private/lighting')),
        'timeout_ms' => 3000,
        'connect_timeout_ms' => 1500,
        'confirmation_timeout_ms' => 5000,
        'poll_interval_ms' => 500,
    ],
    // These names deliberately describe simulations, not captured KOJIMA presets.
    'mock' => [
        'delay_ms' => (int) env('LIGHTING_MOCK_DELAY_MS', 0),
        'failure' => env('LIGHTING_MOCK_FAILURE'), // offline | transport_timeout
        'scenes' => ['green' => 'simulation_green', 'yellow' => 'simulation_yellow', 'blue' => 'simulation_blue'],
    ],
];
