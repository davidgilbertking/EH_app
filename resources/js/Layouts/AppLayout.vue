<script setup>
import AppHeader from '@/Components/App/AppHeader.vue';
import HomeButton from '@/Components/App/HomeButton.vue';
import BackButton from '@/Components/App/BackButton.vue';
import PauseToggleButton from '@/Components/App/PauseToggleButton.vue';
import { warmImageCache } from '@/composables/useImageCacheWarmup';
import { usePage } from '@inertiajs/vue3';
import { computed, onMounted, onBeforeUnmount, ref, watch } from 'vue';

const page = usePage();
const url = computed(() => page.url || '/');
const path = computed(() => url.value.split('?')[0]);
const isHome = computed(() => url.value === '/');
const ancient = computed(() => page.props.gameState?.ancientOne ?? null);
const blobs = computed(() => page.props.gameState?.blobs ?? []);
const bgUrl = computed(() => ancient.value?.bgImageUrl || ancient.value?.imageUrl || null);
const preloadImageUrls = computed(() => page.props.assetPreload?.imageUrls ?? []);
const isInvestigatorsRoute = computed(() => path.value === '/other/investigators');
const showRotateLandscapePrompt = ref(false);
const BASE_WIDTH = 1512;
const BASE_HEIGHT = 982;
const mainClass = computed(() =>
    isInvestigatorsRoute.value
        ? 'box-border px-[clamp(0.45rem,calc(1rem*var(--ui-scale)),1rem)] pt-[clamp(0.2rem,calc(0.75rem*var(--ui-scale)),0.75rem)] pb-[clamp(0.35rem,calc(1.5rem*var(--ui-scale)),1.5rem)] h-[calc(100dvh-var(--header-h,0px))] overflow-y-auto overflow-x-hidden'
        : (isHome.value
            ? 'box-border px-[clamp(0.45rem,calc(1rem*var(--ui-scale)),1rem)] pt-[clamp(0.1rem,calc(0.5rem*var(--ui-scale)),0.5rem)] pb-[clamp(0.2rem,calc(1rem*var(--ui-scale)),1rem)] h-[calc(100dvh-var(--header-h,0px))] overflow-hidden'
            : 'box-border px-[clamp(0.45rem,calc(1rem*var(--ui-scale)),1rem)] pt-[clamp(0.2rem,calc(0.75rem*var(--ui-scale)),0.75rem)] pb-[clamp(0.35rem,calc(1.5rem*var(--ui-scale)),1.5rem)] h-[calc(100dvh-var(--header-h,0px))] overflow-hidden')
);

// Track the live header height and expose it as CSS var --header-h on the
// layout root. Any descendant (BG layers, BackButton, page content) can then
// use it via top-[var(--header-h)] / pt-[var(--header-h)] without hard-coding
// pixel offsets that break when the header wraps onto two lines.
const rootEl = ref(null);
const headerWrapEl = ref(null);
let resizeObserver = null;

function syncHeaderHeight() {
    if (!rootEl.value || !headerWrapEl.value) return;
    rootEl.value.style.setProperty(
        '--header-h',
        headerWrapEl.value.offsetHeight + 'px'
    );
}

function syncUiScale() {
    if (!rootEl.value) return;
    const viewportRatio = Math.min(
        window.innerWidth / BASE_WIDTH,
        window.innerHeight / BASE_HEIGHT,
        1
    );
    const uiScale = Math.max(0.34, Math.min(1, viewportRatio));
    const value = uiScale.toFixed(3);
    rootEl.value.style.setProperty('--ui-scale', value);
    document.documentElement.style.setProperty('--ui-scale', value);
}

function syncRotateLandscapePrompt() {
    if (typeof window === 'undefined') return;
    const isPhone = window.matchMedia('(max-width: 700px)').matches;
    const isTouchPrimary = window.matchMedia('(pointer: coarse)').matches;
    const isPortrait = window.matchMedia('(orientation: portrait)').matches;
    showRotateLandscapePrompt.value = isPhone && isTouchPrimary && isPortrait;
}

onMounted(() => {
    syncHeaderHeight();
    syncUiScale();
    syncRotateLandscapePrompt();
    warmImageCache(preloadImageUrls.value);
    if (typeof ResizeObserver !== 'undefined' && headerWrapEl.value) {
        resizeObserver = new ResizeObserver(syncHeaderHeight);
        resizeObserver.observe(headerWrapEl.value);
    }
    window.addEventListener('resize', syncHeaderHeight);
    window.addEventListener('resize', syncUiScale);
    window.addEventListener('resize', syncRotateLandscapePrompt);
    window.addEventListener('orientationchange', syncRotateLandscapePrompt);
});

onBeforeUnmount(() => {
    resizeObserver?.disconnect();
    window.removeEventListener('resize', syncHeaderHeight);
    window.removeEventListener('resize', syncUiScale);
    window.removeEventListener('resize', syncRotateLandscapePrompt);
    window.removeEventListener('orientationchange', syncRotateLandscapePrompt);
});

// Route-dependent overlay:
//   - Home with no blobs: show the art at full strength (empty stage, hero
//     illustration of the chosen Ancient).
//   - Home with blobs, and /encounters/* and /other/*: heavy dim + blur so
//     the grid / buttons stay readable over busy illustration.
//   - Other auth pages (e.g. /login): no background at all.
const DIMMED = 'bg-black/75 backdrop-blur-md';
// Subtle veil on Home when there are no blobs yet — just enough to knock
// the saturation down so the screen doesn't feel glaring.
const LIGHT_DIM = 'bg-black/40';
const overlayClass = computed(() => {
    if (isHome.value) return blobs.value.length ? DIMMED : LIGHT_DIM;
    if (url.value.startsWith('/encounters') || url.value.startsWith('/other')) {
        return DIMMED;
    }
    return null;
});

// Wake-lock kept alive while logged in. Re-acquired on visibility change.
let wakeLock = null;
async function acquireWakeLock() {
    if (!('wakeLock' in navigator)) return;
    try {
        wakeLock = await navigator.wakeLock.request('screen');
        wakeLock.addEventListener('release', () => { wakeLock = null; });
    } catch (_) { /* user/system denial; ignore */ }
}

onMounted(() => {
    acquireWakeLock();
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible' && !wakeLock) acquireWakeLock();
    });
});

watch(preloadImageUrls, (urls) => {
    warmImageCache(urls);
});
</script>

<template>
    <div ref="rootEl" class="h-[100dvh] min-h-0 overflow-x-clip text-neutral-100 select-none">
        <!--
            Ancient-One background + dim overlay.
            Both layers start BELOW the header (top: var(--header-h)) so the
            sticky header keeps its solid bar and the artwork breathes in the
            content area only.
        -->
        <div
            v-if="bgUrl"
            class="fixed inset-x-0 bottom-0 -z-20 bg-cover bg-center"
            :style="{
                top: 'var(--header-h, 0px)',
                backgroundImage: `url('${bgUrl}')`,
            }"
            aria-hidden="true"
        ></div>
        <div
            v-if="bgUrl && overlayClass"
            class="fixed inset-x-0 bottom-0 -z-10"
            :class="overlayClass"
            :style="{ top: 'var(--header-h, 0px)' }"
            aria-hidden="true"
        ></div>

        <div ref="headerWrapEl">
            <AppHeader />
        </div>

        <HomeButton v-if="!isHome" />
        <BackButton v-if="!isHome" />
        <PauseToggleButton />

        <main :class="mainClass">
            <slot />
        </main>

        <div
            v-if="showRotateLandscapePrompt"
            class="fixed inset-0 z-[90] flex items-center justify-center bg-[#04080f]/95 px-6 text-center backdrop-blur-md"
        >
            <div class="max-w-sm rounded-xl border border-amber-400/40 bg-black/40 px-6 py-7 shadow-[0_0_28px_rgba(242,201,76,0.22)]">
                <div
                    aria-hidden="true"
                    class="relative mx-auto h-14 w-36 overflow-hidden rounded-sm border border-[#f2c94c] bg-black"
                >
                    <div class="absolute inset-y-0 right-0 w-3 bg-[#f2c94c]"></div>
                </div>
            </div>
        </div>
    </div>
</template>
