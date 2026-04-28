<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/App/Breadcrumb.vue';
import ButtonGrid from '@/Components/App/ButtonGrid.vue';

defineOptions({ layout: AppLayout });

const rows = [
    [
        { type: 'play', label: 'Hot', folderSlug: 'contacts/expedition/expeditions/hot',
          tone: 'bg-red-700 hover:bg-red-600 text-red-50 border-red-500' },
        { type: 'play', label: 'Cold', folderSlug: 'contacts/expedition/expeditions/cold',
          tone: 'bg-blue-700 hover:bg-blue-600 text-blue-50 border-blue-500' },
        { type: 'play', label: 'City', folderSlug: 'contacts/expedition/expeditions/city',
          tone: 'bg-neutral-800 hover:bg-neutral-700 text-neutral-100 border-neutral-600' },
    ],
];
</script>

<template>
    <Breadcrumb title="Expedition" parent="Encounters › Quest" />
    <ButtonGrid :rows="rows" :cols="3" />
</template>
