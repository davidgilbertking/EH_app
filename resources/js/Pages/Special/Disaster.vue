<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/App/Breadcrumb.vue';
import ButtonGrid from '@/Components/App/ButtonGrid.vue';

defineOptions({ layout: AppLayout });

const rows = [
    [
        { type: 'nav', label: 'City', href: '/special/disaster/city',
          tone: 'bg-red-700 hover:bg-red-600 text-red-50 border-red-500' },
        { type: 'nav', label: 'Weather', href: '/special/disaster/weather',
          tone: 'bg-teal-500 hover:bg-teal-400 text-teal-950 border-teal-300' },
        { type: 'nav', label: 'Location', href: '/special/disaster/location',
          tone: 'bg-amber-500 hover:bg-amber-400 text-amber-950 border-amber-300' },
    ],
];
</script>

<template>
    <Breadcrumb title="Disaster" parent="Special" />
    <ButtonGrid :rows="rows" :cols="3" />
</template>
