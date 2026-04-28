<script setup>
defineProps({
    title: { type: String, required: true },
    parent: { type: String, default: null },
    sticky: { type: Boolean, default: false },
});
</script>

<template>
    <div
        class="mx-auto mb-4 mt-1 max-w-5xl"
        :class="sticky ? 'sticky top-0 z-20 rounded-md bg-neutral-950/85 px-3 py-2 backdrop-blur-sm' : ''"
    >
        <h1 class="truncate text-base uppercase tracking-widest text-neutral-400 md:text-lg">
            <span v-if="parent">{{ parent }} —</span>
            <span class="text-neutral-100">{{ title }}</span>
        </h1>
    </div>
</template>
