<script setup lang="ts">
import { reactive, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type {
    AnalyticsOptions,
    AnalyticsPeriod,
    AnalyticsPreset,
    AnalyticsQuery,
} from '@/types';

/**
 * Period and dimension filters shared by the reports page and the
 * dashboard. It only emits the new query; the page navigates, so filters
 * stay in the URL. Options come from the server already limited to the
 * viewer's reach (an empty list hides the filter).
 */
const props = defineProps<{
    query: AnalyticsQuery;
    period: AnalyticsPeriod;
    presets: { value: AnalyticsPreset; label: string }[];
    options: AnalyticsOptions;
    maxDays?: number;
}>();

const emit = defineEmits<{ change: [query: AnalyticsQuery] }>();

const state = reactive({
    period: props.period.preset,
    from: props.period.from,
    to: props.period.to,
    department: props.query.department ?? '',
    agent: props.query.agent ?? '',
    connection: props.query.connection ?? '',
});

watch(
    () => [props.period, props.query] as const,
    ([period, query]) => {
        state.period = period.preset;
        state.from = period.from;
        state.to = period.to;
        state.department = query.department ?? '';
        state.agent = query.agent ?? '';
        state.connection = query.connection ?? '';
    },
);

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

function build(): AnalyticsQuery {
    const query: AnalyticsQuery = {};

    if (state.period !== '30d') {
        query.period = state.period;
    }

    if (state.period === 'custom') {
        query.from = state.from;
        query.to = state.to;
    }

    if (state.department !== '') {
        query.department = Number(state.department);
    }

    if (state.agent !== '') {
        query.agent = Number(state.agent);
    }

    if (state.connection !== '') {
        query.connection = Number(state.connection);
    }

    return query;
}

function apply(): void {
    emit('change', build());
}

function changePeriod(): void {
    if (state.period !== 'custom') {
        apply();
    }
}

const rangeInvalid = () =>
    state.from === '' || state.to === '' || state.to < state.from;
</script>

<template>
    <div class="flex flex-col gap-3 rounded-xl border bg-card p-4 shadow-card">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <label class="space-y-1 text-xs font-medium text-muted-foreground">
                <span>Periodo</span>
                <select
                    v-model="state.period"
                    :class="selectClass"
                    class="text-foreground"
                    @change="changePeriod"
                >
                    <option
                        v-for="preset in presets"
                        :key="preset.value"
                        :value="preset.value"
                    >
                        {{ preset.label }}
                    </option>
                </select>
            </label>
            <label
                v-if="options.departments.length > 0"
                class="space-y-1 text-xs font-medium text-muted-foreground"
            >
                <span>Departamento</span>
                <select
                    v-model="state.department"
                    :class="selectClass"
                    class="text-foreground"
                    @change="apply"
                >
                    <option value="">Todos los de tu alcance</option>
                    <option
                        v-for="department in options.departments"
                        :key="department.id"
                        :value="department.id"
                    >
                        {{ department.name }}
                    </option>
                </select>
            </label>
            <label
                v-if="options.agents.length > 0"
                class="space-y-1 text-xs font-medium text-muted-foreground"
            >
                <span>Agente</span>
                <select
                    v-model="state.agent"
                    :class="selectClass"
                    class="text-foreground"
                    @change="apply"
                >
                    <option value="">Todos</option>
                    <option
                        v-for="agent in options.agents"
                        :key="agent.id"
                        :value="agent.id"
                    >
                        {{ agent.name }}
                    </option>
                </select>
            </label>
            <label
                v-if="options.connections.length > 1"
                class="space-y-1 text-xs font-medium text-muted-foreground"
            >
                <span>Conexión</span>
                <select
                    v-model="state.connection"
                    :class="selectClass"
                    class="text-foreground"
                    @change="apply"
                >
                    <option value="">Todas</option>
                    <option
                        v-for="connection in options.connections"
                        :key="connection.id"
                        :value="connection.id"
                    >
                        {{ connection.name }}
                    </option>
                </select>
            </label>
        </div>

        <form
            v-if="state.period === 'custom'"
            class="flex flex-col gap-3 sm:flex-row sm:items-end"
            @submit.prevent="apply"
        >
            <label class="space-y-1 text-xs font-medium text-muted-foreground">
                <span>Desde</span>
                <Input
                    v-model="state.from"
                    type="date"
                    :max="state.to"
                    required
                />
            </label>
            <label class="space-y-1 text-xs font-medium text-muted-foreground">
                <span>Hasta</span>
                <Input
                    v-model="state.to"
                    type="date"
                    :min="state.from"
                    required
                />
            </label>
            <Button type="submit" :disabled="rangeInvalid()"
                >Aplicar rango</Button
            >
            <p v-if="maxDays" class="text-xs text-muted-foreground sm:pb-2">
                Hasta {{ maxDays }} días.
            </p>
        </form>

        <p class="text-xs text-muted-foreground">
            {{ period.label }} · comparado con {{ period.previous.label }} ·
            hora de {{ period.timezone }}
        </p>
    </div>
</template>
