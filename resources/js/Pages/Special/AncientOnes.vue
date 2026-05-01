<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/App/Breadcrumb.vue';
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    items: { type: Array, required: true },
});

const page = usePage();
const currentSlug = computed(() => page.props.gameState?.ancientOne?.slug ?? null);
const ROWS = 5;
const COLS = 4;
const GRID_SLOTS = 20;
const gridItems = computed(() => {
    const items = props.items.slice(0, GRID_SLOTS);
    const cols = Array.from({ length: COLS }, () => []);

    // Fill top-to-bottom inside each column, then move to next column.
    items.forEach((item, idx) => {
        const col = Math.floor(idx / ROWS);
        if (col < COLS) cols[col].push(item);
    });

    const ordered = [];
    for (let row = 0; row < ROWS; row++) {
        for (let col = 0; col < COLS; col++) {
            ordered.push(cols[col][row] ?? null);
        }
    }

    return ordered;
});

function pick(item) {
    router.post(
        '/state/ancient-one',
        { slug: currentSlug.value === item.slug ? null : item.slug },
        { preserveState: true, preserveScroll: true, only: ['gameState'] }
    );
}
</script>

<template>
    <Breadcrumb title="Ancient Ones" parent="Other" />

    <div class="mx-auto grid max-w-7xl grid-cols-4 gap-[clamp(0.2rem,calc(0.75rem*var(--ui-scale)),0.75rem)]">
        <button
            v-for="(item, idx) in gridItems"
            :key="item?.slug ?? `empty-${idx}`"
            type="button"
            class="flex min-h-[clamp(2.4rem,calc(4.7rem*var(--ui-scale)),4.7rem)] items-center gap-[clamp(0.2rem,calc(0.5rem*var(--ui-scale)),0.5rem)] rounded-xl border px-[clamp(0.35rem,calc(0.75rem*var(--ui-scale)),0.75rem)] py-[clamp(0.2rem,calc(0.5rem*var(--ui-scale)),0.5rem)] text-left active:scale-[0.98] transition"
            :class="
                !item
                    ? 'invisible'
                    : currentSlug === item.slug
                    ? 'border-amber-400 bg-amber-900/40'
                    : 'border-sky-900 bg-sky-950/60 hover:bg-sky-900/60'
            "
            @click="item && pick(item)"
        >
            <img
                v-if="item?.imageUrl"
                :src="item.imageUrl"
                :alt="item.name"
                class="h-[clamp(1.5rem,calc(3rem*var(--ui-scale)),3rem)] w-[clamp(1.5rem,calc(3rem*var(--ui-scale)),3rem)] flex-none rounded-lg object-cover"
            />
            <span
                v-else-if="item"
                class="grid h-[clamp(1.5rem,calc(3rem*var(--ui-scale)),3rem)] w-[clamp(1.5rem,calc(3rem*var(--ui-scale)),3rem)] flex-none place-items-center rounded-lg bg-sky-900/50 text-[clamp(0.5rem,calc(0.875rem*var(--ui-scale)),0.875rem)] text-sky-300"
            >?</span>
            <span
                v-if="item"
                class="text-[clamp(0.58rem,calc(1.125rem*var(--ui-scale)),1.125rem)] font-bold leading-tight tracking-wide text-sky-50"
            >{{ item.name }}</span>
        </button>
    </div>
</template>
