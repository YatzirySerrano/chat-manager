<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ListChecks, MessageCircleQuestion, Send, Undo2 } from '@lucide/vue';
import PageHeader from '@/components/PageHeader.vue';
import MissingInfoCard from '@/components/settings/MissingInfoCard.vue';
import { edit as missingInfoEdit } from '@/routes/admin/missing-info';
import type { MissingInfoCatalog } from '@/types';

/**
 * «Información faltante»: the options an agent ticks in «Marcar que
 * necesita información» and the message the customer receives.
 */
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Información faltante', href: missingInfoEdit() },
        ],
    },
});

defineProps<{
    catalog: MissingInfoCatalog;
    can: { update: boolean };
}>();

const steps = [
    {
        icon: ListChecks,
        title: 'El agente marca qué falta',
        text: 'Elige de esta lista o escribe «Otro».',
    },
    {
        icon: Send,
        title: 'El cliente recibe la lista',
        text: 'Por WhatsApp, con tu mensaje de introducción.',
    },
    {
        icon: Undo2,
        title: 'La solicitud espera',
        text: 'Regresa a Pendientes hasta que esté completa.',
    },
];
</script>

<template>
    <Head title="Información faltante" />

    <div class="page-shell">
        <PageHeader
            title="Información faltante"
            description="Lo que el equipo le puede pedir al cliente cuando a su solicitud le falta algo."
        />

        <ol class="grid gap-3 sm:grid-cols-3" aria-label="Cómo funciona">
            <li
                v-for="(step, index) in steps"
                :key="step.title"
                class="flex items-start gap-3 rounded-xl border bg-card p-4"
            >
                <span
                    class="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"
                >
                    <component :is="step.icon" class="size-4" />
                </span>
                <span class="grid gap-0.5">
                    <span class="text-sm font-medium"
                        >{{ index + 1 }}. {{ step.title }}</span
                    >
                    <span class="text-xs text-muted-foreground">{{
                        step.text
                    }}</span>
                </span>
            </li>
        </ol>

        <div
            v-if="!can.update"
            class="flex items-center gap-2 rounded-xl border border-dashed p-3 text-sm text-muted-foreground"
        >
            <MessageCircleQuestion class="size-4" />
            Solo puedes consultar la lista; pide a un administrador que la
            cambie.
        </div>

        <MissingInfoCard :catalog="catalog" :can-update="can.update" />
    </div>
</template>
