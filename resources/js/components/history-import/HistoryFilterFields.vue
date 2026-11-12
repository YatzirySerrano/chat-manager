<script setup lang="ts">
import { computed } from 'vue';
import { Checkbox } from '@/components/ui/checkbox';
import { DatePicker, DateRangePicker } from '@/components/ui/date-picker';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import type { HistoryFacets, HistoryFilters } from '@/types/history-import';
import {
    DECISION_ORDER,
    DECISIONS,
    FLAG_LABELS,
    UNDECIDED_FACET,
} from './labels';

/**
 * The inventory filters as fields, shared by the inventory list and the
 * criteria of a rule (they are the same filters on the server). Facet
 * selects only offer the values found in the uploaded files, plus «Sin
 * dato» for conversations without that value.
 */
const props = withDefaults(
    defineProps<{
        facets: HistoryFacets | null;
        /** Prefix of the field ids (two instances may be on screen). */
        idPrefix: string;
        /** Hide the text search (rules use it rarely). */
        withSearch?: boolean;
        /** Offer the destination filter (inventory only). */
        withDecision?: boolean;
        class?: string;
    }>(),
    { withSearch: true, withDecision: true, class: undefined },
);

const filters = defineModel<HistoryFilters>({ required: true });

const ALL = '__all';

type FacetKey = 'agent' | 'department' | 'connection' | 'status' | 'tag';

const facetFields = computed<
    {
        key: FacetKey;
        label: string;
        all: string;
        values: string[];
        blank: boolean;
    }[]
>(() => [
    {
        key: 'agent',
        label: 'Agente en Whaticket',
        all: 'Todos los agentes',
        values: props.facets?.agents ?? [],
        blank: true,
    },
    {
        key: 'department',
        label: 'Departamento',
        all: 'Todos los departamentos',
        values: props.facets?.departments ?? [],
        blank: true,
    },
    {
        key: 'connection',
        label: 'Conexión',
        all: 'Todas las conexiones',
        values: props.facets?.connections ?? [],
        blank: true,
    },
    {
        key: 'status',
        label: 'Estado en Whaticket',
        all: 'Todos los estados',
        values: props.facets?.statuses ?? [],
        blank: false,
    },
    {
        key: 'tag',
        label: 'Etiqueta',
        all: 'Todas las etiquetas',
        values: props.facets?.tags ?? [],
        blank: false,
    },
]);

function setText(key: keyof HistoryFilters, value: string | number): void {
    const text = String(value);
    const next = { ...filters.value } as Record<string, unknown>;

    if (text === '' || text === ALL) {
        delete next[key];
    } else {
        next[key] = text;
    }

    filters.value = next as HistoryFilters;
}

/** Both ends of the activity range at once (one update of the model). */
function setRange(range: { from: string | null; to: string | null }): void {
    const next = { ...filters.value } as Record<string, unknown>;

    for (const [key, value] of [
        ['date_from', range.from],
        ['date_to', range.to],
    ] as const) {
        if (value) {
            next[key] = value;
        } else {
            delete next[key];
        }
    }

    filters.value = next as HistoryFilters;
}

function setFlag(key: string, checked: boolean | 'indeterminate'): void {
    const next = { ...filters.value } as Record<string, unknown>;

    if (checked === true) {
        next[key] = true;
    } else {
        delete next[key];
    }

    filters.value = next as HistoryFilters;
}

function textValue(key: keyof HistoryFilters): string {
    const value = filters.value[key];

    return typeof value === 'string' && value !== '' ? value : ALL;
}

function inputValue(key: keyof HistoryFilters): string {
    const value = filters.value[key];

    return typeof value === 'string' ? value : '';
}
</script>

<template>
    <div :class="cn('@container grid gap-3', props.class)">
        <div
            class="grid grid-cols-1 gap-3 @lg:grid-cols-2 @3xl:grid-cols-3 @5xl:grid-cols-4"
        >
            <label
                v-if="withSearch"
                :for="`${idPrefix}-search`"
                class="grid gap-1.5 text-sm font-medium @lg:col-span-2"
            >
                Buscar contacto
                <Input
                    :id="`${idPrefix}-search`"
                    type="search"
                    :model-value="inputValue('search')"
                    placeholder="Nombre, número o número de ticket"
                    autocomplete="off"
                    @update:model-value="setText('search', $event)"
                />
            </label>
            <div v-if="withDecision" class="grid gap-1.5">
                <span
                    :id="`${idPrefix}-decision-label`"
                    class="text-sm font-medium"
                >
                    Destino elegido
                </span>
                <Select
                    :model-value="textValue('decision')"
                    @update:model-value="setText('decision', String($event))"
                >
                    <SelectTrigger
                        :aria-labelledby="`${idPrefix}-decision-label`"
                        class="w-full"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="ALL">Cualquier destino</SelectItem>
                        <SelectItem value="undecided">Sin decidir</SelectItem>
                        <SelectItem
                            v-for="decision in DECISION_ORDER"
                            :key="decision"
                            :value="decision"
                        >
                            {{ DECISIONS[decision].label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>
            <fieldset class="grid gap-1.5 @lg:col-span-2">
                <legend class="text-sm font-medium">
                    Última actividad entre
                </legend>
                <DateRangePicker
                    :id="`${idPrefix}-from`"
                    :from="inputValue('date_from')"
                    :to="inputValue('date_to')"
                    placeholder="Cualquier fecha"
                    aria-label="Última actividad entre"
                    :aria-describedby="`${idPrefix}-dates-hint`"
                    @change="setRange"
                />
                <p
                    :id="`${idPrefix}-dates-hint`"
                    class="text-xs text-muted-foreground"
                >
                    La última actividad es la fecha del último mensaje de cada
                    conversación (ambos días incluidos).
                </p>
            </fieldset>
            <label
                :for="`${idPrefix}-inactive`"
                class="grid content-start gap-1.5 text-sm font-medium"
            >
                Sin actividad a partir del
                <DatePicker
                    :id="`${idPrefix}-inactive`"
                    placeholder="Cualquier fecha"
                    :aria-describedby="`${idPrefix}-inactive-hint`"
                    :model-value="inputValue('inactive_since')"
                    @update:model-value="
                        setText('inactive_since', $event ?? '')
                    "
                />
                <span
                    :id="`${idPrefix}-inactive-hint`"
                    class="text-xs font-normal text-muted-foreground"
                >
                    Su último mensaje es anterior a ese día. También incluye las
                    conversaciones sin mensajes.
                </span>
            </label>
            <div
                v-for="field in facetFields"
                :key="field.key"
                class="grid gap-1.5"
            >
                <span
                    :id="`${idPrefix}-${field.key}-label`"
                    class="text-sm font-medium"
                >
                    {{ field.label }}
                </span>
                <Select
                    :model-value="textValue(field.key)"
                    @update:model-value="setText(field.key, String($event))"
                >
                    <SelectTrigger
                        :aria-labelledby="`${idPrefix}-${field.key}-label`"
                        class="w-full"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="ALL">{{ field.all }}</SelectItem>
                        <SelectItem v-if="field.blank" :value="UNDECIDED_FACET">
                            Sin dato
                        </SelectItem>
                        <SelectItem
                            v-for="value in field.values"
                            :key="value"
                            :value="value"
                        >
                            {{ value }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>
        </div>
        <fieldset class="flex flex-wrap gap-2">
            <legend class="sr-only">Mostrar solo</legend>
            <label
                v-for="(label, key) in FLAG_LABELS"
                :key="key"
                class="flex min-h-9 cursor-pointer items-center gap-2 rounded-full border px-3 py-1 text-sm has-[[data-state=checked]]:border-primary/50 has-[[data-state=checked]]:bg-primary-soft has-[[data-state=checked]]:text-primary-soft-foreground pointer-coarse:min-h-11"
            >
                <Checkbox
                    :model-value="filters[key as keyof HistoryFilters] === true"
                    @update:model-value="setFlag(String(key), $event)"
                />
                {{ label }}
            </label>
        </fieldset>
    </div>
</template>
