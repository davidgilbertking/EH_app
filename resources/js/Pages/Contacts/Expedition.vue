<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/App/Breadcrumb.vue';
import ButtonGrid from '@/Components/App/ButtonGrid.vue';

defineOptions({ layout: AppLayout });

const rows = [
    [
        { type: 'nav', label: 'Expeditions', href: '/contacts/expedition/expeditions',
          tone: 'bg-orange-600 hover:bg-orange-500 text-orange-50 border-orange-400' },
        { type: 'play', label: 'Ruins', folderSlug: 'contacts/expedition/ruins',
          tone: 'bg-cyan-600 hover:bg-cyan-500 text-cyan-50 border-cyan-400' },
    ],
];
</script>

<template>
    <Breadcrumb title="Expedition" parent="Contacts" />
    <ButtonGrid :rows="rows" :cols="2" />
</template>
