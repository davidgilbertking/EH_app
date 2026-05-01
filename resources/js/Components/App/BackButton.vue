<script setup>
function goBack() {
    if (window.history.length > 1) window.history.back();
    else window.location.href = '/';
}
</script>

<template>
    <button
        type="button"
        aria-label="Back"
        class="ui-corner-btn fixed left-[clamp(0.2rem,calc(0.5rem*var(--ui-scale)),0.5rem)] z-40 grid place-items-center rounded-full border border-neutral-700 bg-neutral-800/95 text-neutral-100 shadow-2xl backdrop-blur hover:bg-neutral-700 active:scale-95 transition"
        style="top: calc(var(--header-h, 0px) + clamp(0.12rem, calc(0.35rem * var(--ui-scale)), 0.35rem));"
        @click="goBack"
    >
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" class="ui-corner-icon">
            <path d="M15 18l-6-6 6-6" />
        </svg>
    </button>
</template>
