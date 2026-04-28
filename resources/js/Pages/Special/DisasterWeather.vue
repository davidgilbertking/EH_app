<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/App/Breadcrumb.vue';
import ButtonGrid from '@/Components/App/ButtonGrid.vue';

defineOptions({ layout: AppLayout });

const icon = (slug) => `/images/disaster/${slug}.png`;

const rows = [
    [
        { type: 'play', label: 'Waterspouts', folderSlug: 'special/disaster/weather/waterspouts',
          tone: 'bg-blue-800 hover:bg-blue-700 text-blue-50 border-blue-600',
          imageUrl: icon('waterspouts'), showImage: true },
        { type: 'play', label: 'Polar Vortex', folderSlug: 'special/disaster/weather/polar-vortex',
          tone: 'bg-sky-400 hover:bg-sky-300 text-sky-950 border-sky-200',
          imageUrl: icon('polar-vortex'), showImage: true },
        { type: 'play', label: 'Frozen Rails', folderSlug: 'special/disaster/weather/frozen-rails',
          tone: 'bg-slate-100 hover:bg-white text-slate-800 border-slate-300',
          imageUrl: icon('frozen-rails'), showImage: true },
    ],
];
</script>

<template>
    <Breadcrumb title="Weather" parent="Other › Disaster" />
    <ButtonGrid :rows="rows" :cols="3" :max-width="'60rem'" />
</template>
