<script setup>
import { engine } from '@/audio/engine';
import { computed, onBeforeUnmount, ref } from 'vue';

const isVisible = computed(() => Boolean(engine.state.playingFolder || engine.state.canResume));
const volumePercent = computed(() => Math.round((engine.state.masterVolume ?? 1) * 100));
const visibilityClass = computed(() => (
    isVisible.value
        ? 'opacity-100'
        : 'opacity-0 pointer-events-none'
));
const rangeEl = ref(null);
let activePointerId = null;
let touchDragging = false;
let lastPointerStartAt = 0;

function clampPercent(value) {
    return Math.max(0, Math.min(100, value));
}

function setVolumePercent(percent) {
    const clamped = clampPercent(percent);
    engine.setMasterVolume(clamped / 100, { smooth: true });
}

function setVolumeFromClientY(clientY) {
    const el = rangeEl.value;
    if (!el) return;

    const rect = el.getBoundingClientRect();
    if (!rect.height) return;

    const ratio = (rect.bottom - clientY) / rect.height;
    const percent = clampPercent(Math.round(ratio * 100));
    setVolumePercent(percent);
}

function stopPointerDrag() {
    activePointerId = null;
    window.removeEventListener('pointermove', onWindowPointerMove);
    window.removeEventListener('pointerup', onWindowPointerEnd);
    window.removeEventListener('pointercancel', onWindowPointerEnd);
}

function onWindowPointerMove(event) {
    if (activePointerId !== null && event.pointerId !== activePointerId) return;
    setVolumeFromClientY(event.clientY);
    event.preventDefault();
}

function onWindowPointerEnd(event) {
    if (activePointerId !== null && event.pointerId !== activePointerId) return;
    stopPointerDrag();
}

function onSliderPointerDown(event) {
    if (event.button !== undefined && event.button !== 0) return;
    lastPointerStartAt = Date.now();
    activePointerId = event.pointerId ?? null;
    setVolumeFromClientY(event.clientY);
    window.addEventListener('pointermove', onWindowPointerMove, { passive: false });
    window.addEventListener('pointerup', onWindowPointerEnd);
    window.addEventListener('pointercancel', onWindowPointerEnd);
    event.preventDefault();
}

function stopTouchDrag() {
    touchDragging = false;
    window.removeEventListener('touchmove', onWindowTouchMove);
    window.removeEventListener('touchend', onWindowTouchEnd);
    window.removeEventListener('touchcancel', onWindowTouchEnd);
}

function onWindowTouchMove(event) {
    if (!touchDragging) return;
    const touch = event.touches?.[0];
    if (!touch) return;
    setVolumeFromClientY(touch.clientY);
    event.preventDefault();
}

function onWindowTouchEnd() {
    stopTouchDrag();
}

function onSliderTouchStart(event) {
    // On many iOS builds touch + pointer can both fire; prefer pointer path.
    if (Date.now() - lastPointerStartAt < 250) return;

    const touch = event.touches?.[0];
    if (!touch) return;

    touchDragging = true;
    setVolumeFromClientY(touch.clientY);
    window.addEventListener('touchmove', onWindowTouchMove, { passive: false });
    window.addEventListener('touchend', onWindowTouchEnd);
    window.addEventListener('touchcancel', onWindowTouchEnd);
    event.preventDefault();
}

function onVolumeInput(event) {
    const value = Number(event?.target?.value);
    if (!Number.isFinite(value)) return;
    engine.setMasterVolume(value / 100, { smooth: true });
}

onBeforeUnmount(() => {
    stopPointerDrag();
    stopTouchDrag();
});
</script>

<template>
    <div
        class="ui-fade-500 ui-volume-wrap fixed right-[var(--ui-corner-edge-gap)] z-40 rounded-full border border-[#4d4d4d] bg-[#252525]/95 px-[clamp(0.14rem,calc(0.35rem*var(--ui-scale)),0.35rem)] py-[clamp(0.28rem,calc(0.8rem*var(--ui-scale)),0.8rem)] shadow-2xl backdrop-blur"
        :class="visibilityClass"
        @pointerdown="onSliderPointerDown"
        @touchstart.prevent="onSliderTouchStart"
        style="
            top: calc(var(--header-h, 0px) + var(--corner-size) + var(--ui-volume-edge-gap) + var(--ui-volume-inset));
            bottom: calc(var(--corner-size) + var(--ui-volume-edge-gap) + var(--ui-volume-inset));
        "
    >
        <input
            ref="rangeEl"
            type="range"
            min="0"
            max="100"
            step="1"
            orient="vertical"
            aria-label="Master volume"
            class="ui-volume-range h-full"
            :value="volumePercent"
            @input="onVolumeInput"
        />
    </div>
</template>
