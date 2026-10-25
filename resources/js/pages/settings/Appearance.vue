<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
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

    <h1 class="sr-only">Apariencia</h1>

    <div class="space-y-6">
        <p class="text-sm text-pretty text-muted-foreground">
            Elige el tema de la interfaz.
        </p>
        <AppearanceTabs />
    </div>
</template>
