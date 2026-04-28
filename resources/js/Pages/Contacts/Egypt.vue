<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import Breadcrumb from '@/Components/App/Breadcrumb.vue';
import MapPage from '@/Components/App/MapPage.vue';

defineOptions({ layout: AppLayout });

// Red rim = city contact, brown rim = wilderness/desert contact (Egypt expansion).
const city = 'border-red-300/60 bg-red-800/90 text-red-50';
const wild = 'border-amber-200/60 bg-amber-900/95 text-amber-50';
const hotspots = [
    { x: 12.5,  y: 34, label: 'The Sahara Desert', folderSlug: 'contacts/egypt/sahara-desert', tone: wild },
    { x: 37.5, y: 21, label: 'Alexandria',        folderSlug: 'contacts/egypt/alexandria',    tone: city },
    { x: 65, y: 20, label: 'The Bent Pyramid',  folderSlug: 'contacts/egypt/bent-pyramid',  tone: city },
    { x: 80, y: 42, label: 'Cairo',             folderSlug: 'contacts/egypt/cairo',         tone: city },
    { x: 50, y: 65.5, label: 'Tel el-Amarna',     folderSlug: 'contacts/egypt/tel-el-amarna', tone: wild },
    { x: 71.5, y: 81.5, label: 'The Nile River',    folderSlug: 'contacts/egypt/nile-river',    tone: wild },
];
</script>

<template>
    <Breadcrumb title="Egypt" parent="Contacts › Add. Map" />
    <MapPage background-url="/maps/egypt.jpg" :hotspots="hotspots" />
</template>
