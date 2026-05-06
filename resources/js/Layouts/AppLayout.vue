<script setup>
import AppHeader from '@/Components/App/AppHeader.vue';
import HomeButton from '@/Components/App/HomeButton.vue';
import BackButton from '@/Components/App/BackButton.vue';
import PauseToggleButton from '@/Components/App/PauseToggleButton.vue';
import VolumeSlider from '@/Components/App/VolumeSlider.vue';
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
const ANCIENT_BG_FADE_MS = 1250;
const bgCurrentUrl = ref(bgUrl.value);
const bgCurrentOpacity = ref(bgUrl.value ? 1 : 0);
const bgCurrentTransitionMs = ref(ANCIENT_BG_FADE_MS);
const bgPrevUrl = ref(null);
const bgPrevOpacity = ref(0);
const hasAnyBg = computed(() => Boolean(bgCurrentUrl.value || bgPrevUrl.value));
const bgCurrentEl = ref(null);
const bgPrevEl = ref(null);
const preloadImageUrls = computed(() => page.props.assetPreload?.imageUrls ?? []);
const imageWarmupSessionKey = computed(() => {
    const userId = page.props.auth?.user?.id ?? 'guest';
    return `eh:image-warmup:v1:user:${userId}`;
});
const isInvestigatorsRoute = computed(() => path.value === '/other/investigators');
const cornerControlClass = computed(() => (
    isHome.value
        ? 'opacity-0 pointer-events-none ui-fade-500'
        : 'opacity-100 ui-fade-500'
));
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
let bgFadeTimer = null;
let bgFadeRafA = null;
let bgFadeRafB = null;
let bgFadeToken = 0;

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

function warmImageCacheOncePerSession(urls) {
    if (!Array.isArray(urls) || urls.length === 0) return;
    if (typeof window === 'undefined' || !window.sessionStorage) {
        warmImageCache(urls);
        return;
    }
    const key = imageWarmupSessionKey.value;
    if (window.sessionStorage.getItem(key) === '1') return;
    window.sessionStorage.setItem(key, '1');
    warmImageCache(urls);
}

function clearBgFadeWork() {
    if (bgFadeTimer) {
        clearTimeout(bgFadeTimer);
        bgFadeTimer = null;
    }
    if (bgFadeRafA != null && typeof window !== 'undefined') {
        window.cancelAnimationFrame(bgFadeRafA);
        bgFadeRafA = null;
    }
    if (bgFadeRafB != null && typeof window !== 'undefined') {
        window.cancelAnimationFrame(bgFadeRafB);
        bgFadeRafB = null;
    }
}

function onNextPaint(cb) {
    if (typeof window === 'undefined' || typeof window.requestAnimationFrame !== 'function') {
        cb();
        return;
    }
    bgFadeRafA = window.requestAnimationFrame(() => {
        bgFadeRafA = null;
        bgFadeRafB = window.requestAnimationFrame(() => {
            bgFadeRafB = null;
            cb();
        });
    });
}

function preloadBackground(url) {
    if (!url || typeof Image === 'undefined') return Promise.resolve();
    return new Promise((resolve) => {
        const img = new Image();
        const finish = () => {
            img.onload = null;
            img.onerror = null;
            resolve();
        };

        img.onload = finish;
        img.onerror = finish;
        img.src = url;

        // Cached images may complete synchronously.
        if (img.complete) finish();
    });
}

function clampOpacity(value) {
    return Math.max(0, Math.min(1, value));
}

function readRenderedOpacity(el, fallback = 0) {
    if (typeof window === 'undefined' || !el) return clampOpacity(fallback);
    const parsed = Number.parseFloat(window.getComputedStyle(el).opacity);
    return Number.isFinite(parsed) ? clampOpacity(parsed) : clampOpacity(fallback);
}

onMounted(() => {
    syncHeaderHeight();
    syncUiScale();
    syncRotateLandscapePrompt();
    warmImageCacheOncePerSession(preloadImageUrls.value);
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
    clearBgFadeWork();
});

// Route-dependent overlay:
//   - Home with no blobs: show the art at full strength (empty stage, hero
//     illustration of the chosen Ancient).
//   - Home with blobs, and /encounters/* and /other/*: heavy dim + blur so
//     the grid / buttons stay readable over busy illustration.
//   - Other auth pages (e.g. /login): no background at all.
const DIMMED = 'bg-[rgba(10,10,10,0.75)] backdrop-blur-md';
// Subtle veil on Home when there are no blobs yet — just enough to knock
// the saturation down so the screen doesn't feel glaring.
const LIGHT_DIM = 'bg-[rgba(10,10,10,0.40)]';
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

watch(bgUrl, async (next, prev) => {
    if (next === prev) return;
    const token = ++bgFadeToken;
    clearBgFadeWork();

    const hasNext = Boolean(next);

    if (!hasNext) {
        const startCurrentOpacity = readRenderedOpacity(bgCurrentEl.value, bgCurrentOpacity.value);
        const startPrevOpacity = readRenderedOpacity(bgPrevEl.value, bgPrevOpacity.value);

        if (!bgCurrentUrl.value && !bgPrevUrl.value) {
            bgCurrentOpacity.value = 0;
            bgPrevOpacity.value = 0;
            return;
        }

        bgCurrentTransitionMs.value = 0;
        bgCurrentOpacity.value = startCurrentOpacity;
        bgPrevOpacity.value = startPrevOpacity;

        onNextPaint(() => {
            if (token !== bgFadeToken) return;
            bgCurrentTransitionMs.value = ANCIENT_BG_FADE_MS;
            if (bgCurrentUrl.value) bgCurrentOpacity.value = 0;
            if (bgPrevUrl.value) bgPrevOpacity.value = 0;
        });

        bgFadeTimer = setTimeout(() => {
            if (token !== bgFadeToken) return;
            bgCurrentUrl.value = null;
            bgCurrentOpacity.value = 0;
            bgCurrentTransitionMs.value = ANCIENT_BG_FADE_MS;
            bgPrevUrl.value = null;
            bgPrevOpacity.value = 0;
            bgFadeTimer = null;
        }, ANCIENT_BG_FADE_MS + 120);
        return;
    }

    if (hasNext) {
        await preloadBackground(next);
        if (token !== bgFadeToken) return;
    }

    const outgoingUrl = bgCurrentUrl.value || bgPrevUrl.value || prev || null;
    let outgoingOpacity = 1;
    if (outgoingUrl && bgCurrentUrl.value === outgoingUrl) {
        outgoingOpacity = readRenderedOpacity(bgCurrentEl.value, bgCurrentOpacity.value);
    } else if (outgoingUrl && bgPrevUrl.value === outgoingUrl) {
        outgoingOpacity = readRenderedOpacity(bgPrevEl.value, bgPrevOpacity.value);
    }
    const clampedOutgoingOpacity = clampOpacity(outgoingOpacity);
    const hadPrev = Boolean(outgoingUrl);

    if (hadPrev) {
        bgPrevUrl.value = outgoingUrl;
        bgPrevOpacity.value = clampedOutgoingOpacity;
    } else {
        bgPrevUrl.value = null;
        bgPrevOpacity.value = 0;
    }

    bgCurrentUrl.value = next;
    bgCurrentTransitionMs.value = 0;
    bgCurrentOpacity.value = 0;

    onNextPaint(() => {
        if (token !== bgFadeToken) return;
        bgCurrentTransitionMs.value = ANCIENT_BG_FADE_MS;
        bgCurrentOpacity.value = 1;
        if (hadPrev) bgPrevOpacity.value = 0;
    });

    bgFadeTimer = setTimeout(() => {
        if (token !== bgFadeToken) return;
        bgPrevUrl.value = null;
        bgPrevOpacity.value = 0;
        bgFadeTimer = null;
    }, ANCIENT_BG_FADE_MS + 120);
}, { flush: 'post' });

watch(preloadImageUrls, (urls) => {
    warmImageCacheOncePerSession(urls);
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
            v-if="hasAnyBg"
            class="fixed inset-x-0 bottom-0 -z-20"
            :style="{ top: 'var(--header-h, 0px)', backgroundColor: '#0a0a0a' }"
            aria-hidden="true"
        >
            <div
                v-if="bgPrevUrl"
                ref="bgPrevEl"
                class="absolute inset-0 bg-cover bg-center transition-opacity duration-[3000ms] ease-linear"
                :style="{
                    backgroundImage: `url('${bgPrevUrl}')`,
                    opacity: String(bgPrevOpacity),
                }"
            ></div>
            <div
                v-if="bgCurrentUrl"
                ref="bgCurrentEl"
                class="absolute inset-0 bg-cover bg-center"
                :style="{
                    backgroundImage: `url('${bgCurrentUrl}')`,
                    opacity: String(bgCurrentOpacity),
                    transition: `opacity ${bgCurrentTransitionMs}ms linear`,
                }"
            ></div>
        </div>
        <div
            v-if="hasAnyBg && overlayClass"
            class="fixed inset-x-0 bottom-0 -z-10"
            :class="overlayClass"
            :style="{ top: 'var(--header-h, 0px)' }"
            aria-hidden="true"
        ></div>

        <div ref="headerWrapEl">
            <AppHeader />
        </div>

        <HomeButton :class="cornerControlClass" />
        <BackButton :class="cornerControlClass" />
        <PauseToggleButton />
        <VolumeSlider />

        <main :class="mainClass">
            <Transition name="ui-page-fade" mode="out-in">
                <div :key="$page.url" class="min-h-full">
                    <slot />
                </div>
            </Transition>
        </main>

        <div
            v-if="showRotateLandscapePrompt"
            class="fixed inset-0 z-[90] flex items-center justify-center bg-[#04080f]/95 px-6 text-center backdrop-blur-md"
        >
            <div
                aria-hidden="true"
                class="relative w-[18rem] max-w-[82vw] aspect-[6/4] rounded-xl border-2 border-[#f2c94c] bg-black shadow-[0_0_28px_rgba(242,201,76,0.22)]"
            >
                <div
                    class="absolute left-1/2 top-1/2 h-[31.25%] w-[41.6667%] -translate-x-1/2 -translate-y-1/2 rounded-[0.22rem] border-2 border-[#f2c94c]"
                >
                    <div class="absolute bottom-[25%] left-[87.5%] h-1/2 w-[2px] -translate-x-1/2 bg-[#f2c94c]"></div>
                </div>
            </div>
        </div>
    </div>
</template>
