<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/App/Breadcrumb.vue';
import ButtonGrid from '@/Components/App/ButtonGrid.vue';

defineOptions({ layout: AppLayout });

const rows = [
    [
        { type: 'nav', label: 'Major Cities', href: '/other/disaster/city',
          tone: 'border-neutral-300/40 bg-neutral-900/85 hover:bg-neutral-800 text-neutral-100' },
        { type: 'nav', label: 'Weather', href: '/other/disaster/weather',
          tone: 'bg-teal-500 hover:bg-teal-400 text-teal-950 border-teal-300' },
        { type: 'nav', label: 'Location', href: '/other/disaster/location',
          tone: 'bg-amber-500 hover:bg-amber-400 text-amber-950 border-amber-300' },
    ],
];
</script>

<template>
    <Breadcrumb title="Disaster" parent="Other" />
    <ButtonGrid :rows="rows" :cols="3" />
</template>
