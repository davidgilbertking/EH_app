<script setup>
defineProps({
    title: { type: String, required: true },
    parent: { type: String, default: null },
});
</script>

<template>
    <div class="mx-auto mb-4 mt-1 max-w-5xl">
        <h1 class="truncate text-sm uppercase tracking-widest text-neutral-400">
            <span v-if="parent">{{ parent }} —</span>
            <span class="text-neutral-100">{{ title }}</span>
        </h1>
    </div>
</template>
