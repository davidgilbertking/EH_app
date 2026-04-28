<script setup>
import { engine } from '@/audio/engine';
import { useLongPress } from '@/composables/useLongPress';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const url = computed(() => page.url || '/');
const playingFolder = computed(() => engine.state.playingFolder);

// Variant -> Tailwind class lookup. Header variants used to colour-code buttons.
const variantClasses = {
    action: 'bg-amber-700/80 hover:bg-amber-600 text-white',
    actionMuted: 'bg-amber-950 hover:bg-amber-900 text-amber-100 ring-2 ring-amber-700/70',
    combat: 'bg-rose-700/80 hover:bg-rose-600 text-white',
    combatEpic: 'bg-[#4b0a16] hover:bg-[#5c0d1b] text-rose-100 ring-2 ring-rose-700/80',
    mythos: 'bg-purple-700/80 hover:bg-purple-600 text-white',
    encounters: 'bg-emerald-800/80 hover:bg-emerald-700 text-white',
    encountersActive: 'bg-emerald-500 text-white ring-2 ring-emerald-300',
    other: 'bg-sky-800/80 hover:bg-sky-700 text-white',
    otherActive: 'bg-sky-500 text-white ring-2 ring-sky-300',
};

const isEncountersActive = computed(() => url.value.startsWith('/encounters'));
const isOtherActive = computed(() => url.value.startsWith('/other'));
const isActionMuted = computed(() => playingFolder.value === 'action-muted');
const isCombatEpic = computed(() => playingFolder.value === 'combat-epic');
const actionLabel = computed(() => (isActionMuted.value ? 'Muted Action' : 'Action'));
const combatLabel = computed(() => (isCombatEpic.value ? 'Epic Combat' : 'Combat'));
const actionClass = computed(() => (isActionMuted.value ? variantClasses.actionMuted : variantClasses.action));
const combatClass = computed(() => (isCombatEpic.value ? variantClasses.combatEpic : variantClasses.combat));

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
    threshold: 1000,
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
    threshold: 1000,
});

const mythosBindings = useLongPress({
    onTap: () => toggle('mythos', 'Mythos'),
    threshold: 5000, // effectively no long-press behaviour
});
</script>

<template>
    <header
        class="sticky top-0 z-30 flex flex-wrap gap-2 border-b border-neutral-800 bg-neutral-950/90 px-3 py-3 backdrop-blur"
    >
        <button
            type="button"
            class="group relative flex-1 min-w-[6rem] rounded-lg px-3 py-8 text-center text-[2.05rem] font-semibold tracking-wide active:scale-[0.98] transition"
            :class="actionClass"
            v-bind="actionBindings"
        >
            {{ actionLabel }}
            <span class="pointer-events-none absolute inset-x-0 bottom-1 text-[10px] font-normal opacity-0 transition-opacity duration-150 group-hover:opacity-70">
                hold for Muted
            </span>
        </button>

        <button
            type="button"
            class="group relative flex-1 min-w-[6rem] rounded-lg px-3 py-8 text-center text-[2.05rem] font-semibold tracking-wide active:scale-[0.98] transition"
            :class="combatClass"
            v-bind="combatBindings"
        >
            {{ combatLabel }}
            <span class="pointer-events-none absolute inset-x-0 bottom-1 text-[10px] font-normal opacity-0 transition-opacity duration-150 group-hover:opacity-70">
                hold for Epic
            </span>
        </button>

        <Link
            href="/encounters"
            class="flex flex-1 min-w-[6rem] items-center justify-center gap-1 rounded-lg px-3 py-8 text-3xl font-semibold tracking-wide active:scale-[0.98] transition"
            :class="isEncountersActive ? variantClasses.encountersActive : variantClasses.encounters"
        >
            Encounters
            <span aria-hidden="true">›</span>
        </Link>

        <button
            type="button"
            class="flex-1 min-w-[6rem] rounded-lg px-3 py-8 text-3xl font-semibold tracking-wide active:scale-[0.98] transition"
            :class="variantClasses.mythos"
            v-bind="mythosBindings"
        >
            Mythos
        </button>

        <Link
            href="/other"
            class="flex flex-1 min-w-[6rem] items-center justify-center gap-1 rounded-lg px-3 py-8 text-3xl font-semibold tracking-wide active:scale-[0.98] transition"
            :class="isOtherActive ? variantClasses.otherActive : variantClasses.other"
        >
            Other
            <span aria-hidden="true">›</span>
        </Link>
    </header>
</template>
