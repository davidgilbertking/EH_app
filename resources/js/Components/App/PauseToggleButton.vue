<script setup>
import { engine } from '@/audio/engine';
import { computed } from 'vue';

const isVisible = computed(() => Boolean(engine.state.playingFolder || engine.state.canResume));
const isPaused = computed(() => engine.state.isPaused);

function togglePause() {
    if (isPaused.value) {
        engine.resume();
    } else {
        engine.pause();
    }
}
</script>

<template>
    <button
        v-if="isVisible"
        type="button"
        :aria-label="isPaused ? 'Resume' : 'Pause'"
        class="ui-corner-btn fixed right-[clamp(0.45rem,calc(1rem*var(--ui-scale)),1rem)] z-40 grid place-items-center rounded-full border border-neutral-700 bg-neutral-800/95 text-neutral-100 shadow-2xl backdrop-blur hover:bg-neutral-700 active:scale-95 transition"
        style="top: calc(var(--header-h, 0px) + clamp(0.45rem, calc(1rem * var(--ui-scale)), 1rem));"
        @click="togglePause"
    >
        <svg
            v-if="!isPaused"
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 24 24"
            fill="currentColor"
            class="ui-corner-icon"
            aria-hidden="true"
        >
            <rect x="6" y="4" width="4" height="16" rx="1.2" />
            <rect x="14" y="4" width="4" height="16" rx="1.2" />
        </svg>
        <svg
            v-else
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 24 24"
            fill="currentColor"
            class="ui-corner-play-icon"
            aria-hidden="true"
        >
            <path d="M8 5v14l11-7z" />
        </svg>
    </button>
</template>
