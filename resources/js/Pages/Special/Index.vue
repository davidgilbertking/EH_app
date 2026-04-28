<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/App/Breadcrumb.vue';
import PlayButton from '@/Components/App/PlayButton.vue';
import NavLinkButton from '@/Components/App/NavLinkButton.vue';

defineOptions({ layout: AppLayout });

const play = (label, slug, opts = {}) => ({
    type: 'play', label, folderSlug: `special/${slug}`, ...opts,
});
const nav = (label, href, opts = {}) => ({ type: 'nav', label, href, ...opts });

// Pyramid 4-4-2-1, all centred. Every button shares the same fixed width so rows
// remain visually aligned regardless of count.
const rows = [
    [
        // Defeat: very dark burgundy, nearly black — muted end-of-game vibe.
        play('Defeat', 'defeat', {
            tone: 'bg-red-950 hover:bg-red-900 text-red-100 border-red-900',
        }),
        play('Victory',   'victory',   { variant: 'victory' }),
        play('Awakening', 'awakening', { variant: 'awakening' }),
        nav('Disaster', '/special/disaster', { variant: 'disaster' }),
    ],
    [
        play('Death (Sanity)',    'death-sanity',    { variant: 'deathSanity' }),
        play('Death (Health)',    'death-health',    { variant: 'deathHealth' }),
        play('Death (Sacrifice)', 'death-sacrifice', { variant: 'deathSacrifice' }),
        // Devoured: stark black — "nothing left" state.
        play('Devoured', 'devoured', {
            tone: 'bg-black hover:bg-neutral-900 text-neutral-100 border-neutral-700',
        }),
    ],
    [
        nav('Investigators', '/special/investigators', { variant: 'investigators' }),
        nav('Ancient Ones',  '/special/ancient-ones',  { variant: 'ancients' }),
    ],
    [
        play('Honey Pie', 'honey-pie', { variant: 'honey' }),
    ],
];
</script>

<template>
    <Breadcrumb title="Special" />

    <div class="space-y-9">
        <div
            v-for="(row, idx) in rows"
            :key="idx"
            class="flex justify-center gap-3"
        >
            <div v-for="(btn, i) in row" :key="i" class="w-56 shrink-0">
                <NavLinkButton
                    v-if="btn.type === 'nav'"
                    :href="btn.href"
                    :label="btn.label"
                    :variant="btn.variant"
                    :tone="btn.tone"
                    class="w-full"
                />
                <PlayButton
                    v-else
                    :folder-slug="btn.folderSlug"
                    :label="btn.label"
                    :variant="btn.variant"
                    :tone="btn.tone"
                    class="w-full"
                />
            </div>
        </div>
    </div>
</template>
