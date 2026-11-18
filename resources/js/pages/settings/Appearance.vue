<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import { Palette } from '@lucide/vue';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { edit } from '@/routes/appearance';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Apariencia',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Apariencia" />

    <SettingsSection
        :icon="Palette"
        title="Tema"
        description="Claro, oscuro o el de tu sistema, en este navegador."
    >
        <AppearanceTabs />
    </SettingsSection>
</template>
