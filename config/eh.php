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

    // Integrated loudness target used to calculate per-track normalization gain.
    'audio_loudness_target_lufs' => (float) env('EH_AUDIO_LOUDNESS_TARGET_LUFS', -18.0),

    // Do not allow normalization to push true peak above this level.
    'audio_loudness_peak_ceiling_dbtp' => (float) env('EH_AUDIO_LOUDNESS_PEAK_CEILING_DBTP', -1.0),

    // Safety clamps for per-track gain (in dB).
    'audio_loudness_max_boost_db' => (float) env('EH_AUDIO_LOUDNESS_MAX_BOOST_DB', 12.0),
    'audio_loudness_max_cut_db' => (float) env('EH_AUDIO_LOUDNESS_MAX_CUT_DB', 24.0),

    // Per-track ffmpeg loudness analysis timeout (seconds).
    'audio_loudness_analysis_timeout_sec' => (int) env('EH_AUDIO_LOUDNESS_ANALYSIS_TIMEOUT_SEC', 180),
];
