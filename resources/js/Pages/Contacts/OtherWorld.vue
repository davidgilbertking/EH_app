<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/App/Breadcrumb.vue';
import ImageTextButton from '@/Components/App/ImageTextButton.vue';

defineOptions({ layout: AppLayout });

// Image path: drop art into public/images/other-worlds/<slug>.png.
// Buttons render a "?" placeholder when the image is absent.
const img = (slug) => `/images/other-worlds/${slug}.png`;

const worlds = [
    { slug: 'carcosa',    label: 'Carcosa',    tone: 'bg-yellow-900/70  hover:bg-yellow-800  border-yellow-700  text-yellow-50' },
    { slug: 'great-race', label: 'Great Race', tone: 'bg-lime-900/70    hover:bg-lime-800    border-lime-700    text-lime-50' },
    { slug: 'yuggoth',    label: 'Yuggoth',    tone: 'bg-violet-950     hover:bg-violet-900  border-violet-800  text-violet-100' },
    { slug: 'celaeno',    label: 'Celaeno',    tone: 'bg-cyan-900/70    hover:bg-cyan-800    border-cyan-700    text-cyan-50' },
    { slug: 'leng',       label: 'Leng',       tone: 'bg-teal-900/70    hover:bg-teal-800    border-teal-700    text-teal-50' },
    { slug: 'dreamlands', label: 'Dreamlands', tone: 'bg-fuchsia-900/70 hover:bg-fuchsia-800 border-fuchsia-700 text-fuchsia-50' },
    { slug: 'kadath',     label: 'Kadath',     tone: 'bg-indigo-900/70  hover:bg-indigo-800  border-indigo-700  text-indigo-50' },
    { slug: 'underworld', label: 'Underworld', tone: 'bg-zinc-900       hover:bg-zinc-800    border-zinc-700    text-zinc-100' },
    { slug: 'abyss',      label: 'Abyss',      tone: 'bg-slate-900      hover:bg-slate-800   border-slate-700   text-slate-100' },
];
</script>

<template>
    <Breadcrumb title="Other World" parent="Encounters" />

    <div
        class="other-world-desktop other-world-fit mx-auto flex max-w-3xl flex-col pr-[clamp(0.2rem,calc(0.5rem*var(--ui-scale)),0.5rem)] pb-[calc(var(--corner-size)+0.35rem)] [padding-left:calc(var(--corner-size)+0.45rem)]"
        style="gap: var(--ow-space-y, 0.75rem)"
    >
        <div
            class="other-world-grid grid"
            style="grid-template-columns: repeat(2, minmax(0, 1fr)); column-gap: var(--ow-gap-x, 1rem); row-gap: var(--ow-gap-y, 0.75rem)"
        >
            <ImageTextButton
                folder-slug="contacts/other-world/past/1"
                active-folder-prefix="contacts/other-world/past"
                label="Past"
                href="/encounters/other-world/past"
                :image-url="img('past')"
                tone="bg-stone-800 hover:bg-stone-700 border-stone-600 text-stone-100"
                class="other-world-btn"
            />
            <ImageTextButton
                folder-slug="contacts/other-world/future/1"
                active-folder-prefix="contacts/other-world/future"
                label="Future"
                href="/encounters/other-world/future"
                :image-url="img('future')"
                tone="bg-sky-900/80 hover:bg-sky-800 border-sky-700 text-sky-50"
                class="other-world-btn"
            />
        </div>

        <div
            class="other-world-grid grid"
            style="grid-template-columns: repeat(2, minmax(0, 1fr)); column-gap: var(--ow-gap-x, 1rem); row-gap: var(--ow-gap-y, 0.75rem)"
        >
            <ImageTextButton
                v-for="w in worlds"
                :key="w.slug"
                :folder-slug="`contacts/other-world/${w.slug}`"
                :label="w.label"
                :image-url="img(w.slug)"
                :tone="w.tone"
                class="other-world-btn"
            />
        </div>
    </div>
</template>

<style scoped>
.other-world-btn {
    height: clamp(2.35rem, calc(4.4rem * var(--ui-scale)), 4.4rem) !important;
}

@media (min-width: 1024px) {
.other-world-desktop {
    --image-btn-h: 6.35rem;
    --image-btn-icon: 3.2rem;
    --image-btn-label: 1.06rem;
}

.other-world-btn {
    height: clamp(2.7rem, calc(5rem * var(--ui-scale)), 5rem) !important;
}
}

@media (max-width: 950px) and (max-height: 500px) and (orientation: landscape) and (pointer: coarse) {
.other-world-fit {
    --ow-space-y: 0.24rem;
    --ow-gap-x: 0.45rem;
    --ow-gap-y: 0.24rem;
    --image-btn-h: 2.16rem;
    --image-btn-icon: 1.18rem;
    --image-btn-label: 1.22rem;
    padding-bottom: 0.2rem !important;
    padding-left: calc(var(--corner-size) + 0.2rem) !important;
}
}

@media (max-width: 640px) and (orientation: portrait) and (pointer: coarse) {
.other-world-fit {
    --image-btn-label: 0.92rem;
}
}
</style>
