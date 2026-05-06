<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const customCardSrc = '/images/auth/login-card.png';
const fallbackCardSrc = '/icons/src/plate.png';
const cardSrc = ref(customCardSrc);
const page = usePage();
const yellowSignImageUrl = computed(() => page.props.ui?.yellowSignImageUrl ?? null);

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
                <img
                    v-if="yellowSignImageUrl"
                    :src="yellowSignImageUrl"
                    alt="Yellow Sign"
                    class="h-24 w-24 object-contain drop-shadow-[0_0_10px_rgba(242,201,76,0.65)]"
                />
            </Link>
        </div>

        <div class="relative mt-6 w-full max-w-[400px] overflow-hidden rounded-2xl shadow-[0_20px_50px_rgba(0,0,0,0.55)]">
            <img
                :src="cardSrc"
                alt=""
                class="pointer-events-none absolute inset-0 h-full w-full object-fill"
                @error="onCardError"
            />
            <div class="relative mx-auto w-[82%] py-6 sm:py-7">
                <Transition name="ui-page-fade" mode="out-in">
                    <div :key="$page.url">
                        <slot />
                    </div>
                </Transition>
            </div>
        </div>
    </div>
</template>
