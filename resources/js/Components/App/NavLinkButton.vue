<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * A navigation button that goes to another Inertia page. Renders an arrow.
 * No audio playback; long-press is ignored.
 */
const props = defineProps({
    href: { type: String, required: true },
    label: { type: String, required: true },
    variant: { type: String, default: 'contacts' }, // matches PlayButton variants
    // Optional Tailwind class string that overrides `variant`. Lets pages
    // hard-code per-button colours without polluting the variant map.
    tone: { type: String, default: null },
    imageUrl: { type: String, default: null },
    showImage: { type: Boolean, default: false },
    big: { type: Boolean, default: true },
});

const variantClasses = {
    default:     'bg-neutral-800 hover:bg-neutral-700 text-neutral-100 border-neutral-700',
    special:     'bg-sky-900/70 hover:bg-sky-800 text-sky-50 border-sky-800',
    contacts:    'bg-emerald-900/70 hover:bg-emerald-800 text-emerald-50 border-emerald-800',
    city:        'bg-neutral-800 hover:bg-neutral-700 text-neutral-100 border-neutral-600',
    wilderness:  'bg-green-900/70 hover:bg-green-800 text-green-50 border-green-700',
    sea:         'bg-sky-800/70 hover:bg-sky-700 text-sky-50 border-sky-600',
    obstruction: 'bg-stone-800 hover:bg-stone-700 text-stone-100 border-stone-600',
    expedition:  'bg-amber-900/70 hover:bg-amber-800 text-amber-50 border-amber-700',
    addmap:      'bg-teal-900/70 hover:bg-teal-800 text-teal-50 border-teal-700',
    defeated:    'bg-slate-800 hover:bg-slate-700 text-slate-100 border-slate-600',
    disaster:    'bg-red-950 hover:bg-red-900 text-red-100 border-red-800',
    investigators:'bg-indigo-900/70 hover:bg-indigo-800 text-indigo-50 border-indigo-700',
    ancients:    'bg-fuchsia-900/70 hover:bg-fuchsia-800 text-fuchsia-50 border-fuchsia-700',
    bigCity:     'bg-emerald-800 hover:bg-emerald-700 text-emerald-50 border-emerald-600',
    otherWorld:  'bg-purple-900/70 hover:bg-purple-800 text-purple-50 border-purple-700',
    weather:     'bg-cyan-900/70 hover:bg-cyan-800 text-cyan-50 border-cyan-700',
    location:    'bg-orange-900/70 hover:bg-orange-800 text-orange-50 border-orange-700',
    past:        'bg-stone-800 hover:bg-stone-700 text-stone-100 border-stone-600',
    future:      'bg-sky-900/80 hover:bg-sky-800 text-sky-50 border-sky-700',
    elder:       'bg-amber-800 hover:bg-amber-700 text-amber-50 border-amber-600',
};

const hasVisual = computed(() => props.showImage && Boolean(props.imageUrl));
</script>

<template>
    <Link
        :href="props.href"
        class="flex h-full items-center justify-center rounded-xl border px-3 text-center font-semibold tracking-wide active:scale-[0.98] transition"
        :class="[
            props.tone || variantClasses[props.variant] || variantClasses.default,
            props.big ? 'ui-main-btn' : 'ui-main-btn-small',
        ]"
    >
        <span
            class="inline-flex w-full items-center"
            :class="hasVisual ? 'justify-start gap-3 text-left' : 'justify-center gap-1 text-center'"
        >
            <img
                v-if="hasVisual"
                :src="props.imageUrl"
                :alt="props.label"
                class="ui-main-btn-icon flex-none object-contain drop-shadow-[0_2px_4px_rgba(0,0,0,0.55)]"
            />
            <span>{{ props.label }}</span>
            <span
                aria-hidden="true"
                :class="props.big ? 'text-[1.15em] leading-none' : 'text-sm leading-none'"
            >›</span>
        </span>
    </Link>
</template>
