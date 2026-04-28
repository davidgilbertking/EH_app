<script setup>
import { engine } from '@/audio/engine';
import { useLongPress } from '@/composables/useLongPress';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const url = computed(() => page.url || '/');

// Variant -> Tailwind class lookup. Header variants used to colour-code buttons.
const variantClasses = {
    action: 'bg-amber-700/80 hover:bg-amber-600 text-white',
    combat: 'bg-rose-700/80 hover:bg-rose-600 text-white',
    mythos: 'bg-purple-700/80 hover:bg-purple-600 text-white',
    contacts: 'bg-emerald-800/80 hover:bg-emerald-700 text-white',
    contactsActive: 'bg-emerald-500 text-white ring-2 ring-emerald-300',
    special: 'bg-sky-800/80 hover:bg-sky-700 text-white',
    specialActive: 'bg-sky-500 text-white ring-2 ring-sky-300',
};

const isContactsActive = computed(() => url.value.startsWith('/contacts'));
const isSpecialActive = computed(() => url.value.startsWith('/special'));

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
    onTap: () => toggle('action', 'Action'),
    onLongPress: () => toggle('action-muted', 'Muted Action'),
    threshold: 1000,
});

const combatBindings = useLongPress({
    onTap: () => toggle('combat', 'Combat'),
    onLongPress: () => toggle('combat-epic', 'Epic Combat'),
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
            class="group relative flex-1 min-w-[6rem] rounded-lg px-3 py-8 text-center text-2xl font-semibold tracking-wide active:scale-[0.98] transition"
            :class="variantClasses.action"
            v-bind="actionBindings"
        >
            Action
            <span class="pointer-events-none absolute inset-x-0 bottom-1 text-[10px] font-normal opacity-0 transition-opacity duration-150 group-hover:opacity-70">
                hold = Muted
            </span>
        </button>

        <button
            type="button"
            class="group relative flex-1 min-w-[6rem] rounded-lg px-3 py-8 text-center text-2xl font-semibold tracking-wide active:scale-[0.98] transition"
            :class="variantClasses.combat"
            v-bind="combatBindings"
        >
            Combat
            <span class="pointer-events-none absolute inset-x-0 bottom-1 text-[10px] font-normal opacity-0 transition-opacity duration-150 group-hover:opacity-70">
                hold = Epic
            </span>
        </button>

        <Link
            href="/contacts"
            class="flex flex-1 min-w-[6rem] items-center justify-center gap-1 rounded-lg px-3 py-8 text-2xl font-semibold tracking-wide active:scale-[0.98] transition"
            :class="isContactsActive ? variantClasses.contactsActive : variantClasses.contacts"
        >
            Contacts
            <span aria-hidden="true">›</span>
        </Link>

        <button
            type="button"
            class="flex-1 min-w-[6rem] rounded-lg px-3 py-8 text-2xl font-semibold tracking-wide active:scale-[0.98] transition"
            :class="variantClasses.mythos"
            v-bind="mythosBindings"
        >
            Mythos
        </button>

        <Link
            href="/special"
            class="flex flex-1 min-w-[6rem] items-center justify-center gap-1 rounded-lg px-3 py-8 text-2xl font-semibold tracking-wide active:scale-[0.98] transition"
            :class="isSpecialActive ? variantClasses.specialActive : variantClasses.special"
        >
            Special
            <span aria-hidden="true">›</span>
        </Link>
    </header>
</template>
