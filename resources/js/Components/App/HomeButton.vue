<script setup>
import { gameFlow } from '@/gameFlow/controller';
import { router } from '@inertiajs/vue3';
import { onUnmounted } from 'vue';

const HOLD_TO_LOGOUT_MS = 3000;
const HOLD_TO_XMAS_TOGGLE_MS = 7000;
const MOVE_TOLERANCE_PX = 28;

let isLoggingOut = false;
let holdTimer = null;
let pointerId = null;
let pressedAt = 0;
let startX = 0;
let startY = 0;
let xmasToggled = false;

function goHome() {
    router.get('/');
}

function logout() {
    if (isLoggingOut) return;

    isLoggingOut = true;
    const { fadePromise, releasePromise } = gameFlow.logout();

    // Close lighting control while the authenticated session still exists.
    releasePromise.finally(() => router.post('/logout', {}, {
        onSuccess: () => {
            fadePromise.finally(() => window.location.reload());
        },
        onError: () => {
            isLoggingOut = false;
        },
        onCancel: () => {
            isLoggingOut = false;
        },
    }));
}

function clearHold() {
    if (holdTimer !== null) clearTimeout(holdTimer);
    holdTimer = null;
    pointerId = null;
}

function toggleXmasMode() {
    if (xmasToggled) return;
    xmasToggled = true;
    router.post('/xmas-mode/toggle', {}, {
        onSuccess: () => window.location.reload(),
    });
}

function onPointerDown(event) {
    if (pointerId !== null || (event.pointerType === 'mouse' && event.button !== 0)) return;
    if (event.cancelable) event.preventDefault();
    pointerId = event.pointerId;
    pressedAt = performance.now();
    startX = event.clientX;
    startY = event.clientY;
    xmasToggled = false;
    holdTimer = setTimeout(() => {
        holdTimer = null;
        toggleXmasMode();
    }, HOLD_TO_XMAS_TOGGLE_MS);
}

function onPointerMove(event) {
    if (event.pointerId !== pointerId) return;
    if (Math.hypot(event.clientX - startX, event.clientY - startY) > MOVE_TOLERANCE_PX) {
        clearHold();
    }
}

function onPointerUp(event) {
    if (event.pointerId !== pointerId) return;
    const heldFor = performance.now() - pressedAt;
    const didToggle = xmasToggled;
    clearHold();
    if (didToggle) return;
    if (heldFor >= HOLD_TO_XMAS_TOGGLE_MS) {
        toggleXmasMode();
        return;
    }
    if (heldFor >= HOLD_TO_LOGOUT_MS) logout();
    else goHome();
}

onUnmounted(clearHold);
</script>

<template>
    <button
        type="button"
        aria-label="Home"
        title="Hold 3–7s to log out; hold 7s to toggle New Year mode"
        class="ui-corner-btn fixed left-[var(--ui-corner-edge-gap)] bottom-[var(--ui-corner-edge-gap)] z-40 grid place-items-center rounded-full border border-neutral-700 bg-neutral-800/95 text-neutral-100 shadow-2xl backdrop-blur active:scale-95 transition hover:bg-neutral-700"
        @keyup.enter.prevent="goHome"
        @keyup.space.prevent="goHome"
        @pointerdown="onPointerDown"
        @pointermove="onPointerMove"
        @pointerup="onPointerUp"
        @pointercancel="clearHold"
        @pointerleave="clearHold"
        @touchstart.prevent
        @dragstart.prevent
        @contextmenu.prevent
        style="-webkit-touch-callout: none; user-select: none; touch-action: manipulation"
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
