<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/App/Breadcrumb.vue';
import ButtonGrid from '@/Components/App/ButtonGrid.vue';

defineOptions({ layout: AppLayout });

const rows = [
    [
        { type: 'play', label: 'Sanity', folderSlug: 'contacts/defeated/sanity',
          tone: 'bg-sky-600 hover:bg-sky-500 text-sky-50 border-sky-400' },
        { type: 'play', label: 'Health', folderSlug: 'contacts/defeated/health',
          tone: 'bg-red-700 hover:bg-red-600 text-red-50 border-red-500' },
    ],
];
</script>

<template>
    <Breadcrumb title="Defeated" parent="Encounters" />
    <ButtonGrid :rows="rows" :cols="2" />
</template>
