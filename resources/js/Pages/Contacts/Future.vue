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
const shortLabels = [
    'Чужой Таймс-сквер',
    'Город без света',
    'Выжженная земля',
    'Карантин будущего',
    'Река времени',
    'Эпоха идолов',
    'Техно-эпоха',
    'Умирающий двойник',
];
// You can change these short labels to your own wording without touching
// routing/logic; each entry maps to the same index in `labels`.
const futureIcon = '/images/other-worlds/future.png';
// Same 8-colour fixed palette as Past.vue. Button N has the same colour on
// both pages — not a problem because users only see one page at a time.
const items = labels.map((label, i) => ({
    slug: `contacts/other-world/future/${i + 1}`,
    label,
    mobileShortLabel: shortLabels[i] ?? '',
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
