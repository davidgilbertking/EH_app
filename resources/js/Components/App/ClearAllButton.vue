<script setup>
import { engine } from '@/audio/engine';
import { router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    disabled: { type: Boolean, default: false },
});

const page = usePage();
const broomMissing = ref(false);

function clearAll() {
    if (props.disabled) return;

    // If the track currently playing was started by one of the blobs being
    // cleared, fade it out so the user doesn't end up with an "orphan"
    // track that has no UI affordance to stop. Tracks started from header
    // phase buttons or directly from a sub-page (no blob) keep playing —
    // those are independent of the blob roster.
    const blobs = page.props.gameState?.blobs ?? [];
    const activeOrPausedFolder = engine.state.playingFolder || engine.state.pausedFolder;
    if (activeOrPausedFolder && blobs.some((b) => b.folderSlug === activeOrPausedFolder)) {
        engine.stop();
    }

    router.delete('/state/blobs', {
        preserveState: true,
        preserveScroll: true,
        only: ['gameState'],
    });
}
</script>

<template>
    <button
        type="button"
        aria-label="Clear all blobs"
        class="ui-corner-btn fixed right-[var(--ui-corner-edge-gap)] bottom-[var(--ui-corner-edge-gap)] z-40 grid place-items-center rounded-full border border-neutral-700 bg-neutral-800/95 text-neutral-100 shadow-2xl backdrop-blur transition hover:bg-neutral-700 active:scale-95 disabled:pointer-events-none"
        :disabled="disabled"
        @click="clearAll"
    >
        <img
            v-if="!broomMissing"
            src="/icons/broom-clear.png"
            alt=""
            class="ui-corner-icon object-contain brightness-0 invert"
            @error="broomMissing = true"
        />
        <svg
            v-else
            xmlns="http://www.w3.org/2000/svg"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="1.9"
            stroke-linecap="round"
            stroke-linejoin="round"
            class="ui-corner-icon"
            aria-hidden="true"
        >
            <circle cx="5" cy="8" r="1.5" />
            <circle cx="8.5" cy="6.2" r="1" />
            <circle cx="19.5" cy="18" r="1.5" />
            <path d="M14.5 2.5l7 7" />
            <path d="M13.2 3.8l7 7" />
            <path d="M13.9 9.7c-1.9-.2-4 .8-5.6 2.4L3 17.4l4.2 4.2 5.4-5.4c1.7-1.7 2.8-3.8 2.5-5.8z" />
            <path d="M5.7 20.1l2.2-2.2" />
            <path d="M7.8 21.9l2.4-2.4" />
            <path d="M10.2 22.6l2.4-2.4" />
        </svg>
    </button>
</template>
