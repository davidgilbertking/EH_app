<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/App/Breadcrumb.vue';
import MapPage from '@/Components/App/MapPage.vue';
import { citiesDisaster } from '@/Pages/Contacts/data/mainBoardSpots.js';

defineOptions({ layout: AppLayout });

const tone = 'border-neutral-300/40 bg-neutral-900/85 text-neutral-100';
const icon = (slug) => `/images/disaster/${slug}.png`;
const hotspots = citiesDisaster.map((c) => ({
    x: c.x,
    y: c.y,
    label: c.label,
    folderSlug: `special/disaster/city/${c.city}`,
    tone,
    imageUrl: icon(c.city),
}));
</script>

<template>
    <Breadcrumb title="Major Cities" parent="Other › Disaster" />
    <MapPage background-url="/maps/main.jpg" :hotspots="hotspots" />
</template>
