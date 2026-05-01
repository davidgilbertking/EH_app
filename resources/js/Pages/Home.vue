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

function isDisasterBlob(blob) {
    const slug = blob?.folderSlug || '';
    return slug.includes('/disaster/');
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
    return blobTextStyle(blob);
}

function blobTextStyle(blob) {
    const len = (blob?.label || '').length;
    if (len >= 110) return { fontSize: 'clamp(0.38rem, calc(0.70rem * var(--ui-scale)), 0.70rem)', lineHeight: '1.03' };
    if (len >= 90) return { fontSize: 'clamp(0.42rem, calc(0.78rem * var(--ui-scale)), 0.78rem)', lineHeight: '1.05' };
    if (len >= 70) return { fontSize: 'clamp(0.48rem, calc(0.86rem * var(--ui-scale)), 0.86rem)', lineHeight: '1.08' };
    if (len >= 45) return { fontSize: 'clamp(0.58rem, calc(1.05rem * var(--ui-scale)), 1.05rem)', lineHeight: '1.12' };
    return { fontSize: 'clamp(0.9rem, calc(1.6rem * var(--ui-scale)), 1.6rem)', lineHeight: '1.1' };
}

function blobLabelAlignClass(blob) {
    return blobImageUrl(blob) ? 'text-left' : 'text-center';
}

function blobImageClass(blob) {
    if (isOtherWorldBlob(blob)) {
        return 'ui-blob-icon flex-none object-contain drop-shadow-[0_2px_3px_rgba(0,0,0,0.6)]';
    }
    if (isDisasterBlob(blob)) {
        return 'ui-blob-icon flex-none rounded-full object-cover';
    }
    return 'ui-blob-icon flex-none rounded-md object-contain';
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
            class="ui-blob-grid mx-auto grid max-w-[1400px] grid-cols-4"
        >
            <button
                v-for="blob in blobs"
                :key="blob.id"
                type="button"
                class="ui-blob-btn flex w-[82%] justify-self-center items-center justify-center overflow-hidden rounded-2xl border text-center font-semibold backdrop-blur ring-1 ring-white/25 shadow-lg shadow-black/50 transition active:scale-[0.97]"
                :class="[
                    blob.tone || 'border-neutral-700 bg-neutral-900/80 text-neutral-100',
                    engine.state.playingFolder === blob.folderSlug ? 'outline outline-2 outline-amber-400 outline-offset-2' : '',
                    engine.state.isPaused && engine.state.pausedFolder === blob.folderSlug
                        ? 'paused-amber-dash'
                        : '',
                ]"
                @click="playBlob(blob)"
            >
                <img
                    v-if="blobImageUrl(blob)"
                    :src="blobImageUrl(blob)"
                    :alt="blob.label"
                    :class="blobImageClass(blob)"
                />
                <span
                    class="flex-1 break-words"
                    :class="blobLabelAlignClass(blob)"
                    :style="blobTextClass(blob)"
                >{{ blob.label }}</span>
            </button>
        </div>

        <ClearAllButton :disabled="!blobs.length" v-if="blobs.length" />
    </div>
</template>
