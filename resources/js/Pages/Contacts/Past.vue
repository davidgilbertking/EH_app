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
const pastIcon = '/images/other-worlds/past.png';
// Fixed 8-colour palette, same order every session so button N is always the
// same colour (easy to reference during a game).
const items = labels.map((label, i) => ({
    slug: `contacts/other-world/past/${i + 1}`,
    label,
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
    <div class="mx-auto grid max-w-6xl grid-cols-4 gap-3">
        <PlayButton
            v-for="i in gridItems"
            :key="i.slug"
            :folder-slug="i.slug"
            :label="i.label"
            :tone="i.tone"
            :image-url="i.imageUrl"
            class="w-full !h-[10rem] !py-4 !text-lg !leading-tight"
            :big="false"
        />
    </div>
</template>
