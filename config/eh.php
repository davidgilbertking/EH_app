<?php

return [
    'default_user' => [
        'name' => env('EH_USER_NAME', 'Keeper'),
        'email' => env('EH_USER_EMAIL', 'admin@local'),
        'password' => env('EH_USER_PASSWORD', 'change-me'),
    ],

    // Path inside storage/app where audio folders live (one subdir per sound_folders.slug).
    'audio_root' => env('EH_AUDIO_ROOT', 'audio'),
];
