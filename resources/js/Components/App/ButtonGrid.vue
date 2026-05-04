<script setup>
/**
 * Renders a grid of buttons. Each row in `rows` is an array of buttons.
 * Each button is one of:
 *  { type: 'play', label, folderSlug, mode?, variant? }
 *  { type: 'nav',  label, href, variant? }
 * Empty slots: `null`.
 */
import PlayButton from '@/Components/App/PlayButton.vue';
import NavLinkButton from '@/Components/App/NavLinkButton.vue';

defineProps({
    rows: { type: Array, required: true },
    cols: { type: Number, default: 3 },
    maxWidth: { type: String, default: '48rem' },
});
</script>

<template>
    <div
        class="mx-auto space-y-[clamp(0.65rem,calc(2.25rem*var(--ui-scale)),2.25rem)] pt-[clamp(0.2rem,calc(1rem*var(--ui-scale)),1rem)]"
        :style="{ maxWidth }"
    >
        <div
            v-for="(row, rowIdx) in rows"
            :key="rowIdx"
            class="grid gap-x-[clamp(0.45rem,calc(2rem*var(--ui-scale)),2rem)]"
            :style="{ gridTemplateColumns: `repeat(${cols}, minmax(0, 1fr))` }"
        >
            <template v-for="(btn, colIdx) in row" :key="colIdx">
                <div v-if="!btn" class="invisible" aria-hidden="true">&nbsp;</div>
                <NavLinkButton
                    v-else-if="btn.type === 'nav'"
                    :href="btn.href"
                    :label="btn.label"
                    :label-class="btn.labelClass"
                    :mobile-short-label="btn.mobileShortLabel"
                    :variant="btn.variant"
                    :tone="btn.tone"
                    :image-url="btn.imageUrl"
                    :show-image="Boolean(btn.showImage)"
                />
                <PlayButton
                    v-else
                    :folder-slug="btn.folderSlug"
                    :label="btn.label"
                    :label-class="btn.labelClass"
                    :mobile-short-label="btn.mobileShortLabel"
                    :mode="btn.mode"
                    :variant="btn.variant"
                    :tone="btn.tone"
                    :image-url="btn.imageUrl"
                    :show-image="Boolean(btn.showImage)"
                />
            </template>
        </div>
    </div>
</template>
