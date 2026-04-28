<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/App/Breadcrumb.vue';
import ImageTextButton from '@/Components/App/ImageTextButton.vue';
import NavLinkButton from '@/Components/App/NavLinkButton.vue';

defineOptions({ layout: AppLayout });

// Image path: drop art into public/images/outer-worlds/<slug>.jpg.
// Buttons render a "?" placeholder when the image is absent.
const img = (slug) => `/images/outer-worlds/${slug}.jpg`;

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
    <Breadcrumb title="Outer World" parent="Contacts" />

    <div class="mx-auto max-w-4xl space-y-4">
        <div class="grid gap-3" style="grid-template-columns: repeat(2, minmax(0, 1fr))">
            <ImageTextButton
                v-for="w in worlds"
                :key="w.slug"
                :folder-slug="`contacts/outer-world/${w.slug}`"
                :label="w.label"
                :image-url="img(w.slug)"
                :tone="w.tone"
            />
        </div>

        <div class="grid gap-3" style="grid-template-columns: repeat(2, minmax(0, 1fr))">
            <NavLinkButton href="/contacts/outer-world/past"   label="Past"   variant="past" />
            <NavLinkButton href="/contacts/outer-world/future" label="Future" variant="future" />
        </div>
    </div>
</template>
