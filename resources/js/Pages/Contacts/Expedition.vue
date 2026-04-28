<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/App/Breadcrumb.vue';
import ButtonGrid from '@/Components/App/ButtonGrid.vue';

defineOptions({ layout: AppLayout });

const rows = [
    [
        { type: 'nav', label: 'Expedition', href: '/encounters/quest/expedition',
          tone: 'bg-[#4f3a2d] hover:bg-[#5d4536] text-[#f6ebe0] border-[#6c5040]' },
        { type: 'play', label: 'Mystic Ruins', folderSlug: 'contacts/expedition/ruins',
          tone: 'bg-[#496b96] hover:bg-[#5579a7] text-[#edf4ff] border-[#6288b7]' },
        { type: 'nav', label: 'Dream-Quest', href: '/encounters/side-boards/dreamlands?mode=dream-quest',
          tone: 'bg-[#0b5862] hover:bg-[#106a76] text-[#d6f7fb] border-[#1b7a86]' },
    ],
];
</script>

<template>
    <Breadcrumb title="Quest" parent="Encounters" />
    <ButtonGrid :rows="rows" :cols="3" />
</template>
