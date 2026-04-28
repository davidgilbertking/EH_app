<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/App/Breadcrumb.vue';
import ButtonGrid from '@/Components/App/ButtonGrid.vue';

defineOptions({ layout: AppLayout });

const rows = [
    [
        { type: 'nav', label: 'Antarctica', href: '/contacts/add-map/antarctica',
          tone: 'bg-sky-400 hover:bg-sky-300 text-sky-950 border-sky-200' },
        { type: 'nav', label: 'Egypt', href: '/contacts/add-map/egypt',
          tone: 'bg-yellow-500 hover:bg-yellow-400 text-yellow-950 border-yellow-300' },
        { type: 'nav', label: 'Dreamlands', href: '/contacts/add-map/dreamlands',
          tone: 'bg-emerald-950 hover:bg-emerald-900 text-emerald-100 border-emerald-800' },
    ],
];
</script>

<template>
    <Breadcrumb title="Additional Maps" parent="Contacts" />
    <ButtonGrid :rows="rows" :cols="3" />
</template>
