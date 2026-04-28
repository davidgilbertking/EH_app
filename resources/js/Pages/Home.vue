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

function isOtherWorldBlob(blob) {
    const slug = blob?.folderSlug || '';
    return slug.includes('/other-world/');
}

function blobImageUrl(blob) {
    if (blob?.imageUrl) return blob.imageUrl;
    const slug = blob?.folderSlug || '';
    if (slug.includes('/other-world/past/')) return '/images/other-worlds/past.png';
    if (slug.includes('/other-world/future/')) return '/images/other-worlds/future.png';
    const m = slug.match(/\/other-world\/([^/]+)$/);
    if (m && m[1]) return `/images/other-worlds/${m[1]}.png`;
    return null;
}

function blobTextClass(blob) {
    const len = (blob?.label || '').length;
    if (len >= 110) return 'text-[0.70rem] leading-[1.03]';
    if (len >= 90) return 'text-[0.78rem] leading-[1.05]';
    if (len >= 70) return 'text-[0.86rem] leading-[1.08]';
    if (len >= 45) return 'text-[1.05rem] leading-[1.12]';
    return 'text-[1.6rem] leading-tight';
}

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
    <div class="relative">
        <!--
            Ancient-One background art is rendered by AppLayout (shared with
            Encounters / Other branches). Home gets the lightest overlay so
            the artwork reads as the hero visual.
        -->
        <div
            v-if="blobs.length"
            class="mx-auto grid max-w-[1700px] grid-cols-4 gap-4 px-2 pt-3 pb-3"
        >
            <button
                v-for="blob in blobs"
                :key="blob.id"
                type="button"
                class="flex h-28 items-center justify-center gap-3 overflow-hidden rounded-2xl border px-4 py-3 text-center font-semibold backdrop-blur ring-1 ring-white/25 shadow-lg shadow-black/50 transition active:scale-[0.97]"
                :class="[
                    blob.tone || 'border-neutral-700 bg-neutral-900/80 text-neutral-100',
                    engine.state.playingFolder === blob.folderSlug ? 'ring-2 ring-amber-400' : '',
                ]"
                @click="playBlob(blob)"
            >
                <img
                    v-if="blobImageUrl(blob)"
                    :src="blobImageUrl(blob)"
                    :alt="blob.label"
                    :class="isOtherWorldBlob(blob)
                        ? 'h-[4.2rem] w-[4.2rem] flex-none object-contain drop-shadow-[0_2px_3px_rgba(0,0,0,0.6)]'
                        : 'h-[4.4rem] w-[4.4rem] flex-none rounded-md object-cover ring-1 ring-black/40'"
                />
                <span
                    class="flex-1 break-words text-left"
                    :class="blobTextClass(blob)"
                >{{ blob.label }}</span>
            </button>
        </div>

        <ClearAllButton :disabled="!blobs.length" v-if="blobs.length" />
    </div>
</template>
