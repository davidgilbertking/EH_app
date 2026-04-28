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
        class="fixed top-24 left-4 z-40 grid h-20 w-20 place-items-center rounded-full bg-neutral-800/95 text-neutral-100 border border-neutral-700 shadow-2xl backdrop-blur hover:bg-neutral-700 active:scale-95 transition"
        @click="goBack"
    >
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" class="h-10 w-10">
            <path d="M15 18l-6-6 6-6" />
        </svg>
    </button>
</template>
