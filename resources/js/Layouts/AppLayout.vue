<script setup>
import AppHeader from '@/Components/App/AppHeader.vue';
import HomeButton from '@/Components/App/HomeButton.vue';
import BackButton from '@/Components/App/BackButton.vue';
import { usePage } from '@inertiajs/vue3';
import { computed, onMounted, onBeforeUnmount, ref } from 'vue';

const page = usePage();
const url = computed(() => page.url || '/');
const path = computed(() => url.value.split('?')[0]);
const isHome = computed(() => url.value === '/');
const ancient = computed(() => page.props.gameState?.ancientOne ?? null);
const blobs = computed(() => page.props.gameState?.blobs ?? []);
const bgUrl = computed(() => ancient.value?.bgImageUrl || ancient.value?.imageUrl || null);
const isInvestigatorsRoute = computed(() => path.value === '/other/investigators');
const mainClass = computed(() =>
    isInvestigatorsRoute.value
        ? 'px-4 pt-3 pb-6 h-[calc(100dvh-var(--header-h,0px))] overflow-y-auto overflow-x-hidden'
        : (isHome.value
            ? 'px-4 pt-2 pb-4 h-[calc(100dvh-var(--header-h,0px))] overflow-hidden'
            : 'px-4 pt-3 pb-6 h-[calc(100dvh-var(--header-h,0px))] overflow-hidden')
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

onMounted(() => {
    syncHeaderHeight();
    if (typeof ResizeObserver !== 'undefined' && headerWrapEl.value) {
        resizeObserver = new ResizeObserver(syncHeaderHeight);
        resizeObserver.observe(headerWrapEl.value);
    }
    window.addEventListener('resize', syncHeaderHeight);
});

onBeforeUnmount(() => {
    resizeObserver?.disconnect();
    window.removeEventListener('resize', syncHeaderHeight);
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
</script>

<template>
    <div ref="rootEl" class="min-h-screen text-neutral-100 select-none">
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

        <main :class="mainClass">
            <slot />
        </main>
    </div>
</template>
