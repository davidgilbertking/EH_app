<script setup>
import YellowSignGlyph from '@/Components/YellowSignGlyph.vue';
import { useYellowSignFavicon } from '@/composables/useYellowSignFavicon';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const customCardSrc = '/images/auth/login-card.png';
const fallbackCardSrc = '/icons/src/plate.png';
const cardSrc = ref(customCardSrc);
const page = usePage();
const yellowSignSeed = computed(() => page.props.ui?.yellowSignSeed ?? null);

useYellowSignFavicon(yellowSignSeed);

function onCardError() {
    if (cardSrc.value !== fallbackCardSrc) {
        cardSrc.value = fallbackCardSrc;
    }
}
</script>

<template>
    <div class="flex min-h-screen flex-col items-center bg-[#04080f] px-4 pt-6 sm:justify-center sm:pt-0">
        <div>
            <Link href="/">
                <div class="h-24 w-24">
                    <YellowSignGlyph
                        :seed="yellowSignSeed"
                        color="#f2c94c"
                    />
                </div>
            </Link>
        </div>

        <div class="relative mt-6 w-full max-w-[600px] overflow-hidden rounded-2xl shadow-[0_20px_50px_rgba(0,0,0,0.55)]">
            <img
                :src="cardSrc"
                alt=""
                class="pointer-events-none absolute inset-0 h-full w-full object-fill"
                @error="onCardError"
            />
            <div class="relative mx-auto w-[82%] py-9 sm:py-10">
                <slot />
            </div>
        </div>
    </div>
</template>
