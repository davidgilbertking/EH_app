<script setup>
import { computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/App/Breadcrumb.vue';
import ButtonGrid from '@/Components/App/ButtonGrid.vue';
import { gameFlow } from '@/gameFlow/controller';
import { MYTHOS_CARD_BUTTONS } from '@/lighting/palette';

defineOptions({ layout: AppLayout });

const active = computed(() => gameFlow.state.selectedContext === 'mythos' && Boolean(gameFlow.state.mythosSessionId));
const sessionId = computed(() => gameFlow.state.mythosSessionId);
const selected = computed(() => gameFlow.state.selectedMythosColor);
</script>

<template>
    <section class="mythos-page">
        <Breadcrumb title="Mythos" />
        <ButtonGrid :rows="[MYTHOS_CARD_BUTTONS]" :cols="3" aria-label="Mythos cards" v-slot="{ button: card }">
            <button
                type="button"
                class="mythos-card ui-main-btn relative flex h-full w-full items-center justify-center rounded-xl border border-transparent bg-transparent px-3 text-center font-semibold tracking-wide active:scale-[0.98] shadow-lg transition enabled:hover:brightness-110 disabled:cursor-not-allowed"
                :style="{ '--mythos-accent': card.accent }"
                :disabled="!active"
                :aria-label="card.label"
                :aria-pressed="active && selected === card.id"
                @click="gameFlow.selectMythosColor(card.id, sessionId)"
            >
                <span aria-hidden="true" class="invisible leading-tight">&nbsp;</span>
                <svg class="mythos-card-art absolute inset-0 h-full w-full" :viewBox="card.viewBox" preserveAspectRatio="none" aria-hidden="true" focusable="false">
                    <defs>
                        <clipPath :id="`mythos-art-${card.id}`" clipPathUnits="userSpaceOnUse">
                            <rect v-bind="card.clip" />
                        </clipPath>
                    </defs>
                    <image :href="card.image" width="2172" height="724" :clip-path="`url(#mythos-art-${card.id})`" />
                </svg>
            </button>
        </ButtonGrid>
        <p v-if="gameFlow.state.audioError" role="status" class="mt-3 text-center text-sm text-amber-200">{{ gameFlow.state.audioError }}</p>
    </section>
</template>

<style scoped>
.mythos-card {
    min-height: max(44px, var(--main-btn-min-h));
}

.mythos-card[aria-pressed='true'] {
    box-shadow: 0 0 18px rgb(var(--mythos-accent) / 0.6), 0 0 6px rgb(var(--mythos-accent) / 0.45);
}

.mythos-card:focus-visible {
    outline: 3px solid rgb(var(--mythos-accent));
    outline-offset: 4px;
}

/* The SVG clips follow each printed frame, excluding its white outer canvas. */
.mythos-card-art {
    overflow: hidden;
    border-radius: inherit;
}
</style>
