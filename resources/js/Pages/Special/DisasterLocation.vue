<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/App/Breadcrumb.vue';
import ButtonGrid from '@/Components/App/ButtonGrid.vue';

defineOptions({ layout: AppLayout });

const rows = [
    [
        { type: 'play', label: 'Meteor Showers', folderSlug: 'special/disaster/location/meteor-showers',
          tone: 'bg-amber-600 hover:bg-amber-500 text-amber-50 border-amber-400' },
        { type: 'play', label: 'Upheaval', folderSlug: 'special/disaster/location/upheaval',
          tone: 'bg-rose-700 hover:bg-rose-600 text-rose-50 border-rose-500' },
    ],
    [
        { type: 'play', label: 'Destructive Cyclone', folderSlug: 'special/disaster/location/destructive-cyclone',
          tone: 'bg-cyan-700 hover:bg-cyan-600 text-cyan-50 border-cyan-500' },
        { type: 'play', label: 'Otherworldly Rifts', folderSlug: 'special/disaster/location/otherworldly-rifts',
          tone: 'bg-violet-700 hover:bg-violet-600 text-violet-50 border-violet-500' },
    ],
];
</script>

<template>
    <Breadcrumb title="Location" parent="Special › Disaster" />
    <ButtonGrid :rows="rows" :cols="2" />
</template>
