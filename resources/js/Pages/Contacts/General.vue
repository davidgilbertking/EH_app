<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/App/Breadcrumb.vue';
import NavLinkButton from '@/Components/App/NavLinkButton.vue';

defineOptions({ layout: AppLayout });
</script>

<template>
    <Breadcrumb title="General" parent="Encounters" />

    <div class="mx-auto grid max-w-3xl gap-x-8 gap-y-6" style="grid-template-columns: repeat(3, minmax(0, 1fr))">
        <NavLinkButton href="/encounters/general/city"       label="City"       variant="city" />
        <NavLinkButton href="/encounters/general/wilderness" label="Wilderness" variant="wilderness" />
        <NavLinkButton href="/encounters/general/sea"        label="Sea"        variant="sea" />
    </div>
</template>
