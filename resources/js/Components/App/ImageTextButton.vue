<script setup>
import { engine } from '@/audio/engine';
import { useLongPress } from '@/composables/useLongPress';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';

const props = defineProps({
    folderSlug: { type: String, required: true },
    label: { type: String, required: true },
    imageUrl: { type: String, default: null },
    href: { type: String, default: null },
    mode: { type: String, default: null },
    // tailwind-class string for the button background/border
    tone: { type: String, default: 'bg-neutral-900 hover:bg-neutral-800 border-neutral-700 text-neutral-100' },
});

const page = usePage();
const isPlaying = computed(() => engine.state.playingFolder === props.folderSlug);
const isPausedForResume = computed(() =>
    engine.state.isPaused && engine.state.pausedFolder === props.folderSlug
);
const isNav = computed(() => Boolean(props.href));
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
            id: 'blob-' + crypto.randomUUID(),
            label: props.label,
            folderSlug: props.folderSlug,
            mode: props.mode || null,
            tone: props.tone,
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

const bindings = useLongPress({ onTap: tap, onLongPress: longPress, threshold: 1000 });
</script>

<template>
    <Link
        v-if="isNav"
        :href="href"
        class="flex h-20 w-full items-center gap-3 rounded-xl border p-2 text-left active:scale-[0.98] transition"
        :class="tone"
    >
        <img
            v-if="imageUrl"
            :src="imageUrl"
            :alt="label"
            class="h-16 w-16 flex-none object-contain drop-shadow-[0_2px_4px_rgba(0,0,0,0.5)]"
        />
        <span
            v-else
            class="grid h-16 w-16 flex-none place-items-center rounded-lg bg-black/30 text-2xl opacity-60"
        >?</span>
        <span class="flex-1 text-xl font-bold leading-tight tracking-wide">{{ label }}</span>
    </Link>

    <button
        v-else
        type="button"
        class="flex h-20 w-full items-center gap-3 rounded-xl border p-2 text-left active:scale-[0.98] transition"
        :class="[
            tone,
            isPlaying ? 'ring-2 ring-amber-400' : '',
            isPausedForResume ? 'paused-amber-dash' : '',
            blobSavedPulse ? 'ring-2 ring-amber-400' : '',
        ]"
        v-bind="bindings"
    >
        <img
            v-if="imageUrl"
            :src="imageUrl"
            :alt="label"
            class="h-16 w-16 flex-none object-contain drop-shadow-[0_2px_4px_rgba(0,0,0,0.5)]"
        />
        <span
            v-else
            class="grid h-16 w-16 flex-none place-items-center rounded-lg bg-black/30 text-2xl opacity-60"
        >?</span>
        <span class="flex-1 text-xl font-bold leading-tight tracking-wide">{{ label }}</span>
    </button>
</template>
