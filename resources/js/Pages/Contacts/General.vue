<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/App/Breadcrumb.vue';
import NavLinkButton from '@/Components/App/NavLinkButton.vue';

defineOptions({ layout: AppLayout });
</script>

<template>
    <Breadcrumb title="General" parent="Contacts" />

    <div class="mx-auto grid max-w-3xl gap-3" style="grid-template-columns: repeat(3, minmax(0, 1fr))">
        <NavLinkButton href="/contacts/general/city"       label="City"       variant="city" />
        <NavLinkButton href="/contacts/general/wilderness" label="Wilderness" variant="wilderness" />
        <NavLinkButton href="/contacts/general/sea"        label="Sea"        variant="sea" />
    </div>
</template>
