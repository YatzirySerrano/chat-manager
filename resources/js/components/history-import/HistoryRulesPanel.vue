<script setup lang="ts">
import { Eye, Plus, Trash2, Wand2 } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import InputError from '@/components/InputError.vue';
import StatusNote from '@/components/StatusNote.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import {
    destroy as ruleDestroy,
    preview as rulePreview,
    store as ruleStore,
} from '@/routes/admin/history-import/rules';
import type {
    HistoryDecision,
    HistoryFacets,
    HistoryFilters,
    HistoryRule,
    HistorySummary,
} from '@/types/history-import';
import {
    compactFilters,
    errorMessage,
    historyRequest,
    isAborted,
    latestRequest,
} from './api';
import HistoryFilterFields from './HistoryFilterFields.vue';
import {
    DECISION_ORDER,
    DECISIONS,
    describeFilters,
    formatDay,
    formatNumber,
    plural,
} from './labels';

/**
 * Classification rules: a name, criteria (the inventory filters), a
 * destination and whether it also changes conversations that already have
 * one. The preview only counts; applying never changes decisions taken by
 * hand unless «cambiar también» is checked. Removing a rule sends its
 * conversations back to «Sin decidir».
 */
const props = defineProps<{
    batchId: number;
    rules: HistoryRule[];
    facets: HistoryFacets | null;
    editable: boolean;
    /** Current inventory filters (to start a rule from them). */
    currentFilters: HistoryFilters;
}>();

const emit = defineEmits<{
    changed: [summary: HistorySummary];
}>();

const formOpen = ref(false);
const name = ref('');
const decision = ref<HistoryDecision>('archive');
const overwrite = ref(false);
const criteria = ref<HistoryFilters>({});
const preview = ref<{
    matched: number;
    would_change: number;
    already_decided: number;
    not_allowed: number;
} | null>(null);
const previewing = ref(false);
const applying = ref(false);
const formError = ref<string | null>(null);
const nameError = ref<string | null>(null);

const criteriaText = computed(() => describeFilters(criteria.value));

/* The counts belong to the criteria they were asked for: any change drops them (and the request in flight). */
const previewRequest = latestRequest();

watch(
    [criteria, decision, overwrite],
    () => {
        previewRequest.cancel();
        preview.value = null;
        previewing.value = false;
    },
    { deep: true },
);

onBeforeUnmount(() => previewRequest.cancel());

/** What the preview depends on (the name does not change the counts). */
function previewKey(): string {
    return JSON.stringify({
        criteria: compactFilters(criteria.value),
        decision: decision.value,
        overwrite: overwrite.value,
    });
}

function yearsAgo(years: number): string {
    const date = new Date();
    date.setFullYear(date.getFullYear() - years);

    return date.toISOString().slice(0, 10);
}

const examples = computed(() => {
    const cutoff = yearsAgo(1);

    return [
        {
            label: `Archivar como histórico las conversaciones sin actividad a partir del ${formatDay(cutoff)}`,
            name: `Archivar sin actividad a partir del ${formatDay(cutoff)}`,
            decision: 'archive' as HistoryDecision,
            criteria: { inactive_since: cutoff } as HistoryFilters,
        },
        {
            label: 'No importar las conversaciones sin mensajes',
            name: 'No importar las vacías',
            decision: 'exclude' as HistoryDecision,
            criteria: { empty: true } as HistoryFilters,
        },
        {
            label: `Importar las conversaciones con actividad desde el ${formatDay(cutoff)}`,
            name: `Importar desde el ${formatDay(cutoff)}`,
            decision: 'import' as HistoryDecision,
            criteria: { date_from: cutoff } as HistoryFilters,
        },
        {
            label: 'Archivar los grupos',
            name: 'Archivar grupos',
            decision: 'archive' as HistoryDecision,
            criteria: { group: true } as HistoryFilters,
        },
    ];
});

function startRule(preset?: {
    name: string;
    decision: HistoryDecision;
    criteria: HistoryFilters;
}): void {
    name.value = preset?.name ?? '';
    decision.value = preset?.decision ?? 'archive';
    criteria.value = {
        ...(preset?.criteria ?? compactFilters(props.currentFilters)),
    };
    delete (criteria.value as Record<string, unknown>).decision;
    overwrite.value = false;
    preview.value = null;
    formError.value = null;
    nameError.value = null;
    formOpen.value = true;
}

function body(): Record<string, unknown> {
    return {
        name: name.value.trim(),
        criteria: compactFilters(criteria.value),
        decision: decision.value,
        overwrite: overwrite.value,
    };
}

async function runPreview(): Promise<void> {
    const signal = previewRequest.next();
    const askedFor = previewKey();
    previewing.value = true;
    formError.value = null;

    try {
        const response = await historyRequest<{
            preview: NonNullable<typeof preview.value>;
        }>('POST', rulePreview.url(props.batchId), body(), signal);

        if (askedFor === previewKey()) {
            preview.value = response.preview;
        }
    } catch (error) {
        if (isAborted(error)) {
            return;
        }

        formError.value = errorMessage(error);
    } finally {
        if (!signal.aborted) {
            previewing.value = false;
        }
    }
}

async function apply(): Promise<void> {
    nameError.value = null;

    if (name.value.trim() === '') {
        nameError.value =
            'Ponle un nombre a la regla para reconocerla después.';

        return;
    }

    applying.value = true;
    formError.value = null;

    try {
        const response = await historyRequest<{
            rule: HistoryRule;
            summary: HistorySummary;
        }>('POST', ruleStore.url(props.batchId), body());
        emit('changed', response.summary);
        toast.success(
            `Regla aplicada: ${plural(response.rule.applied, 'conversación cambió', 'conversaciones cambiaron')} de destino.`,
        );
        formOpen.value = false;
    } catch (error) {
        formError.value = errorMessage(error);
    } finally {
        applying.value = false;
    }
}

const removing = ref<HistoryRule | null>(null);
const removeOpen = ref(false);
const removeProcessing = ref(false);

function askRemove(rule: HistoryRule): void {
    removing.value = rule;
    removeOpen.value = true;
}

async function remove(): Promise<void> {
    const rule = removing.value;

    if (!rule) {
        return;
    }

    removeProcessing.value = true;

    try {
        const response = await historyRequest<{
            reverted: number;
            summary: HistorySummary;
        }>(
            'DELETE',
            ruleDestroy.url({ transfer: props.batchId, rule: rule.id }),
        );
        emit('changed', response.summary);
        toast.success(
            `Regla eliminada. ${plural(response.reverted, 'conversación volvió', 'conversaciones volvieron')} a «Sin decidir».`,
        );
        removeOpen.value = false;
    } catch (error) {
        toast.error(errorMessage(error));
    } finally {
        removeProcessing.value = false;
    }
}
</script>

<template>
    <SurfaceCard
        title="Reglas de clasificación"
        description="Decide muchas conversaciones a la vez con criterios. Primero ves cuántas cambiarían; las decisiones que ya tomaste no se tocan."
        body-class="grid gap-4 p-4 sm:p-5"
        data-tour="history-import.rules"
    >
        <template v-if="editable && !formOpen" #actions>
            <Button
                variant="outline"
                size="sm"
                class="pointer-coarse:h-10"
                @click="startRule()"
            >
                <Plus />
                Nueva regla
            </Button>
        </template>

        <div v-if="editable && !formOpen" class="grid gap-2">
            <p class="text-xs font-medium text-muted-foreground">
                Ejemplos para empezar
            </p>
            <div class="grid gap-2 sm:flex sm:flex-wrap">
                <Button
                    v-for="example in examples"
                    :key="example.name"
                    variant="secondary"
                    size="sm"
                    class="h-auto min-h-8 max-w-full min-w-0 shrink justify-start py-1.5 text-left whitespace-normal pointer-coarse:min-h-10"
                    @click="startRule(example)"
                >
                    <Wand2 aria-hidden="true" />
                    <span class="min-w-0 break-words">{{ example.label }}</span>
                </Button>
            </div>
        </div>

        <form
            v-if="formOpen"
            class="grid gap-4 rounded-lg border p-3 sm:p-4"
            aria-labelledby="rule-form-title"
            @submit.prevent="apply"
        >
            <h3 id="rule-form-title" class="text-sm font-semibold">
                Nueva regla
            </h3>
            <label for="rule-name" class="grid gap-1.5 text-sm font-medium">
                Nombre de la regla
                <Input
                    id="rule-name"
                    v-model="name"
                    maxlength="120"
                    autocomplete="off"
                    :aria-invalid="Boolean(nameError) || undefined"
                />
                <InputError :message="nameError ?? undefined" />
            </label>

            <fieldset class="grid gap-2">
                <legend class="mb-1 text-sm font-medium">Destino</legend>
                <div class="grid gap-2 sm:grid-cols-3">
                    <label
                        v-for="option in DECISION_ORDER"
                        :key="option"
                        :class="
                            cn(
                                'flex cursor-pointer items-start gap-2 rounded-lg border p-3 text-sm focus-within:ring-2 focus-within:ring-ring',
                                decision === option
                                    ? 'border-primary bg-primary-soft/60'
                                    : 'hover:bg-muted/40',
                            )
                        "
                    >
                        <input
                            v-model="decision"
                            type="radio"
                            name="rule-decision"
                            :value="option"
                            class="mt-0.5 accent-primary"
                        />
                        <span>
                            <span class="block font-medium">
                                {{ DECISIONS[option].label }}
                            </span>
                            <span class="block text-xs text-muted-foreground">
                                {{ DECISIONS[option].summary }}
                            </span>
                        </span>
                    </label>
                </div>
            </fieldset>

            <div class="grid gap-2">
                <p class="text-sm font-medium">Conversaciones que abarca</p>
                <HistoryFilterFields
                    v-model="criteria"
                    :facets="facets"
                    id-prefix="rule"
                    :with-decision="false"
                />
                <p class="text-xs text-muted-foreground">
                    {{
                        criteriaText.length > 0
                            ? criteriaText.join(' · ')
                            : 'Sin criterios: abarca todas las conversaciones del lote.'
                    }}
                </p>
            </div>

            <label class="flex cursor-pointer items-start gap-3 text-sm">
                <Checkbox v-model="overwrite" class="mt-0.5" />
                <span>
                    <span class="block font-medium">
                        Cambiar también las que ya tienen destino
                    </span>
                    <span class="block text-xs text-muted-foreground">
                        Si no lo marcas, la regla solo decide las conversaciones
                        que siguen sin decidir.
                    </span>
                </span>
            </label>

            <StatusNote
                v-if="preview"
                tone="info"
                live="polite"
                title="Vista previa"
            >
                Coinciden
                {{ plural(preview.matched, 'conversación', 'conversaciones') }};
                cambiarían {{ formatNumber(preview.would_change) }}.
                <template v-if="preview.already_decided > 0">
                    {{ formatNumber(preview.already_decided) }} ya tienen otro
                    destino y no se tocan.
                </template>
                <template v-if="preview.not_allowed > 0">
                    {{ formatNumber(preview.not_allowed) }} no admiten este
                    destino (por ejemplo, vacías o grupos) y se quedan como
                    están.
                </template>
            </StatusNote>
            <InputError :message="formError ?? undefined" />

            <div class="flex flex-wrap justify-end gap-2">
                <Button
                    type="button"
                    variant="ghost"
                    :disabled="applying"
                    @click="formOpen = false"
                >
                    Cancelar
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="previewing || applying"
                    @click="runPreview"
                >
                    <Spinner v-if="previewing" />
                    <Eye v-else />
                    Ver cuántas cambian
                </Button>
                <Button type="submit" :disabled="applying || preview === null">
                    <Spinner v-if="applying" />
                    Aplicar regla
                </Button>
            </div>
            <p
                v-if="preview === null"
                class="text-right text-xs text-muted-foreground"
            >
                Revisa la vista previa antes de aplicar.
            </p>
        </form>

        <p
            v-if="rules.length === 0 && !formOpen"
            class="text-sm text-muted-foreground"
        >
            Todavía no hay reglas en este lote.
        </p>
        <ul
            v-else-if="rules.length > 0"
            class="grid gap-2"
            aria-label="Reglas aplicadas"
        >
            <li
                v-for="rule in rules"
                :key="rule.id"
                class="flex flex-col gap-2 rounded-lg border p-3 sm:flex-row sm:items-start sm:justify-between"
            >
                <div class="min-w-0 space-y-1 text-sm">
                    <p class="flex flex-wrap items-center gap-2 font-medium">
                        <span class="wrap-anywhere">{{ rule.name }}</span>
                        <Badge
                            :variant="
                                DECISIONS[rule.decision]?.badge ?? 'muted'
                            "
                        >
                            {{ rule.decision_label }}
                        </Badge>
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{
                            describeFilters(rule.criteria).join(' · ') ||
                            'Todas las conversaciones del lote'
                        }}
                    </p>
                    <p class="text-xs text-muted-foreground tabular-nums">
                        {{ plural(rule.matched, 'coincidió', 'coincidieron') }}
                        · {{ formatNumber(rule.applied) }} cambiadas
                        <template v-if="rule.applied_at">
                            · {{ formatDateTime(rule.applied_at) }}
                        </template>
                        <template v-if="rule.created_by">
                            · {{ rule.created_by }}
                        </template>
                    </p>
                </div>
                <Button
                    v-if="editable"
                    variant="ghost"
                    size="sm"
                    class="self-end sm:self-start pointer-coarse:h-10"
                    :aria-label="`Quitar la regla ${rule.name}`"
                    @click="askRemove(rule)"
                >
                    <Trash2 />
                    Quitar
                </Button>
            </li>
        </ul>

        <ConfirmDialog
            v-model:open="removeOpen"
            :title="`¿Quitar la regla «${removing?.name ?? ''}»?`"
            description="Las conversaciones que decidió volverán a «Sin decidir». Las que cambiaste a mano después no se tocan."
            confirm-label="Quitar regla"
            destructive
            :processing="removeProcessing"
            @confirm="remove"
        />
    </SurfaceCard>
</template>
