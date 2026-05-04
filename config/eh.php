<?php

return [
    'default_user' => [
        'name' => env('EH_USER_NAME', 'Keeper'),
        'email' => env('EH_USER_EMAIL', 'admin@local'),
        'password' => env('EH_USER_PASSWORD', 'change-me'),
    ],

    // Path inside storage/app where audio folders live (one subdir per sound_folders.slug).
    'audio_root' => env('EH_AUDIO_ROOT', 'audio'),

    // When enabled, AudioController returns X-Accel-Redirect so nginx serves
    // bytes directly from disk (faster than PHP streaming under network jitter).
    'audio_accel_enabled' => env('EH_AUDIO_ACCEL_ENABLED', false),

    // Internal nginx location prefix mapped to storage/app/private/.
    // Example nginx block:
    //   location /_protected-audio/ { internal; alias /var/www/EH_app/storage/app/private/; }
    'audio_accel_internal_prefix' => env('EH_AUDIO_ACCEL_INTERNAL_PREFIX', '/_protected-audio/'),
];
