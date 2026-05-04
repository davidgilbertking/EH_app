<script setup>
import { getActiveFolder, isHrefBranchActive } from '@/audio/folderBranch';
import { engine } from '@/audio/engine';
import { useLongPress } from '@/composables/useLongPress';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

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

// Tap toggles: second tap on the currently-playing folder fades it out.
// Header buttons represent phase changes (Action / Combat / Mythos / etc.) —
// so we use hardSwitch: true to guarantee the previous phase's track is fully
// silent before the new one starts. No overlap allowed at phase boundaries.
function toggle(folderSlug, label) {
    if (engine.state.playingFolder === folderSlug) {
        engine.stop();
        return;
    }
    engine.play({ folderSlug, label, hardSwitch: true });
}

const actionBindings = useLongPress({
    onTap: () => {
        if (playingFolder.value === 'action' || playingFolder.value === 'action-muted') {
            engine.stop();
            return;
        }
        toggle('action', 'Action');
    },
    onLongPress: () => {
        if (playingFolder.value === 'action-muted') {
            engine.stop();
            return;
        }
        toggle('action-muted', 'Muted Action');
    },
    threshold: 900,
});

const combatBindings = useLongPress({
    onTap: () => {
        if (playingFolder.value === 'combat' || playingFolder.value === 'combat-epic') {
            engine.stop();
            return;
        }
        toggle('combat', 'Combat');
    },
    onLongPress: () => {
        if (playingFolder.value === 'combat-epic') {
            engine.stop();
            return;
        }
        toggle('combat-epic', 'Epic Combat');
    },
    threshold: 900,
});

const mythosBindings = useLongPress({
    onTap: () => toggle('mythos', 'Mythos'),
    threshold: 5000, // effectively no long-press behaviour
});
</script>

<template>
    <header
        class="sticky top-0 z-30 flex flex-wrap gap-[clamp(0.2rem,calc(0.5rem*var(--ui-scale)),0.5rem)] border-b border-neutral-800 bg-neutral-950/90 px-[clamp(0.35rem,calc(0.75rem*var(--ui-scale)),0.75rem)] py-[clamp(0.25rem,calc(0.75rem*var(--ui-scale)),0.75rem)] backdrop-blur"
    >
        <button
            type="button"
            class="group relative flex-1 rounded-lg text-center font-semibold tracking-wide active:scale-[0.98] transition ui-header-btn whitespace-normal break-words leading-tight"
            :class="[
                actionClass,
                isActionActive ? 'ring-2 ring-amber-400' : '',
                isActionPaused ? 'paused-amber-dash' : '',
            ]"
            v-bind="actionBindings"
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
        >
            {{ combatLabel }}
            <span
                v-if="!isCombatEpic"
                class="pointer-events-none absolute inset-x-0 bottom-2 font-normal opacity-0 transition-opacity duration-150 group-hover:opacity-70 ui-header-hint"
            >
                hold for Epic
            </span>
        </button>

        <Link
            href="/encounters"
            class="flex flex-1 items-center justify-center gap-1 rounded-lg font-semibold tracking-wide active:scale-[0.98] transition ui-header-btn ui-header-link whitespace-normal break-words leading-tight"
            :class="[
                isEncountersActive ? variantClasses.encountersActive : variantClasses.encounters,
                isEncountersBranchActive ? 'ring-2 ring-amber-400' : '',
            ]"
        >
            Encounters
            <span aria-hidden="true" class="max-[640px]:hidden">›</span>
        </Link>

        <button
            type="button"
            class="flex-1 rounded-lg font-semibold tracking-wide active:scale-[0.98] transition ui-header-btn ui-header-link whitespace-normal break-words leading-tight"
            :class="[
                variantClasses.mythos,
                isMythosActive ? 'ring-2 ring-amber-400' : '',
                isMythosPaused ? 'paused-amber-dash' : '',
            ]"
            v-bind="mythosBindings"
        >
            Mythos
        </button>

        <Link
            href="/other"
            class="flex flex-1 items-center justify-center gap-1 rounded-lg font-semibold tracking-wide active:scale-[0.98] transition ui-header-btn ui-header-link whitespace-normal break-words leading-tight"
            :class="[
                isOtherActive ? variantClasses.otherActive : variantClasses.other,
                isOtherBranchActive ? 'ring-2 ring-amber-400' : '',
            ]"
        >
            Other
            <span aria-hidden="true" class="max-[640px]:hidden">›</span>
        </Link>
    </header>
</template>
