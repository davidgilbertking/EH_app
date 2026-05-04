<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/App/Breadcrumb.vue';
import PlayButton from '@/Components/App/PlayButton.vue';
import { PALETTE_8 } from './data/buttonPalette';

defineOptions({ layout: AppLayout });

const labels = [
    'Сквозь щели в дверях чулана вы видите самого себя в детстве, сидящего в постели.',
    'Вы стоите перед старинным научным оборудованием.',
    'Среди барханов вы замечаете мужчину, похожего на безумца.',
    'Вы оказываетесь в Провиденсе, штат Род-Айленд, более шестидесяти лет назад.',
    'Вы оказались на континенте Му в его последний день.',
    'По другую сторону портала вы оказываетесь в пустом городе с огромными примитивными зданиями из зеленого камня.',
    'Вы не можете поверить, когда видите более молодого себя, участвующего в ритуале культа, открывшего этот портал.',
    'Жители Аркхэма бродят по улицам, охотясь на ведьм.',
];
const shortLabels = [
    'Себя в детстве',
    'Старое оборудование',
    'Безумец в дюнах',
    'Старый Провиденс',
    'Последний день Му',
    'Пустой зелёный город',
    'Ритуал молодого себя',
    'Охота на ведьм',
];
// You can change these short labels to your own wording without touching
// routing/logic; each entry maps to the same index in `labels`.
const pastIcon = '/images/other-worlds/past.png';
// Fixed 8-colour palette, same order every session so button N is always the
// same colour (easy to reference during a game).
const items = labels.map((label, i) => ({
    slug: `contacts/other-world/past/${i + 1}`,
    label,
    mobileShortLabel: shortLabels[i] ?? '',
    tone: PALETTE_8[i],
    imageUrl: pastIcon,
}));

const ROWS = 2;
const COLS = 4;
const cols = Array.from({ length: COLS }, () => []);
items.forEach((item, idx) => {
    const col = Math.floor(idx / ROWS);
    if (col < COLS) cols[col].push(item);
});
const gridItems = [];
for (let row = 0; row < ROWS; row++) {
    for (let col = 0; col < COLS; col++) {
        const item = cols[col][row];
        if (item) gridItems.push(item);
    }
}
</script>

<template>
    <Breadcrumb title="Past" parent="Encounters › Other World" />
    <div class="mx-auto grid max-w-6xl grid-cols-4 gap-2 pr-[clamp(0.2rem,calc(0.5rem*var(--ui-scale)),0.5rem)] pb-[calc(var(--corner-size)+0.35rem)] [padding-left:calc(var(--corner-size)+0.45rem)]">
        <PlayButton
            v-for="i in gridItems"
            :key="i.slug"
            :folder-slug="i.slug"
            :label="i.label"
            :mobile-short-label="i.mobileShortLabel"
            :tone="i.tone"
            :image-url="i.imageUrl"
            class="other-world-mobile-btn w-full !h-[clamp(4.2rem,calc(10rem*var(--ui-scale)),10rem)] !py-[clamp(0.35rem,calc(1rem*var(--ui-scale)),1rem)] !text-[clamp(0.56rem,calc(1.125rem*var(--ui-scale)),1.125rem)] !leading-tight"
            :big="false"
        />
    </div>
</template>

<style scoped>
@media (max-width: 640px), (max-width: 950px) and (max-height: 500px) and (orientation: landscape) and (pointer: coarse) {
    .other-world-mobile-btn {
        height: 5.2rem !important;
        font-size: 0.74rem !important;
    }
}
</style>
