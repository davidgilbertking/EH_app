<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/App/Breadcrumb.vue';
import MapPage from '@/Components/App/MapPage.vue';

defineOptions({ layout: AppLayout });

// Approximate percentages tuned to the user-provided board photo. Fine-tune in browser.
const camp  = 'border-yellow-200 bg-yellow-400/95 text-yellow-950';
const alien = 'border-indigo-300/60 bg-indigo-800/90 text-indigo-50';
const hotspots = [
    { x: 11,  y: 35, label: 'City of Elder Things', folderSlug: 'contacts/antarctica/city-of-elder-things', tone: alien },
    { x: 33, y: 20, label: 'Frozen Waste',         folderSlug: 'contacts/antarctica/frozen-waste',         tone: camp },
    { x: 60, y: 20, label: 'Lake Camp',            folderSlug: 'contacts/antarctica/lake-camp',            tone: camp },
    { x: 90, y: 25, label: 'Miskatonic Outpost',   folderSlug: 'contacts/antarctica/miskatonic-outpost',   tone: camp },
    { x: 13, y: 74, label: 'Plateau of Leng',      folderSlug: 'contacts/antarctica/plateau-of-leng',      tone: alien },
    { x: 40.5, y: 75, label: 'Snowy Mountains',      folderSlug: 'contacts/antarctica/snowy-mountains',      tone: alien },
];
</script>

<template>
    <Breadcrumb title="Antarctica" parent="Encounters › Side Boards" />
    <MapPage background-url="/maps/antarctica.jpg" :hotspots="hotspots" />
</template>
