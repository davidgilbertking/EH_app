<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/App/Breadcrumb.vue';
import MapPage from '@/Components/App/MapPage.vue';
import { cities } from '@/Pages/Contacts/data/mainBoardSpots.js';

defineOptions({ layout: AppLayout });

const tone = 'border-orange-300/60 bg-orange-700/90 text-orange-50';
const hotspots = cities.map((c) => ({
    x: c.x,
    y: c.y,
    label: c.label,
    folderSlug: `special/disaster/city/${c.city}`,
    tone,
}));
</script>

<template>
    <Breadcrumb title="City" parent="Special › Disaster" />
    <MapPage background-url="/maps/main.jpg" :hotspots="hotspots" />
</template>
