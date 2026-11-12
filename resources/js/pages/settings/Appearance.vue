<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import FormSection from '@/components/FormSection.vue';
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

    <FormSection
        title="Tema"
        description="Claro, oscuro o el que use tu sistema. Solo cambia en este navegador; los colores de la plataforma los define la administración."
    >
        <AppearanceTabs />
    </FormSection>
</template>
