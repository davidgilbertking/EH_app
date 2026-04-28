<script setup>
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <Link
        href="/"
        aria-label="Home"
        class="fixed left-4 bottom-4 z-40 grid h-20 w-20 place-items-center rounded-full bg-neutral-800/95 text-neutral-100 shadow-2xl backdrop-blur active:scale-95 transition border border-neutral-700 hover:bg-neutral-700"
    >
        <svg
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2.2"
            stroke-linecap="round"
            stroke-linejoin="round"
            class="h-10 w-10"
        >
            <path d="M3 11l9-8 9 8" />
            <path d="M5 10v10a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V10" />
        </svg>
    </Link>
</template>
