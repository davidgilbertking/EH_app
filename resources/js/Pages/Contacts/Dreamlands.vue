<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/App/Breadcrumb.vue';
import MapPage from '@/Components/App/MapPage.vue';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineOptions({ layout: AppLayout });

const page = usePage();
const isDreamQuest = computed(() => {
    const query = (page.url || '').split('?')[1] || '';
    return new URLSearchParams(query).get('mode') === 'dream-quest';
});

const wild = 'border-green-300/60 bg-green-800/90 text-green-50';
const city = 'border-blue-300/60 bg-blue-800/90 text-blue-50';
const dreamQuestTone = 'border-[#0b5862]/60 bg-[#0b5862] text-[#d6f7fb]';
const allHotspots = [
    { x: 49, y: 13, label: 'Unknown Kadath', folderSlug: 'contacts/dreamlands/unknown-kadath', tone: wild, dreamQuest: true },
    { x: 20, y: 28, label: 'Enchanted Wood', folderSlug: 'contacts/dreamlands/enchanted-wood', tone: wild, dreamQuest: true },
    { x: 76.5, y: 27, label: 'Celephaïs',    folderSlug: 'contacts/dreamlands/celephais',      tone: city, dreamQuest: false },
    { x: 24.5, y: 44, label: 'Ulthar',       folderSlug: 'contacts/dreamlands/ulthar',         tone: city, dreamQuest: false },
    { x: 65.5, y: 50, label: 'Dylath-Leen',  folderSlug: 'contacts/dreamlands/dylath-leen',    tone: city, dreamQuest: false },
    { x: 20, y: 78, label: 'The Underworld', folderSlug: 'contacts/dreamlands/underworld',     tone: wild, dreamQuest: true },
    { x: 81, y: 74.5, label: 'The Moon',     folderSlug: 'contacts/dreamlands/moon',           tone: wild, dreamQuest: true },
];
const hotspots = computed(() =>
    isDreamQuest.value
        ? allHotspots
            .filter((h) => h.dreamQuest)
            .map((h) => ({ ...h, tone: dreamQuestTone }))
        : allHotspots
);
const title = computed(() => (isDreamQuest.value ? 'Dream-Quest' : 'Dreamlands'));
const parent = computed(() => (isDreamQuest.value ? 'Encounters › Quest' : 'Encounters › Side Boards'));
</script>

<template>
    <Breadcrumb :title="title" :parent="parent" />
    <MapPage background-url="/maps/dreamlands.jpg" :hotspots="hotspots" aspect="2 / 3" />
</template>
