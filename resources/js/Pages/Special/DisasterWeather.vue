<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/App/Breadcrumb.vue';
import ButtonGrid from '@/Components/App/ButtonGrid.vue';

defineOptions({ layout: AppLayout });

const rows = [
    [
        { type: 'play', label: 'Waterspouts', folderSlug: 'special/disaster/weather/waterspouts',
          tone: 'bg-blue-800 hover:bg-blue-700 text-blue-50 border-blue-600' },
        { type: 'play', label: 'Polar Vortex', folderSlug: 'special/disaster/weather/polar-vortex',
          tone: 'bg-sky-400 hover:bg-sky-300 text-sky-950 border-sky-200' },
        { type: 'play', label: 'Frozen Rails', folderSlug: 'special/disaster/weather/frozen-rails',
          tone: 'bg-slate-100 hover:bg-white text-slate-800 border-slate-300' },
    ],
];
</script>

<template>
    <Breadcrumb title="Weather" parent="Special › Disaster" />
    <ButtonGrid :rows="rows" :cols="3" />
</template>
