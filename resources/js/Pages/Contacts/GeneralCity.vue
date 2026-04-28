<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/App/Breadcrumb.vue';
import MapPage from '@/Components/App/MapPage.vue';
import { citySpaces } from './data/mainBoardSpots.js';

defineOptions({ layout: AppLayout });

// Each city space has its own folder.
const tone = 'border-neutral-300/40 bg-neutral-900/85 text-neutral-100';
const hotspots = citySpaces.map((c) => ({
    x: c.x, y: c.y, label: c.label,
    folderSlug: `contacts/general/city/${c.slug}`,
    tone,
}));
</script>

<template>
    <Breadcrumb title="City" parent="Encounters › General" />
    <MapPage background-url="/maps/main.jpg" :hotspots="hotspots" />
</template>
