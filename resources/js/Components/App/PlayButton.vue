<script setup>
import { engine } from '@/audio/engine';
import { useLongPress } from '@/composables/useLongPress';
import { makeBlobId } from '@/utils/blobId';
import { router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';

/**
 * A button that plays audio on tap. Long-press adds the button as a "blob"
 * to the home page (round playlist). Used for any non-header, non-navigation
 * button on Contacts/Special pages.
 */
const props = defineProps({
    folderSlug: { type: String, required: true },
    label: { type: String, required: true },
    labelClass: { type: String, default: '' },
    mobileShortLabel: { type: String, default: '' },
    imageUrl: { type: String, default: null },
    showImage: { type: Boolean, default: false },
    mode: { type: String, default: null },
    variant: { type: String, default: 'default' }, // 'default'|'special'|'contacts'
    // Optional Tailwind class string that overrides `variant`. Used by pages
    // that need per-button colours (e.g. Past/Future contacts).
    tone: { type: String, default: null },
    big: { type: Boolean, default: true },
});

const variantClasses = {
    default:     'bg-neutral-800 hover:bg-neutral-700 text-neutral-100 border-neutral-700',
    special:     'bg-sky-900/70 hover:bg-sky-800 text-sky-50 border-sky-800',
    contacts:    'bg-emerald-900/70 hover:bg-emerald-800 text-emerald-50 border-emerald-800',
    danger:      'bg-rose-900/70 hover:bg-rose-800 text-rose-50 border-rose-800',
    // semantic colours
    city:        'bg-neutral-800 hover:bg-neutral-700 text-neutral-100 border-neutral-600',
    wilderness:  'bg-green-900/70 hover:bg-green-800 text-green-50 border-green-700',
    sea:         'bg-sky-800/70 hover:bg-sky-700 text-sky-50 border-sky-600',
    obstruction: 'bg-stone-800 hover:bg-stone-700 text-stone-100 border-stone-600',
    expedition:  'bg-amber-900/70 hover:bg-amber-800 text-amber-50 border-amber-700',
    addmap:      'bg-teal-900/70 hover:bg-teal-800 text-teal-50 border-teal-700',
    devastation: 'bg-rose-950 hover:bg-rose-900 text-rose-100 border-rose-800',
    elder:       'bg-amber-800 hover:bg-amber-700 text-amber-50 border-amber-600',
    defeated:    'bg-slate-800 hover:bg-slate-700 text-slate-100 border-slate-600',
    // Special
    defeat:      'bg-zinc-800 hover:bg-zinc-700 text-zinc-100 border-zinc-600',
    victory:     'bg-lime-700 hover:bg-lime-600 text-lime-50 border-lime-500',
    awakening:   'bg-stone-500 hover:bg-stone-400 text-stone-50 border-stone-300',
    disaster:    'bg-red-950 hover:bg-red-900 text-red-100 border-red-800',
    deathSanity: 'bg-blue-900 hover:bg-blue-800 text-blue-50 border-blue-700',
    deathHealth: 'bg-red-700 hover:bg-red-600 text-red-50 border-red-500',
    deathSacrifice: 'bg-orange-300 hover:bg-orange-200 text-orange-950 border-orange-400',
    honey:       'bg-yellow-800/80 hover:bg-yellow-700 text-yellow-50 border-yellow-600',
    investigators:'bg-indigo-900/70 hover:bg-indigo-800 text-indigo-50 border-indigo-700',
    ancients:    'bg-fuchsia-900/70 hover:bg-fuchsia-800 text-fuchsia-50 border-fuchsia-700',
    // Disaster sub-categories
    weather:     'bg-cyan-900/70 hover:bg-cyan-800 text-cyan-50 border-cyan-700',
    location:    'bg-orange-900/70 hover:bg-orange-800 text-orange-50 border-orange-700',
    // Contacts sub
    bigCity:     'bg-emerald-800 hover:bg-emerald-700 text-emerald-50 border-emerald-600',
    otherWorld:  'bg-purple-900/70 hover:bg-purple-800 text-purple-50 border-purple-700',
    // Other-World per-location
    carcosa:     'bg-yellow-900/70 hover:bg-yellow-800 text-yellow-50 border-yellow-700',
    greatRace:   'bg-lime-900/70 hover:bg-lime-800 text-lime-50 border-lime-700',
    yuggoth:     'bg-violet-950 hover:bg-violet-900 text-violet-100 border-violet-800',
    celaeno:     'bg-cyan-900/70 hover:bg-cyan-800 text-cyan-50 border-cyan-700',
    leng:        'bg-teal-900/70 hover:bg-teal-800 text-teal-50 border-teal-700',
    dreamlands:  'bg-fuchsia-900/70 hover:bg-fuchsia-800 text-fuchsia-50 border-fuchsia-700',
    kadath:      'bg-indigo-900/70 hover:bg-indigo-800 text-indigo-50 border-indigo-700',
    underworld:  'bg-zinc-900 hover:bg-zinc-800 text-zinc-100 border-zinc-700',
    abyss:       'bg-slate-900 hover:bg-slate-800 text-slate-100 border-slate-700',
    past:        'bg-stone-800 hover:bg-stone-700 text-stone-100 border-stone-600',
    future:      'bg-sky-900/80 hover:bg-sky-800 text-sky-50 border-sky-700',
};

const cls = computed(() => props.tone || variantClasses[props.variant] || variantClasses.default);
const hasVisual = computed(() => props.showImage && Boolean(props.imageUrl));

const page = usePage();
const playingFolder = computed(() => engine.state.playingFolder);
const isPlaying = computed(() => playingFolder.value === props.folderSlug);
const isPausedForResume = computed(() =>
    engine.state.isPaused && engine.state.pausedFolder === props.folderSlug
);
const blobSavedPulse = ref(false);
let pulseTimer = null;

function pulseBlobSaved() {
    blobSavedPulse.value = true;
    if (pulseTimer) clearTimeout(pulseTimer);
    pulseTimer = setTimeout(() => {
        blobSavedPulse.value = false;
        pulseTimer = null;
    }, 360);
}

onBeforeUnmount(() => {
    if (pulseTimer) clearTimeout(pulseTimer);
});

function tap() {
    // If the same folder is already playing, treat a second tap as "stop" so
    // the user always has a way to silence the current track (there is no
    // dedicated stop button on most pages).
    if (isPlaying.value) {
        engine.stop();
        return;
    }
    engine.play({ folderSlug: props.folderSlug, mode: props.mode, label: props.label });
}

function longPress() {
    const current = page.props.gameState?.blobs ?? [];
    if (current.find((b) => b.folderSlug === props.folderSlug)) return;
    const updated = [
        ...current,
        {
            id: makeBlobId(),
            label: props.label,
            folderSlug: props.folderSlug,
            mode: props.mode || null,
            // Snapshot the button's current colour so the blob inherits it.
            tone: cls.value,
            imageUrl: props.imageUrl || null,
        },
    ];
    router.post(
        '/state/blobs',
        { blobs: updated },
        {
            preserveState: true,
            preserveScroll: true,
            only: ['gameState'],
            onSuccess: pulseBlobSaved,
        }
    );
}

const bindings = useLongPress({
    onTap: tap,
    onLongPress: longPress,
    threshold: 1000,
});
</script>

<template>
    <button
        type="button"
        class="h-full rounded-xl border px-3 text-center font-semibold tracking-wide active:scale-[0.98] transition"
        :class="[
            cls,
            big ? 'ui-main-btn' : 'ui-main-btn-small',
            isPlaying ? 'ring-2 ring-amber-400' : '',
            isPausedForResume ? 'paused-amber-dash' : '',
            blobSavedPulse ? 'ring-2 ring-amber-400' : '',
        ]"
        v-bind="bindings"
    >
        <span
            class="inline-flex w-full items-center gap-3"
            :class="hasVisual ? 'justify-start text-left' : 'justify-center text-center'"
        >
            <span
                v-if="hasVisual"
                class="ui-main-btn-icon flex-none overflow-hidden rounded-full border-0 bg-transparent ring-0 outline-none"
            >
                <img
                    :src="imageUrl"
                    :alt="label"
                    class="h-full w-full scale-110 object-cover"
                />
            </span>
            <span class="min-w-0 max-w-full whitespace-normal break-words leading-tight" :class="props.labelClass">
                <template v-if="props.mobileShortLabel">
                    <span class="ui-phone-short-mobile-only">{{ props.mobileShortLabel }}</span>
                    <span class="ui-phone-short-desktop-only">{{ label }}</span>
                </template>
                <template v-else>{{ label }}</template>
            </span>
        </span>
    </button>
</template>
