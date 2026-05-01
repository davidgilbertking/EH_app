<script setup>
import { engine } from '@/audio/engine';
import { useLongPress } from '@/composables/useLongPress';
import { makeBlobId } from '@/utils/blobId';
import { router, usePage } from '@inertiajs/vue3';
import { reactive } from 'vue';

/**
 * Renders a map background with absolutely-positioned hotspot buttons.
 * Each hotspot acts like a PlayButton: tap = play, long-press = add blob.
 *
 * Hotspot shape: { x, y, label, folderSlug, mode?, tone?, imageUrl?, hotspotClass? }
 *   x, y are percentages (0-100) of the map container (centred on the value).
 */
const props = defineProps({
    backgroundUrl: { type: String, default: null },
    hotspots: { type: Array, required: true },
    aspect: { type: String, default: '3 / 2' },
    hotspotClass: { type: String, default: '' },
});

const page = usePage();
const pulseByKey = reactive({});

function pulseBlobSaved(key) {
    if (!key) return;
    pulseByKey[key] = true;
    setTimeout(() => {
        pulseByKey[key] = false;
    }, 360);
}

function makeBindings(h, pulseKey) {
    return useLongPress({
        onTap: () => {
            if (engine.state.playingFolder === h.folderSlug) {
                engine.stop();
                return;
            }
            engine.play({
                folderSlug: h.folderSlug,
                mode: h.mode || null,
                label: h.label,
            });
        },
        onLongPress: () => {
            const current = page.props.gameState?.blobs ?? [];
            if (current.find((b) => b.folderSlug === h.folderSlug)) return;
            const updated = [...current, {
                id: makeBlobId(),
                label: h.label,
                folderSlug: h.folderSlug,
                mode: h.mode || null,
                tone: h.tone || null,
                imageUrl: h.imageUrl || null,
            }];
            router.post(
                '/state/blobs',
                { blobs: updated },
                {
                    preserveState: true,
                    preserveScroll: true,
                    only: ['gameState'],
                    onSuccess: () => pulseBlobSaved(pulseKey),
                }
            );
        },
        threshold: 1000,
    });
}

function hotspotSizing(h) {
    return h.hotspotClass
        || props.hotspotClass
        || 'px-[clamp(0.35rem,calc(1.25rem*var(--ui-scale)),1.25rem)] py-[clamp(0.2rem,calc(0.75rem*var(--ui-scale)),0.75rem)]';
}
</script>

<template>
    <div class="mx-auto flex w-full items-center justify-center" style="max-height: calc(100dvh - var(--header-h, 0px) - 2.75rem)">
        <div class="relative inline-block" style="max-height: calc(100dvh - var(--header-h, 0px) - 2.75rem)">
            <img
                v-if="backgroundUrl"
                :src="backgroundUrl"
                alt=""
                class="block w-auto rounded-2xl border border-neutral-800 shadow-inner"
                style="max-height: calc(100dvh - var(--header-h, 0px) - 2.75rem); object-fit: contain"
            />
            <div
                v-else
                class="grid h-[60vh] w-[80vw] place-items-center rounded-2xl border border-dashed border-neutral-700 bg-neutral-900/50 text-xs text-neutral-500"
                :style="{ aspectRatio: aspect, maxHeight: 'calc(100dvh - var(--header-h, 0px) - 2.75rem)' }"
            >
                Map background not uploaded
            </div>

            <button
                v-for="(h, i) in hotspots"
                :key="h.folderSlug || i"
                type="button"
                class="absolute min-w-0 -translate-x-1/2 -translate-y-1/2 whitespace-nowrap rounded-full border-2 text-[clamp(0.58rem,calc(1rem*var(--ui-scale)),1rem)] font-semibold shadow-lg backdrop-blur active:scale-[0.95] transition"
                :class="[
                    'inline-flex items-center gap-2.5',
                    hotspotSizing(h),
                    h.hideLabelOnPhone ? 'map-hotspot-icon-only-phone' : '',
                    h.tone || 'border-emerald-300/50 bg-emerald-900/85 text-emerald-50',
                    engine.state.playingFolder === h.folderSlug ? 'ring-2 ring-amber-400' : '',
                    engine.state.isPaused && engine.state.pausedFolder === h.folderSlug
                        ? 'paused-amber-dash'
                        : '',
                    pulseByKey[h.folderSlug || i] ? 'ring-2 ring-amber-400' : '',
                ]"
                :style="{ left: h.x + '%', top: h.y + '%', maxWidth: h.maxWidth || 'min(45vw, 14rem)' }"
                v-bind="makeBindings(h, h.folderSlug || i)"
            >
                <img
                    v-if="h.imageUrl"
                    :src="h.imageUrl"
                    :alt="h.label"
                    class="h-[clamp(1rem,calc(2rem*var(--ui-scale)),2rem)] w-[clamp(1rem,calc(2rem*var(--ui-scale)),2rem)] flex-none object-contain drop-shadow-[0_1px_3px_rgba(0,0,0,0.5)]"
                    :class="h.hideLabelOnPhone ? 'map-hotspot-icon-only-phone-img' : ''"
                />
                <span
                    class="min-w-0 flex-1 truncate leading-tight"
                    :class="h.hideLabelOnPhone ? 'map-hotspot-hide-label-phone' : ''"
                >{{ h.label }}</span>
            </button>
        </div>
    </div>
</template>

<style scoped>
@media (max-width: 640px) and (pointer: coarse),
       (max-width: 950px) and (max-height: 500px) and (orientation: landscape) and (pointer: coarse) {
    .map-hotspot-hide-label-phone {
        display: none;
    }

    /* Keep all icon-only map chips identical size on phone */
    .map-hotspot-icon-only-phone {
        width: clamp(1.6rem, 4.625vw, 1.9rem);
        min-width: clamp(1.6rem, 4.625vw, 1.9rem);
        max-width: none !important;
        padding: clamp(0.16rem, 0.45vw, 0.21rem);
        justify-content: center;
        gap: 0;
    }

    .map-hotspot-icon-only-phone-img {
        width: clamp(1rem, 2.9vw, 1.175rem);
        height: clamp(1rem, 2.9vw, 1.175rem);
        border-radius: 9999px;
        object-fit: cover;
    }
}
</style>
