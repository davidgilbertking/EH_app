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
    <div class="mx-auto space-y-9 pt-4" :style="{ maxWidth }">
        <div
            v-for="(row, rowIdx) in rows"
            :key="rowIdx"
            class="grid gap-x-8"
            :style="{ gridTemplateColumns: `repeat(${cols}, minmax(0, 1fr))` }"
        >
            <template v-for="(btn, colIdx) in row" :key="colIdx">
                <div v-if="!btn" class="invisible" aria-hidden="true">&nbsp;</div>
                <NavLinkButton
                    v-else-if="btn.type === 'nav'"
                    :href="btn.href"
                    :label="btn.label"
                    :variant="btn.variant"
                    :tone="btn.tone"
                    :image-url="btn.imageUrl"
                    :show-image="Boolean(btn.showImage)"
                />
                <PlayButton
                    v-else
                    :folder-slug="btn.folderSlug"
                    :label="btn.label"
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
