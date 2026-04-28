<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/App/Breadcrumb.vue';
import PlayButton from '@/Components/App/PlayButton.vue';
import { PALETTE_8 } from './data/buttonPalette';

defineOptions({ layout: AppLayout });

const labels = [
    'Through the slits in the closet door',
    'According to the papers the year is 1771',
    'Through the shifting sands of the desert',
    'The police have surrounded what looks like a church',
    'You appear on the continent of Mu',
    'enormous primitive buildings made of green stone',
    'You are shocked to see a younger version of yourself',
    'The people of Arkham are prowling the streets hunting a witch',
];
// Fixed 8-colour palette, same order every session so button N is always the
// same colour (easy to reference during a game).
const items = labels.map((label, i) => ({
    slug: `contacts/outer-world/past/${i + 1}`,
    label,
    tone: PALETTE_8[i],
}));
</script>

<template>
    <Breadcrumb title="Past" parent="Contacts › Outer World" />
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
