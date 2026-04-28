<script setup>
import { engine } from '@/audio/engine';
import { useLongPress } from '@/composables/useLongPress';
import { router, usePage } from '@inertiajs/vue3';

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

function makeBindings(h) {
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
                id: 'blob-' + crypto.randomUUID(),
                label: h.label,
                folderSlug: h.folderSlug,
                mode: h.mode || null,
                tone: h.tone || null,
                imageUrl: h.imageUrl || null,
            }];
            router.post(
                '/state/blobs',
                { blobs: updated },
                { preserveState: true, preserveScroll: true, only: ['gameState'] }
            );
        },
        threshold: 1000,
    });
}

function hotspotSizing(h) {
    return h.hotspotClass || props.hotspotClass || 'px-5 py-3';
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
                class="absolute -translate-x-1/2 -translate-y-1/2 whitespace-nowrap rounded-full border-2 text-base font-semibold shadow-lg backdrop-blur active:scale-[0.95] transition"
                :class="[
                    'inline-flex items-center gap-2.5',
                    hotspotSizing(h),
                    h.tone || 'border-emerald-300/50 bg-emerald-900/85 text-emerald-50',
                    engine.state.playingFolder === h.folderSlug ? 'ring-2 ring-amber-300' : '',
                ]"
                :style="{ left: h.x + '%', top: h.y + '%' }"
                v-bind="makeBindings(h)"
            >
                <img
                    v-if="h.imageUrl"
                    :src="h.imageUrl"
                    :alt="h.label"
                    class="h-8 w-8 flex-none object-contain drop-shadow-[0_1px_3px_rgba(0,0,0,0.5)]"
                />
                <span class="leading-tight">{{ h.label }}</span>
            </button>
        </div>
    </div>
</template>
