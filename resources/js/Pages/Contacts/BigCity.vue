<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/App/Breadcrumb.vue';
import MapPage from '@/Components/App/MapPage.vue';
import { citiesContacts } from './data/mainBoardSpots.js';

defineOptions({ layout: AppLayout });

// Continent → tone (matches the coloured ring around each city card on the board).
const REGION_TONE = {
    america: 'border-emerald-300/60 bg-emerald-700/90 text-emerald-50',
    europe:  'border-orange-300/60 bg-orange-700/90 text-orange-50',
    asia:    'border-purple-300/60 bg-purple-800/90 text-purple-50',
};
const REGION_OF = {
    'san-francisco': 'america',
    'arkham':        'america',
    'buenos-aires':  'america',
    'london':        'europe',
    'rome':          'europe',
    'istanbul':      'europe',
    'tokyo':         'asia',
    'shanghai':      'asia',
    'sydney':        'asia',
};
const hotspots = citiesContacts.map((c) => ({
    x: c.x,
    y: c.y,
    label: c.label,
    folderSlug: `contacts/big-city/${c.city}`,
    tone: REGION_TONE[REGION_OF[c.city]] || REGION_TONE.america,
}));
</script>

<template>
    <Breadcrumb title="Named Cities" parent="Encounters" />
    <MapPage background-url="/maps/main.jpg" :hotspots="hotspots" />
</template>
