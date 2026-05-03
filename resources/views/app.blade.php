<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @php
            $iconVersion = (string) (@filemtime(public_path('icons/icon-512.png')) ?: time());
            $yellowSignSeed = (string) session('yellow_sign_seed', 'the-yellow-sign');
            $yellowSignIcon16 = route('ui.yellowSignIcon', ['seed' => $yellowSignSeed, 'size' => 16]);
            $yellowSignIcon32 = route('ui.yellowSignIcon', ['seed' => $yellowSignSeed, 'size' => 32]);
            $yellowSignIcon64 = route('ui.yellowSignIcon', ['seed' => $yellowSignSeed, 'size' => 64]);
        @endphp
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

        <meta name="theme-color" content="#0a0a0a">
        <meta name="color-scheme" content="dark">

        <!-- iPad/iPhone home-screen PWA -->
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="apple-mobile-web-app-title" content="{{ config('app.name', 'The Yellow Sign') }}">
        <!-- Put custom styled icon here (recommended 180x180 PNG) -->
        <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('icons/apple-touch-icon.png') }}?v={{ $iconVersion }}">

        <!-- Browser tab icons -->
        <link rel="icon" type="image/png" sizes="32x32" href="{{ $yellowSignIcon32 }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ $yellowSignIcon16 }}">
        <link rel="shortcut icon" type="image/png" href="{{ $yellowSignIcon64 }}">
        <link rel="alternate icon" href="{{ asset('favicon.ico') }}?v={{ $iconVersion }}">
        <link rel="manifest" href="{{ asset('manifest.webmanifest') }}?v={{ $iconVersion }}">

        <title data-inertia>{{ config('app.name', 'The Yellow Sign') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=eb-garamond:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased bg-neutral-950 text-neutral-100">
        @inertia
    </body>
</html>
