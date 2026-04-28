<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/App/Breadcrumb.vue';
import ButtonGrid from '@/Components/App/ButtonGrid.vue';

defineOptions({ layout: AppLayout });

const rows = [
    [
        { type: 'play', label: 'Detained', folderSlug: 'contacts/obstruction/detained',
          tone: 'bg-zinc-900 hover:bg-zinc-800 text-zinc-100 border-zinc-700' },
        { type: 'play', label: 'Lost in Time and Space', folderSlug: 'contacts/obstruction/lost',
          tone: 'bg-violet-700 hover:bg-violet-600 text-violet-50 border-violet-500' },
    ],
];
</script>

<template>
    <Breadcrumb title="Restriction" parent="Encounters" />
    <ButtonGrid :rows="rows" :cols="2" />
</template>
