<script setup>
import { engine } from '@/audio/engine';
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
const MAP_LONG_PRESS_MS = 450;
const MAP_MOVE_TOLERANCE_PX = 24;
const bindingsCache = new Map();

function pulseBlobSaved(key) {
    if (!key) return;
    pulseByKey[key] = true;
    setTimeout(() => {
        pulseByKey[key] = false;
    }, 360);
}

function playHotspot(h) {
    if (engine.state.playingFolder === h.folderSlug) {
        engine.stop();
        return;
    }
    engine.play({
        folderSlug: h.folderSlug,
        mode: h.mode || null,
        label: h.label,
        crossfade: true,
    });
}

function addHotspotBlob(h, pulseKey) {
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
}

function createMapBindings(h, pulseKey) {
    let timer = null;
    let firedLong = false;
    let startX = 0;
    let startY = 0;
    let touchId = null;

    const clear = () => {
        if (timer) {
            clearTimeout(timer);
            timer = null;
        }
    };

    const start = (x, y) => {
        firedLong = false;
        startX = x;
        startY = y;
        clear();
        timer = setTimeout(() => {
            firedLong = true;
            timer = null;
            addHotspotBlob(h, pulseKey);
        }, MAP_LONG_PRESS_MS);
    };

    const move = (x, y) => {
        if (!timer) return;
        if (Math.hypot(x - startX, y - startY) > MAP_MOVE_TOLERANCE_PX) {
            clear();
        }
    };

    const end = () => {
        const wasTimerActive = Boolean(timer);
        clear();
        if (firedLong) return;
        if (wasTimerActive) playHotspot(h);
    };

    const findTrackedTouch = (touchList) => {
        if (!touchList) return null;
        for (let i = 0; i < touchList.length; i += 1) {
            const t = touchList[i];
            if (touchId == null || t.identifier === touchId) return t;
        }
        return null;
    };

    return {
        onPointerdown: (e) => {
            if (e.pointerType === 'touch') return;
            start(e.clientX ?? 0, e.clientY ?? 0);
        },
        onPointermove: (e) => {
            if (e.pointerType === 'touch') return;
            move(e.clientX ?? 0, e.clientY ?? 0);
        },
        onPointerup: (e) => {
            if (e.pointerType === 'touch') return;
            end();
        },
        onPointercancel: clear,
        onPointerleave: clear,
        onTouchstart: (e) => {
            const t = findTrackedTouch(e.changedTouches) || findTrackedTouch(e.touches);
            if (!t) return;
            touchId = t.identifier;
            if (e.cancelable) e.preventDefault();
            start(t.clientX ?? 0, t.clientY ?? 0);
        },
        onTouchmove: (e) => {
            const t = findTrackedTouch(e.changedTouches) || findTrackedTouch(e.touches);
            if (!t) return;
            if (e.cancelable) e.preventDefault();
            move(t.clientX ?? 0, t.clientY ?? 0);
        },
        onTouchend: (e) => {
            const t = findTrackedTouch(e.changedTouches) || findTrackedTouch(e.touches);
            if (!t) return;
            if (e.cancelable) e.preventDefault();
            touchId = null;
            end();
        },
        onTouchcancel: () => {
            touchId = null;
            clear();
        },
        onContextmenu: (e) => {
            e.preventDefault();
        },
        onDragstart: (e) => {
            e.preventDefault();
        },
        style: {
            WebkitTouchCallout: 'none',
            userSelect: 'none',
            touchAction: 'none',
        },
    };
}

function makeBindings(h, pulseKey) {
    const key = String(pulseKey ?? h.folderSlug ?? '');
    const cached = bindingsCache.get(key);
    if (cached) return cached;
    const created = createMapBindings(h, pulseKey);
    bindingsCache.set(key, created);
    return created;
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
                class="pointer-events-none block w-auto select-none rounded-2xl border border-neutral-800 shadow-inner"
                draggable="false"
                style="-webkit-touch-callout: none; max-height: calc(100dvh - var(--header-h, 0px) - 2.75rem); object-fit: contain"
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
                    class="pointer-events-none h-[clamp(1rem,calc(2rem*var(--ui-scale)),2rem)] w-[clamp(1rem,calc(2rem*var(--ui-scale)),2rem)] flex-none select-none object-contain drop-shadow-[0_1px_3px_rgba(0,0,0,0.5)]"
                    :class="h.hideLabelOnPhone ? 'map-hotspot-icon-only-phone-img' : ''"
                    draggable="false"
                    style="-webkit-touch-callout: none;"
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
