<script setup>
import AppHeader from '@/Components/App/AppHeader.vue';
import HomeButton from '@/Components/App/HomeButton.vue';
import BackButton from '@/Components/App/BackButton.vue';
import { usePage } from '@inertiajs/vue3';
import { computed, onMounted } from 'vue';

const page = usePage();
const url = computed(() => page.url || '/');
const isHome = computed(() => url.value === '/');
const ancient = computed(() => page.props.gameState?.ancientOne ?? null);
const blobs = computed(() => page.props.gameState?.blobs ?? []);
const bgUrl = computed(() => ancient.value?.bgImageUrl || ancient.value?.imageUrl || null);

// Route-dependent overlay:
//   - Home with no blobs: show the art at full strength (empty stage, hero
//     illustration of the chosen Ancient).
//   - Home with blobs, and /contacts/* and /special/*: heavy dim + blur so
//     the grid / buttons stay readable over busy illustration.
//   - Other auth pages (e.g. /login): no background at all.
const DIMMED = 'bg-black/75 backdrop-blur-md';
// Subtle veil on Home when there are no blobs yet — just enough to knock
// the saturation down so the screen doesn't feel glaring.
const LIGHT_DIM = 'bg-black/40';
const overlayClass = computed(() => {
    if (isHome.value) return blobs.value.length ? DIMMED : LIGHT_DIM;
    if (url.value.startsWith('/contacts') || url.value.startsWith('/special')) {
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
    <div class="min-h-screen text-neutral-100 select-none">
        <!--
            Ancient-One background + dim overlay.
            Sits at -z-20 / -z-10 so it never intercepts taps. Both layers
            appear only when an Ancient is selected AND the current route
            has an overlay rule (Home / Contacts / Special).
        -->
        <div
            v-if="bgUrl"
            class="fixed inset-0 -z-20 bg-cover bg-center"
            :style="{ backgroundImage: `url('${bgUrl}')` }"
            aria-hidden="true"
        ></div>
        <div
            v-if="bgUrl && overlayClass"
            class="fixed inset-0 -z-10"
            :class="overlayClass"
            aria-hidden="true"
        ></div>

        <AppHeader />

        <HomeButton v-if="!isHome" />
        <BackButton v-if="!isHome" />

        <main class="px-4 pb-28 pt-3">
            <slot />
        </main>
    </div>
</template>
