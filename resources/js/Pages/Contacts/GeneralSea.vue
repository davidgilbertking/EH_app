<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/App/Breadcrumb.vue';
import MapPage from '@/Components/App/MapPage.vue';
import { seaSpaces } from './data/mainBoardSpots.js';

defineOptions({ layout: AppLayout });

// Each sea space has its own folder.
const tone = 'border-sky-300/50 bg-sky-800/85 text-sky-50';
const hotspots = seaSpaces.map((s) => ({
    x: s.x, y: s.y, label: s.label,
    folderSlug: `contacts/general/sea/${s.slug}`,
    tone,
}));
</script>

<template>
    <Breadcrumb title="Sea" parent="Contacts › General" />
    <MapPage background-url="/maps/main.jpg" :hotspots="hotspots" />
</template>
