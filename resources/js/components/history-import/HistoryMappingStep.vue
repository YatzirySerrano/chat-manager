<script setup lang="ts">
import { Plug, Save } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import EmptyState from '@/components/EmptyState.vue';
import InputError from '@/components/InputError.vue';
import StatusNote from '@/components/StatusNote.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { mappings as mappingsRoute } from '@/routes/admin/history-import';
import type {
    HistoryMappingRows,
    HistoryOptions,
    HistorySummary,
} from '@/types/history-import';
import { errorMessage, historyRequest, HistoryRequestError } from './api';
import { plural } from './labels';

/**
 * Step 2: where each Whaticket connection, department, agent and tag goes
 * in Chat Manager. Connections are required for everything imported or
 * archived: an existing connection or «Conexión histórica sin envío», a
 * placeholder that keeps the history but can never send. Departments,
 * agents and tags are optional (without a match the value is kept only as
 * a Whaticket detail).
 */
const props = defineProps<{
    batchId: number;
    mappings: HistoryMappingRows;
    options: HistoryOptions;
    editable: boolean;
}>();

const emit = defineEmits<{
    saved: [mappings: HistoryMappingRows, summary: HistorySummary];
}>();

/** True while there are changes not saved yet (the parent warns before leaving). */
const dirtyModel = defineModel<boolean>('dirty', { default: false });

const NONE = '__none';
const PLACEHOLDER = 'placeholder';

type Draft = {
    connections: Record<string, string>;
    departments: Record<string, string>;
    agents: Record<string, string>;
    tags: Record<string, string>;
};

function draftFrom(rows: HistoryMappingRows): Draft {
    return {
        connections: Object.fromEntries(
            rows.connections.map((row) => [
                row.source,
                row.target === 'placeholder'
                    ? PLACEHOLDER
                    : row.target === 'connection' && row.connection_id !== null
                      ? String(row.connection_id)
                      : NONE,
            ]),
        ),
        departments: Object.fromEntries(
            rows.departments.map((row) => [
                row.source,
                row.target_id === null ? NONE : String(row.target_id),
            ]),
        ),
        agents: Object.fromEntries(
            rows.agents.map((row) => [
                row.source,
                row.target_id === null ? NONE : String(row.target_id),
            ]),
        ),
        tags: Object.fromEntries(
            rows.tags.map((row) => [
                row.source,
                row.target_id === null ? NONE : String(row.target_id),
            ]),
        ),
    };
}

const draft = ref<Draft>(draftFrom(props.mappings));
const saving = ref(false);
const errors = ref<Record<string, string>>({});
const generalError = ref<string | null>(null);

/* A reload of the mappings (progress polling) never wipes unsaved changes. */
watch(
    () => props.mappings,
    (rows, previous) => {
        if (
            JSON.stringify(draft.value) === JSON.stringify(draftFrom(previous))
        ) {
            draft.value = draftFrom(rows);
        }
    },
);

const dirty = computed(
    () =>
        JSON.stringify(draft.value) !==
        JSON.stringify(draftFrom(props.mappings)),
);

watch(dirty, (value) => (dirtyModel.value = value), { immediate: true });

const unmappedConnections = computed(
    () =>
        props.mappings.connections.filter(
            (row) => draft.value.connections[row.source] === NONE,
        ).length,
);

function sourceLabel(source: string): string {
    return source === '' ? 'Sin conexión indicada' : source;
}

function idOrNull(value: string | undefined): number | null {
    return value === undefined || value === NONE ? null : Number(value);
}

async function save(): Promise<void> {
    saving.value = true;
    errors.value = {};
    generalError.value = null;

    const connections: Record<
        string,
        | { target: 'connection'; connection_id: number }
        | { target: 'placeholder' }
        | null
    > = {};

    for (const [source, value] of Object.entries(draft.value.connections)) {
        connections[source] =
            value === PLACEHOLDER
                ? { target: 'placeholder' }
                : value === NONE
                  ? null
                  : { target: 'connection', connection_id: Number(value) };
    }

    const plain = (values: Record<string, string>) =>
        Object.fromEntries(
            Object.entries(values).map(([source, value]) => [
                source,
                idOrNull(value),
            ]),
        );

    try {
        const response = await historyRequest<{
            mappings: HistoryMappingRows;
            summary: HistorySummary;
        }>('PUT', mappingsRoute.url(props.batchId), {
            connections,
            departments: plain(draft.value.departments),
            agents: plain(draft.value.agents),
            tags: plain(draft.value.tags),
        });
        draft.value = draftFrom(response.mappings);
        emit('saved', response.mappings, response.summary);
        toast.success('Correspondencias guardadas.');
    } catch (error) {
        if (error instanceof HistoryRequestError && error.status === 422) {
            errors.value = error.errors;
        }

        generalError.value = errorMessage(error);
    } finally {
        saving.value = false;
    }
}

function fieldError(group: string, source: string): string | undefined {
    return (
        errors.value[`${group}.${source}`] ??
        errors.value[`mappings.${group}.${source}`] ??
        errors.value[`${group}.${source}.connection_id`]
    );
}

const optionalGroups = computed(
    () =>
        [
            {
                key: 'departments',
                title: 'Departamentos',
                empty: 'Los archivos no indican departamentos.',
                rows: props.mappings.departments,
                choices: props.options.departments,
                none: 'Sin departamento',
            },
            {
                key: 'agents',
                title: 'Agentes',
                empty: 'Los archivos no indican agentes.',
                rows: props.mappings.agents,
                choices: props.options.agents,
                none: 'Sin agente asignado',
            },
            {
                key: 'tags',
                title: 'Etiquetas',
                empty: 'Los archivos no indican etiquetas.',
                rows: props.mappings.tags,
                choices: props.options.tags,
                none: 'No asignar etiqueta',
            },
        ] as const,
);
</script>

<template>
    <div class="grid gap-6">
        <SurfaceCard
            title="Conexiones"
            description="Obligatorio para todo lo que se importe o archive. Elige a qué conexión de Chat Manager pertenece cada línea de Whaticket."
            body-class="grid gap-4 p-4 sm:p-5"
            data-tour="history-import.connections"
        >
            <StatusNote tone="info" title="«Conexión histórica sin envío»">
                Guarda el historial bajo una conexión de solo consulta, sin
                credenciales de WhatsApp: nunca envía mensajes y no aparece como
                canal activo. Úsala si la línea de Whaticket ya no existe o no
                quieres mezclarla con una conexión actual. Si eliges una
                conexión existente, las conversaciones importadas quedan
                cerradas en ella y tampoco se envía nada.
            </StatusNote>

            <EmptyState
                v-if="mappings.connections.length === 0"
                class="border-none"
                :icon="Plug"
                title="Sin conexiones todavía"
                description="Aparecerán cuando se lean los archivos."
            />
            <ul v-else class="grid gap-3" aria-label="Conexiones de Whaticket">
                <li
                    v-for="row in mappings.connections"
                    :key="row.source"
                    class="grid gap-2 rounded-lg border p-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,18rem)] sm:items-center"
                    :data-connection-source="row.source"
                >
                    <div class="min-w-0">
                        <p
                            :id="`connection-${row.source || 'blank'}-label`"
                            class="font-medium wrap-anywhere"
                        >
                            {{ sourceLabel(row.source) }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{
                                plural(
                                    row.conversations,
                                    'conversación',
                                    'conversaciones',
                                )
                            }}
                        </p>
                    </div>
                    <div class="grid gap-1">
                        <Select
                            v-model="draft.connections[row.source]"
                            :disabled="!editable"
                        >
                            <SelectTrigger
                                :aria-labelledby="`connection-${row.source || 'blank'}-label`"
                                class="w-full"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="NONE"
                                    >Sin elegir</SelectItem
                                >
                                <SelectItem :value="PLACEHOLDER">
                                    Conexión histórica sin envío
                                </SelectItem>
                                <SelectItem
                                    v-for="connection in options.connections"
                                    :key="connection.id"
                                    :value="String(connection.id)"
                                >
                                    {{ connection.name
                                    }}{{
                                        connection.active ? '' : ' (inactiva)'
                                    }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError
                            :message="fieldError('connections', row.source)"
                        />
                    </div>
                </li>
            </ul>
            <p
                v-if="editable && unmappedConnections > 0"
                class="text-sm text-warning"
                role="status"
            >
                Falta elegir
                {{ plural(unmappedConnections, 'conexión', 'conexiones') }}. Las
                conversaciones de esas conexiones no se pueden importar ni
                archivar hasta que lo hagas.
            </p>
        </SurfaceCard>

        <SurfaceCard
            title="Departamentos, agentes y etiquetas"
            description="Opcional. Si no eliges nada, el dato se conserva solo como referencia de Whaticket en la conversación."
            body-class="grid gap-6 p-4 sm:p-5 lg:grid-cols-3"
        >
            <section
                v-for="group in optionalGroups"
                :key="group.key"
                class="grid content-start gap-3"
                :aria-labelledby="`mapping-${group.key}`"
            >
                <h3 :id="`mapping-${group.key}`" class="text-sm font-semibold">
                    {{ group.title }}
                </h3>
                <p
                    v-if="group.rows.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    {{ group.empty }}
                </p>
                <div
                    v-for="row in group.rows"
                    :key="row.source"
                    class="grid gap-1"
                >
                    <span
                        :id="`mapping-${group.key}-${row.source}`"
                        class="text-sm wrap-anywhere"
                    >
                        {{ row.source }}
                        <span class="text-xs text-muted-foreground">
                            ·
                            {{
                                plural(
                                    row.conversations,
                                    'conversación',
                                    'conversaciones',
                                )
                            }}
                        </span>
                    </span>
                    <Select
                        v-model="draft[group.key][row.source]"
                        :disabled="!editable"
                    >
                        <SelectTrigger
                            :aria-labelledby="`mapping-${group.key}-${row.source}`"
                            class="w-full"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="NONE">{{
                                group.none
                            }}</SelectItem>
                            <SelectItem
                                v-for="choice in group.choices"
                                :key="choice.id"
                                :value="String(choice.id)"
                            >
                                {{ choice.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="fieldError(group.key, row.source)" />
                </div>
            </section>
        </SurfaceCard>

        <div
            v-if="editable"
            class="flex flex-col items-stretch gap-2 sm:flex-row sm:items-center sm:justify-end"
        >
            <InputError :message="generalError ?? undefined" />
            <Button
                class="pointer-coarse:h-11"
                :disabled="saving || !dirty"
                data-tour="history-import.save-mappings"
                @click="save"
            >
                <Spinner v-if="saving" />
                <Save v-else />
                Guardar correspondencias
            </Button>
        </div>
    </div>
</template>
