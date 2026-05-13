<script setup>
import { useLongPress } from '@/composables/useLongPress';
import { router } from '@inertiajs/vue3';

const HOLD_TO_LOGOUT_MS = 7000;

function goHome() {
    router.get('/');
}

function logout() {
    router.post('/logout', {}, {
        onSuccess: () => {
            window.location.reload();
        },
    });
}

const homeBindings = useLongPress({
    onTap: () => {
        goHome();
    },
    onLongPress: () => {
        logout();
    },
    threshold: HOLD_TO_LOGOUT_MS,
    preventDefaultOnStart: true,
});
</script>

<template>
    <button
        type="button"
        aria-label="Home"
        title="Hold 7s to log out"
        class="ui-corner-btn fixed left-[var(--ui-corner-edge-gap)] bottom-[var(--ui-corner-edge-gap)] z-40 grid place-items-center rounded-full border border-neutral-700 bg-neutral-800/95 text-neutral-100 shadow-2xl backdrop-blur active:scale-95 transition hover:bg-neutral-700"
        @keyup.enter.prevent="goHome"
        @keyup.space.prevent="goHome"
        v-bind="homeBindings"
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
    </button>
</template>
