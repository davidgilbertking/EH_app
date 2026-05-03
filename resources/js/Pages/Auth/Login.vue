<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
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
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Log in" />

        <div v-if="status" class="mb-4 text-sm font-medium text-emerald-300">
            {{ status }}
        </div>

        <form class="mx-auto w-[90%] space-y-4" @submit.prevent="submit">
            <div>
                <InputLabel for="email" value="Email" class="text-lg text-[#d4c19a] dark:text-[#d4c19a]" />

                <div class="mt-1 w-full">
                    <TextInput
                        id="email"
                        type="email"
                        class="block w-full rounded-xl border border-[#2f435f] bg-[#07152c]/95 text-[#e6e2d7] shadow-none focus:border-[#7c6a49] focus:ring-[#7c6a49]"
                        v-model="form.email"
                        required
                        autofocus
                        autocomplete="username"
                    />
                </div>

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div>
                <InputLabel for="password" value="Password" class="text-lg text-[#d4c19a] dark:text-[#d4c19a]" />

                <div class="mt-1 w-full">
                    <TextInput
                        id="password"
                        type="password"
                        class="block w-full rounded-xl border border-[#2f435f] bg-[#07152c]/95 text-[#e6e2d7] shadow-none focus:border-[#7c6a49] focus:ring-[#7c6a49]"
                        v-model="form.password"
                        required
                        autocomplete="current-password"
                    />
                </div>

                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div class="block">
                <label class="flex items-center">
                    <Checkbox
                        name="remember"
                        v-model:checked="form.remember"
                        class="rounded border-[#4c5e79] bg-[#07152c] text-[#c8b17c] focus:ring-[#c8b17c]"
                    />
                    <span class="ms-2 text-sm text-[#b5af9e] dark:text-[#b5af9e]"
                        >Remember me</span
                    >
                </label>
            </div>

            <div class="mt-2 flex items-center justify-end pe-4">
                <button
                    type="submit"
                    class="-translate-y-1"
                    :class="[
                        'inline-flex items-center rounded-xl border border-[#8a7a56] bg-[#ded7c8] px-6 py-2 text-base font-semibold tracking-widest text-[#2a2a2a] transition hover:bg-[#f0eadf]',
                        form.processing ? 'opacity-25' : '',
                    ]"
                    :disabled="form.processing"
                >
                    Log in
                </button>
            </div>
        </form>
    </GuestLayout>
</template>
