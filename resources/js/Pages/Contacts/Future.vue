<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/App/Breadcrumb.vue';
import PlayButton from '@/Components/App/PlayButton.vue';
import { PALETTE_8 } from './data/buttonPalette';

defineOptions({ layout: AppLayout });

const labels = [
    'Вы стоите на Таймс-сквер в Нью-Йорке, но не узнаете площадь.',
    "Вы оказываетесь в знакомом, но лишённом электричества городе.",
    'Вокруг вас расстилается незнакомая местность со следами пожара.',
    'Вы попали в недалёкое будущее, где вас держат в карантине вместе с сотнями жертв эпидемии.',
    'Вы переходите вброд бесконечную реку времени.',
    'Это далёкое будущее очень похоже на утраченные древние цивилизации, где обитали волшебники и идолопоклонники.',
    'Вы оказываетесь в эпохе технологических чудес.',
    'Будущий вы лежите в затхлом гостиничном номере, бессвязно бормоча и медленно умирая от жестоких ран.',
];
const futureIcon = '/images/other-worlds/future.png';
// Same 8-colour fixed palette as Past.vue. Button N has the same colour on
// both pages — not a problem because users only see one page at a time.
const items = labels.map((label, i) => ({
    slug: `contacts/other-world/future/${i + 1}`,
    label,
    tone: PALETTE_8[i],
    imageUrl: futureIcon,
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
    <Breadcrumb title="Future" parent="Encounters › Other World" />
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
