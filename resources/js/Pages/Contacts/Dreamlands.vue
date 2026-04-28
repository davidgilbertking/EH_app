<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/App/Breadcrumb.vue';
import MapPage from '@/Components/App/MapPage.vue';

defineOptions({ layout: AppLayout });

const wild = 'border-green-300/60 bg-green-800/90 text-green-50';
const city = 'border-blue-300/60 bg-blue-800/90 text-blue-50';
const hotspots = [
    { x: 49, y: 13, label: 'Unknown Kadath',    folderSlug: 'contacts/dreamlands/unknown-kadath', tone: wild },
    { x: 20, y: 28, label: 'Enchanted Wood',    folderSlug: 'contacts/dreamlands/enchanted-wood', tone: wild },
    { x: 76.5, y: 27, label: 'Celephaïs',         folderSlug: 'contacts/dreamlands/celephais',      tone: city },
    { x: 24.5, y: 44, label: 'Ulthar',            folderSlug: 'contacts/dreamlands/ulthar',         tone: city },
    { x: 65.5, y: 50, label: 'Dylath-Leen',       folderSlug: 'contacts/dreamlands/dylath-leen',    tone: city },
    { x: 20, y: 78, label: 'The Underworld',    folderSlug: 'contacts/dreamlands/underworld',     tone: wild },
    { x: 81, y: 74.5, label: 'The Moon',          folderSlug: 'contacts/dreamlands/moon',           tone: wild },
];
</script>

<template>
    <Breadcrumb title="Dreamlands" parent="Contacts › Add. Map" />
    <MapPage background-url="/maps/dreamlands.jpg" :hotspots="hotspots" aspect="2 / 3" />
</template>
