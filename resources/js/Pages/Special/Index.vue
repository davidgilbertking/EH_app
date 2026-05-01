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
        nav('Disaster', '/other/disaster', { variant: 'disaster' }),
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
        nav('Investigators', '/other/investigators', { variant: 'investigators' }),
        nav('Ancient Ones',  '/other/ancient-ones',  { variant: 'ancients' }),
    ],
    [
        play('Honey Pie', 'honey-pie', { variant: 'honey' }),
    ],
];
</script>

<template>
    <Breadcrumb title="Other" />

    <div
        class="space-y-[clamp(0.35rem,calc(2.25rem*var(--ui-scale)),2.25rem)] pt-[clamp(0.1rem,calc(1rem*var(--ui-scale)),1rem)] pr-[clamp(0.2rem,calc(0.5rem*var(--ui-scale)),0.5rem)] pb-[calc(var(--corner-size)+0.35rem)] [padding-left:calc(var(--corner-size)+0.45rem)]"
    >
        <div
            v-for="(row, idx) in rows"
            :key="idx"
            class="mx-auto flex w-full max-w-[72rem] justify-center gap-[clamp(0.25rem,calc(2rem*var(--ui-scale)),2rem)]"
        >
            <div
                v-for="(btn, i) in row"
                :key="i"
                class="min-w-0 max-w-[14rem] flex-1"
            >
                <NavLinkButton
                    v-if="btn.type === 'nav'"
                    :href="btn.href"
                    :label="btn.label"
                    :variant="btn.variant"
                    :tone="btn.tone"
                    class="w-full !min-h-[clamp(2.15rem,calc(6rem*var(--ui-scale)),6rem)] !py-[clamp(0.2rem,calc(0.8rem*var(--ui-scale)),0.8rem)] !text-[clamp(0.92rem,calc(1.35rem*var(--ui-scale)),1.35rem)]"
                />
                <PlayButton
                    v-else
                    :folder-slug="btn.folderSlug"
                    :label="btn.label"
                    :variant="btn.variant"
                    :tone="btn.tone"
                    class="w-full !min-h-[clamp(2.15rem,calc(6rem*var(--ui-scale)),6rem)] !py-[clamp(0.2rem,calc(0.8rem*var(--ui-scale)),0.8rem)] !text-[clamp(0.92rem,calc(1.35rem*var(--ui-scale)),1.35rem)]"
                />
            </div>
        </div>
    </div>
</template>
