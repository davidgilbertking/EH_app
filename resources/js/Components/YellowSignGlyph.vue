<script setup>
import { buildYellowSignPaths } from '@/utils/yellowSign';
import { computed } from 'vue';

const props = defineProps({
    seed: { type: [String, Number], default: null },
    color: { type: String, default: '#f2c94c' },
    strokeWidth: { type: Number, default: 4 },
    glow: { type: Boolean, default: true },
});

const paths = computed(() => buildYellowSignPaths(props.seed ?? 'the-yellow-sign'));
</script>

<template>
    <svg
        viewBox="0 0 64 64"
        fill="none"
        :stroke="color"
        :stroke-width="strokeWidth"
        stroke-linecap="round"
        stroke-linejoin="round"
        class="h-full w-full"
        :class="glow ? 'drop-shadow-[0_0_10px_rgba(242,201,76,0.65)]' : ''"
        aria-hidden="true"
    >
        <path
            v-for="(d, idx) in paths"
            :key="idx"
            :d="d"
        />
    </svg>
</template>
