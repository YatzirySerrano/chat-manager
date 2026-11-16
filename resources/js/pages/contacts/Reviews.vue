<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowRightLeft,
    CheckCheck,
    GitMerge,
    ShieldQuestion,
    Trash2,
    UserCheck,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataPagination from '@/components/DataPagination.vue';
import DirectoryAvatar from '@/components/directory/DirectoryAvatar.vue';
import FilteredEmpty from '@/components/filters/FilteredEmpty.vue';
import FilterTabs from '@/components/filters/FilterTabs.vue';
import type { FilterTabOption } from '@/components/filters/types';
import PageHeader from '@/components/PageHeader.vue';
import StatusBadge from '@/components/StatusBadge.vue';
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
                    'El número de WhatsApp, sus notas, etiquetas y procedencia pasan al contacto existente. La ficha repetida se elimina, pero su historial se conserva (puede restaurarse).',
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

/* The tab moves at once; the list follows when the server answers. */
const status = ref<ContactReviewStatusValue>(props.filters.status);
const loading = ref(false);

watch(
    () => props.filters.status,
    (value) => (status.value = value),
);

const statusTabs = computed<FilterTabOption<ContactReviewStatusValue>[]>(() =>
    props.statuses.map((option) => ({
        value: option.value,
        label: option.label,
    })),
);

function filterBy(value: ContactReviewStatusValue): void {
    if (value === status.value) {
        return;
    }

    status.value = value;

    router.get(
        reviewsIndex.url({ query: value === 'open' ? {} : { status: value } }),
        undefined,
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => (loading.value = true),
            onFinish: () => (loading.value = false),
        },
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

    <div class="page-shell">
        <PageHeader
            help="contactos"
            title="Revisión de duplicados"
            description="Contactos que podrían estar repetidos. El sistema no los une solo para no mezclar a personas distintas; decide tú si son la misma persona."
        />

        <FilterTabs
            :model-value="status"
            :options="statusTabs"
            label="Estado de los casos"
            class="w-fit max-w-full enter-fade"
            @update:model-value="filterBy"
        />

        <div
            v-if="props.cases.data.length === 0"
            class="reveal surface-float"
            :aria-busy="loading"
        >
            <FilteredEmpty
                :narrowed="false"
                narrowed-title=""
                :icon="ShieldQuestion"
                :title="
                    filters.status === 'open'
                        ? 'No hay posibles duplicados pendientes'
                        : 'Todavía no se ha resuelto ningún caso'
                "
                description="Aparecen aquí cuando alguien escribe por WhatsApp y se parece a varios contactos, o cuando se agrega a mano un teléfono o correo que ya usa otro contacto."
            />
        </div>

        <ul
            v-else
            role="list"
            class="auto-grid items-start transition-opacity [--col-min:34rem] [--grid-gap:1rem]"
            :class="loading && 'opacity-60'"
            :aria-busy="loading"
        >
            <li
                v-for="(reviewCase, position) in props.cases.data"
                :key="reviewCase.id"
                class="grid enter-spring gap-4 surface-float p-4 sm:p-5"
                :style="{ '--i': Math.min(position, 8) }"
            >
                <header
                    class="flex flex-wrap items-start justify-between gap-3"
                >
                    <div class="flex min-w-0 items-start gap-3">
                        <span
                            class="flex size-9 shrink-0 items-center justify-center rounded-xl"
                            :class="
                                reviewCase.status === 'resolved'
                                    ? 'bg-success-soft text-success-soft-foreground'
                                    : 'bg-warning-soft text-warning-soft-foreground'
                            "
                            aria-hidden="true"
                        >
                            <CheckCheck
                                v-if="reviewCase.status === 'resolved'"
                                class="size-4"
                            />
                            <ShieldQuestion v-else class="size-4" />
                        </span>
                        <div class="min-w-0">
                            <p class="font-display leading-snug font-semibold">
                                {{ reviewCase.reason_label }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                Detectado
                                {{ formatDateTime(reviewCase.created_at) }}
                            </p>
                        </div>
                    </div>
                    <p
                        v-if="reviewCase.status === 'resolved'"
                        class="rounded-full bg-success-soft px-2.5 py-1 text-xs font-medium text-success-soft-foreground"
                    >
                        {{ reviewCase.resolution_label }}
                        <template v-if="reviewCase.resolved_by">
                            · {{ reviewCase.resolved_by }}</template
                        >
                        · {{ formatDateTime(reviewCase.resolved_at) }}
                    </p>
                    <Button
                        v-else
                        size="sm"
                        variant="outline"
                        class="rounded-full max-sm:w-full"
                        @click="decide(reviewCase, 'distinct_people')"
                    >
                        <UserCheck />
                        Son personas distintas
                    </Button>
                </header>

                <div
                    class="grid items-stretch gap-3 md:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)]"
                >
                    <section
                        class="grid content-start gap-1.5 rounded-xl border border-warning/40 bg-[color-mix(in_oklch,var(--warning)_6%,var(--card))] p-3.5"
                        aria-label="Contacto en revisión"
                    >
                        <p class="text-xs font-medium text-muted-foreground">
                            En revisión
                        </p>
                        <div class="flex min-w-0 items-center gap-2.5">
                            <DirectoryAvatar
                                :name="reviewCase.contact.name"
                                size="sm"
                            />
                            <Link
                                :href="show(reviewCase.contact.id)"
                                class="min-w-0 truncate rounded-sm font-semibold outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring"
                                >{{ reviewCase.contact.name }}</Link
                            >
                        </div>
                        <p class="text-sm break-words text-muted-foreground">
                            {{ phone(reviewCase.contact) }}
                            <template v-if="reviewCase.contact.email">
                                · {{ reviewCase.contact.email }}</template
                            >
                        </p>
                        <p class="text-xs break-words text-muted-foreground">
                            {{ reviewCase.contact.source_label }}
                            <template
                                v-if="reviewCase.contact.whatsapp_ids.length"
                            >
                                · WhatsApp
                                {{ reviewCase.contact.whatsapp_ids.join(', ') }}
                            </template>
                        </p>
                        <StatusBadge
                            :active="reviewCase.contact.status === 'active'"
                            active-label="Activo"
                            inactive-label="Eliminado"
                            class="mt-0.5 justify-self-start"
                        />
                    </section>

                    <span
                        class="flex items-center justify-center text-muted-foreground"
                        aria-hidden="true"
                    >
                        <span
                            class="flex size-8 items-center justify-center rounded-full border bg-card shadow-(--shadow-control) max-md:rotate-90"
                        >
                            <ArrowRightLeft class="size-4" />
                        </span>
                    </span>

                    <div class="grid content-start gap-3">
                        <section
                            v-for="candidate in reviewCase.candidates"
                            :key="candidate.id"
                            class="grid gap-1.5 rounded-xl border border-dashed border-border-strong/70 bg-card p-3.5"
                            :aria-label="`Coincidencia: ${candidate.name}`"
                        >
                            <p
                                class="text-xs font-medium text-muted-foreground"
                            >
                                Coincide con
                            </p>
                            <div class="flex min-w-0 items-center gap-2.5">
                                <DirectoryAvatar
                                    :name="candidate.name"
                                    size="sm"
                                />
                                <Link
                                    :href="show(candidate.id)"
                                    class="min-w-0 truncate rounded-sm font-semibold outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring"
                                    >{{ candidate.name }}</Link
                                >
                            </div>
                            <p
                                class="text-sm break-words text-muted-foreground"
                            >
                                {{ phone(candidate) }}
                                <template v-if="candidate.email">
                                    · {{ candidate.email }}</template
                                >
                            </p>
                            <p
                                class="text-xs break-words text-muted-foreground"
                            >
                                {{ candidate.source_label }}
                                <template v-if="candidate.whatsapp_ids.length">
                                    · WhatsApp
                                    {{ candidate.whatsapp_ids.join(', ') }}
                                </template>
                            </p>
                            <div
                                v-if="reviewCase.status === 'open'"
                                class="mt-1.5 flex flex-wrap gap-2"
                            >
                                <Button
                                    v-if="
                                        reviewCase.reason ===
                                        'ambiguous_identity_match'
                                    "
                                    size="sm"
                                    class="max-sm:w-full"
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
                                    class="rounded-full text-destructive hover:bg-destructive/10 hover:text-destructive max-sm:w-full"
                                    @click="
                                        decide(
                                            reviewCase,
                                            'duplicate_archived',
                                            candidate,
                                        )
                                    "
                                >
                                    <Trash2 />
                                    Eliminar el registro en revisión
                                </Button>
                            </div>
                        </section>
                    </div>
                </div>
            </li>
        </ul>

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
