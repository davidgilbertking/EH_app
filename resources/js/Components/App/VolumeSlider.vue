<script setup>
import { engine } from '@/audio/engine';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const isVisible = computed(() => Boolean(engine.state.playingFolder || engine.state.canResume));
const volumePercent = computed(() => Math.round((engine.state.masterVolume ?? 1) * 100));
const visibilityClass = computed(() => (
    isVisible.value
        ? 'opacity-100'
        : 'opacity-0 pointer-events-none'
));
const isPhone = ref(false);

function detectPhoneDevice() {
    if (typeof window === 'undefined' || typeof navigator === 'undefined') return false;
    const ua = navigator.userAgent || '';
    const looksLikeMobileUa = /Android|webOS|iPhone|iPod|BlackBerry|IEMobile|Opera Mini|Mobile/i.test(ua);
    const isIpad = /iPad/i.test(ua)
        || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    return looksLikeMobileUa && !isIpad;
}

function refreshPhoneFlag() {
    isPhone.value = detectPhoneDevice();
}

function onVolumeInput(event) {
    const value = Number(event?.target?.value);
    if (!Number.isFinite(value)) return;
    engine.setMasterVolume(value / 100, { smooth: true });
}

onMounted(() => {
    refreshPhoneFlag();
    window.addEventListener('resize', refreshPhoneFlag);
    window.addEventListener('orientationchange', refreshPhoneFlag);
});

onBeforeUnmount(() => {
    window.removeEventListener('resize', refreshPhoneFlag);
    window.removeEventListener('orientationchange', refreshPhoneFlag);
});
</script>

<template>
    <div
        class="ui-fade-500 ui-volume-wrap fixed right-[var(--ui-corner-edge-gap)] z-40 rounded-full border border-[#4d4d4d] bg-[#252525]/95 px-[clamp(0.14rem,calc(0.35rem*var(--ui-scale)),0.35rem)] py-[clamp(0.28rem,calc(0.8rem*var(--ui-scale)),0.8rem)] shadow-2xl backdrop-blur"
        :class="visibilityClass"
        style="
            top: calc(var(--header-h, 0px) + var(--corner-size) + var(--ui-volume-edge-gap) + var(--ui-volume-inset));
            bottom: calc(var(--corner-size) + var(--ui-volume-edge-gap) + var(--ui-volume-inset));
        "
    >
        <input
            type="range"
            min="0"
            max="100"
            step="1"
            orient="vertical"
            aria-label="Master volume"
            :class="[
                'ui-volume-range h-full',
                isPhone ? 'ui-volume-range-phone-hit' : '',
            ]"
            :value="volumePercent"
            @input="onVolumeInput"
        />
    </div>
</template>
