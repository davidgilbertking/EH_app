<script setup>
defineProps({
    title: { type: String, required: true },
    parent: { type: String, default: null },
    sticky: { type: Boolean, default: false },
});
</script>

<template>
    <div
        class="mx-auto mb-[clamp(0.15rem,calc(1rem*var(--ui-scale)),1rem)] mt-[clamp(0.03rem,calc(0.25rem*var(--ui-scale)),0.25rem)] max-w-5xl"
        :class="sticky ? 'sticky top-0 z-20 rounded-md bg-neutral-950/85 px-3 py-2 backdrop-blur-sm' : ''"
    >
        <h1 class="truncate text-[clamp(0.45rem,calc(1rem*var(--ui-scale)),1rem)] uppercase tracking-[0.14em] text-neutral-400 md:text-[clamp(0.55rem,calc(1.125rem*var(--ui-scale)),1.125rem)]">
            <span v-if="parent">{{ parent }} —</span>
            <span class="text-neutral-100">{{ title }}</span>
        </h1>
    </div>
</template>
