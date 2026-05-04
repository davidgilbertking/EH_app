<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/App/Breadcrumb.vue';
import ButtonGrid from '@/Components/App/ButtonGrid.vue';
import { router, usePage } from '@inertiajs/vue3';
import { computed, onMounted } from 'vue';

defineOptions({ layout: AppLayout });

const page = usePage();
const ancient = computed(() => page.props.gameState?.ancientOne ?? null);

// Inertia back/forward restores page from history snapshot.
// Refresh gameState on page enter so "Pick Ancient One" button always shows
// current selection after returning from /other/ancient-ones.
onMounted(() => {
    router.reload({
        only: ['gameState'],
        preserveState: true,
        preserveScroll: true,
    });
});

const elderBtn = computed(() => {
    if (ancient.value) {
        return {
            type: 'play',
            folderSlug: `ancient/${ancient.value.slug}`,
            label: ancient.value.name,
            tone: 'bg-black hover:bg-neutral-900 text-red-500 border-neutral-700',
        };
    }
    return {
        type: 'nav',
        label: 'Pick Ancient One',
        labelClass: 'max-[640px]:whitespace-nowrap max-[640px]:text-[0.82em] max-[640px]:tracking-normal',
        href: '/other/ancient-ones',
        tone: 'bg-zinc-300 hover:bg-zinc-200 text-zinc-900 border-zinc-100',
    };
});

const rows = computed(() => [
    [
        elderBtn.value,
        { type: 'nav', label: 'General',     href: '/encounters/general',     variant: 'wilderness' },
        { type: 'nav', label: 'Restriction', href: '/encounters/restriction', variant: 'obstruction' },
    ],
    [
        { type: 'nav', label: 'Named Cities', href: '/encounters/named-cities', variant: 'bigCity' },
        { type: 'nav', label: 'Other World',  href: '/encounters/other-world',  variant: 'otherWorld' },
        { type: 'nav', label: 'Defeated',     href: '/encounters/defeated',     variant: 'defeated' },
    ],
    [
        { type: 'nav', label: 'Quest',       href: '/encounters/quest',       variant: 'expedition' },
        { type: 'nav', label: 'Side Boards', href: '/encounters/side-boards', variant: 'addmap' },
        { type: 'play', label: 'Devastation', folderSlug: 'contacts/devastation', variant: 'devastation' },
    ],
]);
</script>

<template>
    <Breadcrumb title="Encounters" />
    <ButtonGrid :rows="rows" :cols="3" />
</template>
