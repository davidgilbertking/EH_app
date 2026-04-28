<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/App/Breadcrumb.vue';
import MapPage from '@/Components/App/MapPage.vue';
import { wildernessSpaces } from './data/mainBoardSpots.js';

defineOptions({ layout: AppLayout });

// Each wilderness space has its own folder.
const tone = 'border-green-300/50 bg-green-900/85 text-green-50';
const hotspots = wildernessSpaces.map((w) => ({
    x: w.x, y: w.y, label: w.label,
    folderSlug: `contacts/general/wilderness/${w.slug}`,
    tone,
}));
</script>

<template>
    <Breadcrumb title="Wilderness" parent="Encounters › General" />
    <MapPage background-url="/maps/main.jpg" :hotspots="hotspots" />
</template>
