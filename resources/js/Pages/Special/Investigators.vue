<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/App/Breadcrumb.vue';
import { engine } from '@/audio/engine';
import { useLongPress } from '@/composables/useLongPress';
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    men: { type: Array, required: true },
    women: { type: Array, required: true },
});

const page = usePage();

// Single 4-col grid: cols 1-2 = men (top-to-bottom, then left-to-right),
// cols 3-4 = women (same flow). Row count = max of the two halves so the
// shorter group keeps its column ordering correct.
const COLS_PER_GROUP = 2;
const rows = computed(() =>
    Math.max(
        Math.ceil(props.men.length / COLS_PER_GROUP),
        Math.ceil(props.women.length / COLS_PER_GROUP),
        1
    )
);

// Subtle blue/pink tint — borders + a touch of background colour.
const MEN_TONE   = 'border-sky-900/60 bg-sky-950/40 hover:bg-sky-900/40';
const WOMEN_TONE = 'border-pink-900/50 bg-pink-950/30 hover:bg-pink-900/30';

const placedMen = computed(() =>
    props.men.map((item, idx) => ({
        ...item,
        col: Math.floor(idx / rows.value) + 1,
        row: (idx % rows.value) + 1,
        tone: MEN_TONE,
    }))
);
const placedWomen = computed(() =>
    props.women.map((item, idx) => ({
        ...item,
        col: Math.floor(idx / rows.value) + 1 + COLS_PER_GROUP,
        row: (idx % rows.value) + 1,
        tone: WOMEN_TONE,
    }))
);
const allItems = computed(() => [...placedMen.value, ...placedWomen.value]);

function makeBindings(item) {
    return useLongPress({
        onTap: () => engine.play({ folderSlug: item.folderSlug, label: item.name }),
        onLongPress: () => {
            const current = page.props.gameState?.blobs ?? [];
            if (current.find((b) => b.folderSlug === item.folderSlug)) return;
            const updated = [
                ...current,
                {
                    id: 'blob-' + crypto.randomUUID(),
                    label: item.name,
                    folderSlug: item.folderSlug,
                    mode: null,
                    tone: item.tone,
                    imageUrl: item.imageUrl || null,
                },
            ];
            router.post(
                '/state/blobs',
                { blobs: updated },
                { preserveState: true, preserveScroll: true, only: ['gameState'] }
            );
        },
        threshold: 1000,
    });
}
</script>

<template>
    <Breadcrumb title="Investigators" parent="Special" />

    <div class="mx-auto max-w-6xl">
        <div
            class="grid gap-2"
            :style="{
                gridTemplateColumns: `repeat(${COLS_PER_GROUP * 2}, minmax(0, 1fr))`,
                gridTemplateRows: `repeat(${rows}, minmax(0, 1fr))`,
            }"
        >
            <button
                v-for="item in allItems"
                :key="item.slug"
                type="button"
                class="flex items-center gap-3 rounded-xl border px-3 py-2 text-left active:scale-[0.98] transition"
                :class="[
                    item.tone,
                    engine.state.playingFolder === item.folderSlug ? 'ring-2 ring-amber-400' : '',
                ]"
                :style="{ gridColumn: item.col, gridRow: item.row }"
                v-bind="makeBindings(item)"
            >
                <img
                    v-if="item.imageUrl"
                    :src="item.imageUrl"
                    :alt="item.name"
                    class="h-12 w-12 flex-none rounded object-cover"
                />
                <div
                    v-else
                    class="grid h-12 w-12 flex-none place-items-center rounded bg-neutral-800 text-xs text-neutral-500"
                >?</div>
                <span class="text-sm font-semibold text-neutral-100">{{ item.name }}</span>
            </button>
        </div>
    </div>
</template>
