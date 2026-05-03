<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

defineProps({
    status: {
        type: String,
    },
});

const form = useForm({
    email: '',
    password: '',
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="" />

        <div v-if="status" class="mb-4 text-sm font-medium text-emerald-300">
            {{ status }}
        </div>

        <form class="mx-auto w-[90%] space-y-3" @submit.prevent="submit">
            <div>
                <div class="w-full">
                    <TextInput
                        id="email"
                        type="email"
                        class="block w-full rounded-xl border border-[#2f435f] bg-[#07152c]/95 text-[#e6e2d7] shadow-none focus:border-[#7c6a49] focus:ring-[#7c6a49]"
                        v-model="form.email"
                        aria-label="email"
                        required
                        autofocus
                        autocomplete="username"
                    />
                </div>

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div>
                <div class="w-full">
                    <TextInput
                        id="password"
                        type="password"
                        class="block w-full rounded-xl border border-[#2f435f] bg-[#07152c]/95 text-[#e6e2d7] shadow-none focus:border-[#7c6a49] focus:ring-[#7c6a49]"
                        v-model="form.password"
                        aria-label="password"
                        required
                        autocomplete="current-password"
                    />
                </div>

                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div class="mt-1 flex items-center justify-center">
                <button
                    type="submit"
                    class="inline-flex h-11 w-24 -translate-y-1 items-center justify-center rounded-xl border border-[#f2c94c] bg-[#f2c94c] text-[#2a2a2a] transition hover:bg-[#f6d66c]"
                    :class="[
                        form.processing ? 'opacity-25' : '',
                    ]"
                    :disabled="form.processing"
                >
                    <span class="sr-only">submit</span>
                </button>
            </div>
        </form>
    </GuestLayout>
</template>
