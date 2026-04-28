<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/App/Breadcrumb.vue';
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineOptions({ layout: AppLayout });

defineProps({
    items: { type: Array, required: true },
});

const page = usePage();
const currentSlug = computed(() => page.props.gameState?.ancientOne?.slug ?? null);

function pick(item) {
    router.post(
        '/state/ancient-one',
        { slug: item.slug },
        { preserveState: true, preserveScroll: true, only: ['gameState'] }
    );
}
</script>

<template>
    <Breadcrumb title="Ancient Ones" parent="Special" />

    <div class="mx-auto max-w-lg space-y-2">
        <button
            v-for="item in items"
            :key="item.slug"
            type="button"
            class="flex w-full items-center gap-3 rounded-xl border px-3 py-2.5 text-left active:scale-[0.98] transition"
            :class="
                currentSlug === item.slug
                    ? 'border-amber-400 bg-amber-900/40'
                    : 'border-sky-900 bg-sky-950/60 hover:bg-sky-900/60'
            "
            @click="pick(item)"
        >
            <img
                v-if="item.imageUrl"
                :src="item.imageUrl"
                :alt="item.name"
                class="h-16 w-16 flex-none rounded-lg object-cover"
            />
            <div
                v-else
                class="grid h-16 w-16 flex-none place-items-center rounded-lg bg-sky-900/50 text-base text-sky-300"
            >?</div>
            <span class="text-base font-bold tracking-wide text-sky-50">{{ item.name }}</span>
        </button>
    </div>
</template>
