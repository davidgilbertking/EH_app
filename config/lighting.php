<?php

return [
    // Physical Cloud transport is opt-in; credentials stay in a private file.
    'driver' => env('LIGHTING_DRIVER', 'mock'),
    // Duration of the full calibrated white range (1% to 100%).
    'white_fade_ms' => (int) env('LIGHTING_WHITE_FADE_MS', 4000),
    'white_fade_down_ms' => (int) env('LIGHTING_WHITE_FADE_DOWN_MS', env('LIGHTING_WHITE_FADE_MS', 4000)),
    'white_fade_up_ms' => (int) env('LIGHTING_WHITE_FADE_UP_MS', env('LIGHTING_WHITE_FADE_MS', 4000)),
    'scene_fade_out_ms' => (int) env('LIGHTING_SCENE_FADE_OUT_MS', 2000),
    'dark_hold_ms' => (int) env('LIGHTING_DARK_HOLD_MS', 150),
    'curve' => env('LIGHTING_CURVE', 'smoothstep'), // smoothstep | linear
    'tick_ms' => (int) env('LIGHTING_TICK_MS', 100),
    'worker_timeout_ms' => 5000,
    'control_lease_ms' => (int) env('LIGHTING_CONTROL_LEASE_MS', 43200000),
    'observation_max_age_ms' => 10000,
    'history_limit' => 256,
    'white_profiles' => [
        'action' => ['brightnessPct' => 100, 'temperaturePct' => 33],
        'encounters' => ['brightnessPct' => 90, 'temperaturePct' => 20],
    ],
    'cloud' => [
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
