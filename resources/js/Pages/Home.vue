<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import ClearAllButton from '@/Components/App/ClearAllButton.vue';
import { engine } from '@/audio/engine';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineOptions({ layout: AppLayout });

const page = usePage();
const ancient = computed(() => page.props.gameState?.ancientOne ?? null);
const blobs = computed(() => page.props.gameState?.blobs ?? []);

function playBlob(b) {
    // Universal rule across the whole app: tapping a button whose folder is
    // already playing fades the track out (toggle-stop). Otherwise start a
    // random track from this folder.
    if (engine.state.playingFolder === b.folderSlug) {
        engine.stop();
        return;
    }
    engine.play({
        folderSlug: b.folderSlug,
        mode: b.mode || null,
        label: b.label,
    });
}
</script>

<template>
    <div class="relative min-h-[calc(100vh-9rem)]">
        <!--
            Ancient-One background art is rendered by AppLayout (shared with
            Contacts / Special branches). Home gets the lightest overlay so
            the artwork reads as the hero visual.
        -->
        <div
            v-if="blobs.length"
            class="grid gap-5 pt-6 pl-2"
            style="grid-template-columns: repeat(auto-fill, minmax(300px, 1fr))"
        >
            <button
                v-for="blob in blobs"
                :key="blob.id"
                type="button"
                class="flex items-center justify-center gap-3 rounded-2xl border px-6 py-8 text-center text-2xl font-semibold backdrop-blur ring-1 ring-white/25 shadow-lg shadow-black/50 active:scale-[0.97] transition"
                :class="[
                    blob.tone || 'border-neutral-700 bg-neutral-900/80 text-neutral-100',
                    engine.state.playingFolder === blob.folderSlug ? 'ring-2 ring-amber-400' : '',
                ]"
                @click="playBlob(blob)"
            >
                <img
                    v-if="blob.imageUrl"
                    :src="blob.imageUrl"
                    :alt="blob.label"
                    class="h-16 w-16 flex-none rounded-md object-cover ring-1 ring-black/40"
                />
                <span class="leading-tight">{{ blob.label }}</span>
            </button>
        </div>

        <ClearAllButton :disabled="!blobs.length" v-if="blobs.length" />
    </div>
</template>
