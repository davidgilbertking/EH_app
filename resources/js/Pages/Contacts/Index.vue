<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/App/Breadcrumb.vue';
import ButtonGrid from '@/Components/App/ButtonGrid.vue';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineOptions({ layout: AppLayout });

const page = usePage();
const ancient = computed(() => page.props.gameState?.ancientOne ?? null);

const elderBtn = computed(() => {
    if (ancient.value) {
        return {
            type: 'play',
            folderSlug: `ancient/${ancient.value.slug}`,
            label: `Elder — ${ancient.value.name}`,
            variant: 'elder',
        };
    }
    return { type: 'nav', label: 'Pick Ancient One', href: '/special/ancient-ones', variant: 'ancients' };
});

const rows = computed(() => [
    [
        elderBtn.value,
        { type: 'nav', label: 'General',     href: '/contacts/general',     variant: 'wilderness' },
        { type: 'nav', label: 'Obstruction', href: '/contacts/obstruction', variant: 'obstruction' },
    ],
    [
        { type: 'nav', label: 'Big City',    href: '/contacts/big-city',    variant: 'bigCity' },
        { type: 'nav', label: 'Outer World', href: '/contacts/outer-world', variant: 'outerWorld' },
        { type: 'nav', label: 'Defeated',    href: '/contacts/defeated',    variant: 'defeated' },
    ],
    [
        { type: 'nav', label: 'Expedition',  href: '/contacts/expedition',  variant: 'expedition' },
        { type: 'nav', label: 'Add. Map',    href: '/contacts/add-map',     variant: 'addmap' },
        { type: 'play', label: 'Devastation', folderSlug: 'contacts/devastation', variant: 'devastation' },
    ],
]);
</script>

<template>
    <Breadcrumb title="Contacts" />
    <ButtonGrid :rows="rows" :cols="3" />
</template>
