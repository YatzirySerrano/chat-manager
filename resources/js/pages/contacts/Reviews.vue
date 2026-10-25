<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { CheckCheck, GitMerge, ShieldQuestion, UserCheck } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataPagination from '@/components/DataPagination.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/format';
import { formatPhone } from '@/lib/phone';
import { index as contactsIndex, show } from '@/routes/contacts';
import {
    index as reviewsIndex,
    update as resolveReview,
} from '@/routes/contacts/reviews';
import type {
    ContactReviewCase,
    ContactReviewResolutionValue,
    ContactReviewStatusValue,
    ContactReviewSummary,
    Paginated,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Contactos', href: contactsIndex() },
            { title: 'Revisión de duplicados', href: reviewsIndex() },
        ],
    },
});

const props = defineProps<{
    cases: Paginated<ContactReviewCase>;
    filters: { status: ContactReviewStatusValue };
    statuses: { value: ContactReviewStatusValue; label: string }[];
}>();

type PendingDecision = {
    reviewCase: ContactReviewCase;
    resolution: ContactReviewResolutionValue;
    target: ContactReviewSummary | null;
};

const pending = ref<PendingDecision | null>(null);
const confirmOpen = ref(false);
const processing = ref(false);

const confirmCopy = computed(() => {
    const decision = pending.value;

    if (decision === null) {
        return { title: '', description: '', label: '' };
    }

    const reviewed = decision.reviewCase.contact.name;
    const target = decision.target?.name ?? '';

    switch (decision.resolution) {
        case 'identity_linked':
            return {
                title: `¿Vincular el WhatsApp de «${reviewed}» a «${target}»?`,
                description:
                    'La identidad de WhatsApp, sus conexiones, su procedencia, notas y etiquetas pasan a la ficha existente. El registro automático queda eliminado y conserva su historial (puede restaurarse).',
                label: 'Vincular',
            };
        case 'duplicate_archived':
            return {
                title: `¿Eliminar «${reviewed}» como duplicado de «${target}»?`,
                description:
                    'El registro nuevo se elimina y conserva su historial (puede restaurarse); la ficha existente no cambia.',
                label: 'Eliminar duplicado',
            };
        default:
            return {
                title: '¿Son personas distintas?',
                description:
                    'Los contactos se mantienen como están y el caso se cierra.',
                label: 'Confirmar',
            };
    }
});

function decide(
    reviewCase: ContactReviewCase,
    resolution: ContactReviewResolutionValue,
    target: ContactReviewSummary | null = null,
): void {
    pending.value = { reviewCase, resolution, target };
    confirmOpen.value = true;
}

function confirmDecision(): void {
    const decision = pending.value;

    if (decision === null) {
        return;
    }

    router.patch(
        resolveReview.url(decision.reviewCase.id),
        {
            resolution: decision.resolution,
            target_contact_id: decision.target?.id ?? null,
        },
        {
            preserveScroll: true,
            onStart: () => (processing.value = true),
            onError: (errors) => {
                const message = Object.values(errors)[0];

                if (message) {
                    toast.error(message);
                }
            },
            onFinish: () => {
                processing.value = false;
                confirmOpen.value = false;
            },
        },
    );
}

function filterBy(status: ContactReviewStatusValue): void {
    router.get(
        reviewsIndex.url({ query: status === 'open' ? {} : { status } }),
        undefined,
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function phone(summary: ContactReviewSummary): string {
    return summary.phone_e164
        ? formatPhone(summary.phone_e164)
        : 'Sin teléfono';
}
</script>

<template>
    <Head title="Revisión de duplicados" />

    <div
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8"
    >
        <PageHeader
            help="contactos"
            title="Revisión de duplicados"
            description="Posibles duplicados que no se resolvieron automáticamente para no fusionar personas sin evidencia suficiente ni revelar contactos entre departamentos."
        />

        <div
            class="flex flex-wrap gap-2"
            role="group"
            aria-label="Estado de los casos"
        >
            <Button
                v-for="status in statuses"
                :key="status.value"
                size="sm"
                :variant="
                    filters.status === status.value ? 'default' : 'outline'
                "
                :aria-pressed="filters.status === status.value"
                @click="filterBy(status.value)"
            >
                {{ status.label }}
            </Button>
        </div>

        <EmptyState
            v-if="props.cases.data.length === 0"
            :icon="ShieldQuestion"
            :title="
                filters.status === 'open'
                    ? 'No hay posibles duplicados pendientes'
                    : 'Todavía no se ha resuelto ningún caso'
            "
            description="Aparecen aquí cuando un remitente de WhatsApp coincide de forma ambigua con fichas existentes, o cuando alguien registra a mano un teléfono o correo que ya pertenece a contactos fuera de su alcance."
        />

        <SurfaceCard
            v-for="reviewCase in props.cases.data"
            :key="reviewCase.id"
            class="grid gap-4 p-4"
        >
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div class="min-w-0">
                    <p class="font-medium">{{ reviewCase.reason_label }}</p>
                    <p class="text-xs text-muted-foreground">
                        Detectado {{ formatDateTime(reviewCase.created_at) }}
                    </p>
                </div>
                <p
                    v-if="reviewCase.status === 'resolved'"
                    class="text-sm text-muted-foreground"
                >
                    <CheckCheck
                        class="mr-1 inline size-4 text-success"
                        aria-hidden="true"
                    />{{ reviewCase.resolution_label }}
                    <template v-if="reviewCase.resolved_by">
                        · {{ reviewCase.resolved_by }}</template
                    >
                    · {{ formatDateTime(reviewCase.resolved_at) }}
                </p>
                <Button
                    v-else
                    size="sm"
                    variant="outline"
                    @click="decide(reviewCase, 'distinct_people')"
                >
                    <UserCheck />
                    Son personas distintas
                </Button>
            </div>

            <div class="grid gap-3 md:grid-cols-2">
                <section
                    class="grid content-start gap-1 rounded-lg border p-3"
                    aria-label="Contacto en revisión"
                >
                    <p class="text-xs font-medium text-muted-foreground">
                        En revisión
                    </p>
                    <Link
                        :href="show(reviewCase.contact.id)"
                        class="truncate font-medium hover:underline"
                        >{{ reviewCase.contact.name }}</Link
                    >
                    <p class="text-sm text-muted-foreground">
                        {{ phone(reviewCase.contact) }}
                        <template v-if="reviewCase.contact.email">
                            · {{ reviewCase.contact.email }}</template
                        >
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ reviewCase.contact.source_label }}
                        <template v-if="reviewCase.contact.whatsapp_ids.length">
                            · WhatsApp
                            {{ reviewCase.contact.whatsapp_ids.join(', ') }}
                        </template>
                    </p>
                    <StatusBadge
                        :active="reviewCase.contact.status === 'active'"
                        active-label="Activo"
                        inactive-label="Eliminado"
                        class="justify-self-start"
                    />
                </section>

                <div class="grid content-start gap-3">
                    <section
                        v-for="candidate in reviewCase.candidates"
                        :key="candidate.id"
                        class="grid gap-1 rounded-lg border border-dashed p-3"
                        :aria-label="`Coincidencia: ${candidate.name}`"
                    >
                        <p class="text-xs font-medium text-muted-foreground">
                            Coincide con
                        </p>
                        <Link
                            :href="show(candidate.id)"
                            class="truncate font-medium hover:underline"
                            >{{ candidate.name }}</Link
                        >
                        <p class="text-sm text-muted-foreground">
                            {{ phone(candidate) }}
                            <template v-if="candidate.email">
                                · {{ candidate.email }}</template
                            >
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ candidate.source_label }}
                            <template v-if="candidate.whatsapp_ids.length">
                                · WhatsApp
                                {{ candidate.whatsapp_ids.join(', ') }}
                            </template>
                        </p>
                        <div
                            v-if="reviewCase.status === 'open'"
                            class="mt-1 flex flex-wrap gap-2"
                        >
                            <Button
                                v-if="
                                    reviewCase.reason ===
                                    'ambiguous_identity_match'
                                "
                                size="sm"
                                @click="
                                    decide(
                                        reviewCase,
                                        'identity_linked',
                                        candidate,
                                    )
                                "
                            >
                                <GitMerge />
                                Vincular WhatsApp a esta ficha
                            </Button>
                            <Button
                                v-else
                                size="sm"
                                variant="outline"
                                @click="
                                    decide(
                                        reviewCase,
                                        'duplicate_archived',
                                        candidate,
                                    )
                                "
                            >
                                Eliminar el registro en revisión
                            </Button>
                        </div>
                    </section>
                </div>
            </div>
        </SurfaceCard>

        <DataPagination
            v-if="props.cases.data.length > 0"
            :meta="props.cases.meta"
            noun="casos"
        />

        <ConfirmDialog
            v-model:open="confirmOpen"
            :title="confirmCopy.title"
            :description="confirmCopy.description"
            :confirm-label="confirmCopy.label"
            :destructive="pending?.resolution === 'duplicate_archived'"
            :processing="processing"
            @confirm="confirmDecision"
        />
    </div>
</template>
