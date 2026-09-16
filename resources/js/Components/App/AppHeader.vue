<script setup>
import { getActiveFolder, isHrefBranchActive } from '@/audio/folderBranch';
import { engine } from '@/audio/engine';
import { gameFlow } from '@/gameFlow/controller';
import { useLongPress } from '@/composables/useLongPress';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    staticPosition: { type: Boolean, default: false },
});

const page = usePage();
const url = computed(() => page.url || '/');
const playingFolder = computed(() => engine.state.playingFolder);
const phaseFolder = computed(() =>
    playingFolder.value || (engine.state.isPaused ? engine.state.pausedFolder : null)
);
const activeAudioFolder = computed(() => getActiveFolder(engine.state));

// Variant -> Tailwind class lookup. Header variants used to colour-code buttons.
const variantClasses = {
    action: 'bg-amber-700/80 hover:bg-amber-600 text-white',
    actionMuted: 'bg-amber-950 hover:bg-amber-900 text-amber-100',
    combat: 'bg-rose-700/80 hover:bg-rose-600 text-white',
    combatEpic: 'bg-[#4b0a16] hover:bg-[#5c0d1b] text-rose-100',
    mythos: 'bg-purple-700/80 hover:bg-purple-600 text-white',
    encounters: 'bg-emerald-800/80 hover:bg-emerald-700 text-white',
    encountersActive: 'bg-emerald-500 text-white ring-2 ring-emerald-300',
    other: 'bg-sky-800/80 hover:bg-sky-700 text-white',
    otherActive: 'bg-sky-500 text-white ring-2 ring-sky-300',
};

const isEncountersActive = computed(() => url.value.startsWith('/encounters'));
const isOtherActive = computed(() => url.value.startsWith('/other'));
const isEncountersBranchActive = computed(() =>
    isHrefBranchActive('/encounters', activeAudioFolder.value)
);
const isOtherBranchActive = computed(() =>
    isHrefBranchActive('/other', activeAudioFolder.value)
);
const isActionMuted = computed(() => phaseFolder.value === 'action-muted');
const isCombatEpic = computed(() => phaseFolder.value === 'combat-epic');
const actionLabel = computed(() => (isActionMuted.value ? 'Muted Action' : 'Action'));
const combatLabel = computed(() => (isCombatEpic.value ? 'Epic Combat' : 'Combat'));
const actionClass = computed(() => (isActionMuted.value ? variantClasses.actionMuted : variantClasses.action));
const combatClass = computed(() => (isCombatEpic.value ? variantClasses.combatEpic : variantClasses.combat));
const isActionActive = computed(() => playingFolder.value === 'action' || playingFolder.value === 'action-muted');
const isCombatActive = computed(() => playingFolder.value === 'combat' || playingFolder.value === 'combat-epic');
const isMythosActive = computed(() => playingFolder.value === 'mythos');
const isActionPaused = computed(() =>
    engine.state.isPaused
    && (engine.state.pausedFolder === 'action' || engine.state.pausedFolder === 'action-muted')
);
const isCombatPaused = computed(() =>
    engine.state.isPaused
    && (engine.state.pausedFolder === 'combat' || engine.state.pausedFolder === 'combat-epic')
);
const isMythosPaused = computed(() =>
    engine.state.isPaused && engine.state.pausedFolder === 'mythos'
);
const headerClass = computed(() => [
    props.staticPosition ? 'relative' : 'sticky top-0 z-30',
    'flex flex-wrap gap-[clamp(0.2rem,calc(0.5rem*var(--ui-scale)),0.5rem)] border-b border-neutral-800 bg-neutral-950/90 px-[clamp(0.35rem,calc(0.75rem*var(--ui-scale)),0.75rem)] py-[clamp(0.25rem,calc(0.75rem*var(--ui-scale)),0.75rem)] backdrop-blur',
]);

// The coordinator accepts the gesture once for both music and light.
const actionBindings = useLongPress({
    onTap: () => gameFlow.selectAction('action'),
    onLongPress: () => gameFlow.selectAction('action-muted'),
    threshold: 900,
});

const combatBindings = useLongPress({
    onTap: () => gameFlow.selectCombat('combat'),
    onLongPress: () => gameFlow.selectCombat('combat-epic'),
    threshold: 900,
});

const mythosBindings = useLongPress({
    onTap: () => gameFlow.enterMythos(),
    threshold: 5000,
});
</script>

<template>
    <header :class="headerClass">
        <button
            type="button"
            class="group relative flex-1 rounded-lg text-center font-semibold tracking-wide active:scale-[0.98] transition ui-header-btn whitespace-normal break-words leading-tight"
            :class="[
                actionClass,
                isActionActive ? 'ring-2 ring-amber-400' : '',
                isActionPaused ? 'paused-amber-dash' : '',
            ]"
            v-bind="actionBindings"
            @click="($event.detail === 0) && gameFlow.selectAction('action')"
        >
            {{ actionLabel }}
            <span
                v-if="!isActionMuted"
                class="pointer-events-none absolute inset-x-0 bottom-2 font-normal opacity-0 transition-opacity duration-150 group-hover:opacity-70 ui-header-hint"
            >
                hold for Muted
            </span>
        </button>

        <button
            type="button"
            class="group relative flex-1 rounded-lg text-center font-semibold tracking-wide active:scale-[0.98] transition ui-header-btn whitespace-normal break-words leading-tight"
            :class="[
                combatClass,
                isCombatActive ? 'ring-2 ring-amber-400' : '',
                isCombatPaused ? 'paused-amber-dash' : '',
            ]"
            v-bind="combatBindings"
            @click="($event.detail === 0) && gameFlow.selectCombat('combat')"
        >
            {{ combatLabel }}
            <span
                v-if="!isCombatEpic"
                class="pointer-events-none absolute inset-x-0 bottom-2 font-normal opacity-0 transition-opacity duration-150 group-hover:opacity-70 ui-header-hint"
            >
                hold for Epic
            </span>
        </button>

        <button
            type="button"
            @click="gameFlow.enterEncounters()"
            class="flex min-w-0 flex-1 items-center justify-center gap-1 rounded-lg font-semibold tracking-wide active:scale-[0.98] transition ui-header-btn ui-header-link whitespace-normal break-words leading-tight"
            :class="[
                isEncountersActive ? variantClasses.encountersActive : variantClasses.encounters,
                isEncountersBranchActive ? 'ring-2 ring-amber-400' : '',
            ]"
        >
            <span class="ui-header-nav-mobile-label min-w-0 whitespace-normal break-words text-center">Encounters</span>
            <span aria-hidden="true" class="max-[640px]:hidden">›</span>
        </button>

        <button
            type="button"
            class="flex min-w-0 flex-1 items-center justify-center gap-1 rounded-lg font-semibold tracking-wide active:scale-[0.98] transition ui-header-btn ui-header-link whitespace-normal break-words leading-tight"
            :class="[
                variantClasses.mythos,
                isMythosActive ? 'ring-2 ring-amber-400' : '',
                isMythosPaused ? 'paused-amber-dash' : '',
            ]"
            v-bind="mythosBindings"
            @click="($event.detail === 0) && gameFlow.enterMythos()"
        >
            <span class="ui-header-nav-mobile-label min-w-0 whitespace-normal break-words text-center">Mythos</span>
            <span aria-hidden="true" class="max-[640px]:hidden">›</span>
        </button>

        <Link
            href="/other"
            class="flex min-w-0 flex-1 items-center justify-center gap-1 rounded-lg font-semibold tracking-wide active:scale-[0.98] transition ui-header-btn ui-header-link whitespace-normal break-words leading-tight"
            :class="[
                isOtherActive ? variantClasses.otherActive : variantClasses.other,
                isOtherBranchActive ? 'ring-2 ring-amber-400' : '',
            ]"
        >
            <span class="ui-header-nav-mobile-label min-w-0 whitespace-normal break-words text-center">Other</span>
            <span aria-hidden="true" class="max-[640px]:hidden">›</span>
        </Link>
    </header>
</template>
