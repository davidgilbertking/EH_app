<script setup>
import { engine } from '@/audio/engine';
import { router, usePage } from '@inertiajs/vue3';

const props = defineProps({
    disabled: { type: Boolean, default: false },
});

const page = usePage();

function clearAll() {
    if (props.disabled) return;

    // If the track currently playing was started by one of the blobs being
    // cleared, fade it out so the user doesn't end up with an "orphan"
    // track that has no UI affordance to stop. Tracks started from header
    // phase buttons or directly from a sub-page (no blob) keep playing —
    // those are independent of the blob roster.
    const blobs = page.props.gameState?.blobs ?? [];
    const playing = engine.state.playingFolder;
    if (playing && blobs.some((b) => b.folderSlug === playing)) {
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
        class="fixed right-4 bottom-4 z-40 rounded-full bg-rose-900/90 px-6 py-5 text-base font-bold uppercase tracking-wider text-rose-50 shadow-2xl backdrop-blur active:scale-95 transition border border-rose-700 hover:bg-rose-800 disabled:opacity-30 disabled:pointer-events-none"
        :disabled="disabled"
        @click="clearAll"
    >
        Clear all
    </button>
</template>
