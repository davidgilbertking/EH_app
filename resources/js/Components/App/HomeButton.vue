<script setup>
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <Link
        href="/"
        aria-label="Home"
        class="ui-corner-btn fixed left-[clamp(0.2rem,calc(0.5rem*var(--ui-scale)),0.5rem)] bottom-[clamp(0.15rem,calc(0.35rem*var(--ui-scale)),0.35rem)] z-40 grid place-items-center rounded-full border border-neutral-700 bg-neutral-800/95 text-neutral-100 shadow-2xl backdrop-blur active:scale-95 transition hover:bg-neutral-700"
    >
        <svg
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2.2"
            stroke-linecap="round"
            stroke-linejoin="round"
            class="ui-corner-icon"
        >
            <path d="M3 11l9-8 9 8" />
            <path d="M5 10v10a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V10" />
        </svg>
    </Link>
</template>
