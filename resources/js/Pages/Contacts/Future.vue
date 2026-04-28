<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/App/Breadcrumb.vue';
import PlayButton from '@/Components/App/PlayButton.vue';
import { PALETTE_8 } from './data/buttonPalette';

defineOptions({ layout: AppLayout });

const labels = [
    'You stand in Times Square, New York',
    "You find yourself in a familiar city, but there's no electricity",
    'Large beetle-like creatures',
    'imprisoned in a compound with hundreds of plague victims',
    'wading through the endless river of time',
    'filled with wizards and the worship of blasphemous gods',
    'You discover an era of technological marvels',
    'A future version of you lies in a rancid hotel room',
];
// Same 8-colour fixed palette as Past.vue. Button N has the same colour on
// both pages — not a problem because users only see one page at a time.
const items = labels.map((label, i) => ({
    slug: `contacts/outer-world/future/${i + 1}`,
    label,
    tone: PALETTE_8[i],
}));
</script>

<template>
    <Breadcrumb title="Future" parent="Contacts › Outer World" />
    <div class="mx-auto max-w-md space-y-3">
        <PlayButton
            v-for="i in items"
            :key="i.slug"
            :folder-slug="i.slug"
            :label="i.label"
            :tone="i.tone"
            class="w-full"
        />
    </div>
</template>
