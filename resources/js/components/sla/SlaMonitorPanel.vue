<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    CircleAlert,
    CircleCheck,
    CircleSlash,
    ExternalLink,
    Timer,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import DepartmentTag from '@/components/DepartmentTag.vue';
import EmptyState from '@/components/EmptyState.vue';
import SlaBadge from '@/components/sla/SlaBadge.vue';
import StatCard from '@/components/StatCard.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useServerNow } from '@/composables/useServerNow';
import { formatDateTime } from '@/lib/format';
import {
    formatSlaSpan,
    formatSlaTarget,
    slaSecondsElapsed,
    slaSecondsLeft,
} from '@/lib/sla';
import { index as attentionIndex } from '@/routes/attention';
import type {
    SlaBreachHistory,
    SlaBreachRow,
    SlaMonitorData,
    SlaMonitorFilters,
    SlaMonitorOptions,
    SlaMonitorRow,
} from '@/types';

/**
 * «Monitoreo de SLA» of «Seguimiento de atención»: the customers waiting
 * for an answer against their response objective (most urgent first) and
 * the history of breaches, both within the viewer's reach.
 *
 * Counters and rows come from the server; elapsed and remaining times move
 * by themselves with the shared server clock. Filters live in the URL
 * (validated by the server) and reload only the SLA props. `data` and
 * `history` are deferred props: undefined while they load.
 */
const props = defineProps<{
    data?: SlaMonitorData;
    history?: SlaBreachHistory;
    filters: SlaMonitorFilters;
    options: SlaMonitorOptions;
    /** Link to the service objectives, only for who can edit settings. */
    settingsUrl: string | null;
}>();

const SLA_PROPS = ['sla', 'slaBreaches', 'slaFilters', 'serverTime', 'tab'];

const now = useServerNow();
const loading = ref(false);

/** Query string of the current filters with some values replaced. */
function query(
    changes: Partial<Record<keyof SlaMonitorFilters, string | number | null>>,
): Record<string, string | number> {
    const next = { ...props.filters, ...changes };
    const entries: [string, string | number | null][] = [
        ['tab', 'sla'],
        ['sla_state', next.state],
        ['sla_department', next.department],
        ['sla_category', next.category],
        ['sla_agent', next.agent],
        ['sla_connection', next.connection],
        ['sla_page', Number(next.page) > 1 ? next.page : null],
        ['sla_from', next.from],
        ['sla_to', next.to],
        [
            'sla_history_page',
            Number(next.history_page) > 1 ? next.history_page : null,
        ],
    ];

    return Object.fromEntries(
        entries.filter(
            (entry): entry is [string, string | number] =>
                entry[1] !== null && entry[1] !== '',
        ),
    );
}

function visit(
    changes: Partial<Record<keyof SlaMonitorFilters, string | number | null>>,
): void {
    router.get(attentionIndex.url(), query(changes), {
        only: SLA_PROPS,
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => (loading.value = true),
        onFinish: () => (loading.value = false),
    });
}

/** A dimension filter changed: both lists go back to their first page. */
function filterBy(
    key: 'state' | 'department' | 'category' | 'agent' | 'connection',
    value: unknown,
): void {
    const chosen = value === 'all' || value == null ? null : String(value);

    visit({
        [key]: chosen === null || key === 'state' ? chosen : Number(chosen),
        page: 1,
        history_page: 1,
    });
}

const hasFilters = computed(
    () =>
        props.filters.state !== null ||
        props.filters.department !== null ||
        props.filters.category !== null ||
        props.filters.agent !== null ||
        props.filters.connection !== null,
);

function clearFilters(): void {
    visit({
        state: null,
        department: null,
        category: null,
        agent: null,
        connection: null,
        page: 1,
        history_page: 1,
    });
}

const selects = computed(() => [
    {
        key: 'state' as const,
        label: 'Estado del SLA',
        all: 'Todos los estados',
        value: props.filters.state,
        items: props.options.states.map((state) => ({
            value: state.value as string,
            label: state.label,
        })),
    },
    {
        key: 'department' as const,
        label: 'Departamento',
        all: 'Todos los departamentos',
        value: props.filters.department,
        items: props.options.departments.map((item) => ({
            value: String(item.id),
            label: item.name,
        })),
    },
    {
        key: 'category' as const,
        label: 'Categoría',
        all: 'Todas las categorías',
        value: props.filters.category,
        items: props.options.categories.map((item) => ({
            value: String(item.id),
            label: item.name,
        })),
    },
    {
        key: 'agent' as const,
        label: 'Agente responsable',
        all: 'Todos los agentes',
        value: props.filters.agent,
        items: props.options.agents.map((item) => ({
            value: String(item.id),
            label: item.name,
        })),
    },
    {
        key: 'connection' as const,
        label: 'Conexión',
        all: 'Todas las conexiones',
        value: props.filters.connection,
        items: props.options.connections.map((item) => ({
            value: String(item.id),
            label: item.name,
        })),
    },
]);

/* Breach history range. */
const from = ref(props.filters.from);
const to = ref(props.filters.to);

function applyRange(): void {
    visit({ from: from.value, to: to.value, history_page: 1 });
}

function elapsed(row: SlaMonitorRow): string {
    return formatSlaSpan(slaSecondsElapsed(row, now.value));
}

function remaining(row: SlaMonitorRow): { text: string; overdue: boolean } {
    const left = row.sla === null ? null : slaSecondsLeft(row.sla, now.value);

    if (left === null) {
        return { text: 'No se mide', overdue: false };
    }

    return left >= 0
        ? { text: `Quedan ${formatSlaSpan(left)}`, overdue: false }
        : { text: `Vencida hace ${formatSlaSpan(-left)}`, overdue: true };
}

function objective(row: SlaBreachRow): string {
    return row.target_seconds === null
        ? row.kind_label
        : `${row.kind_label} en ${formatSlaTarget(row.target_seconds)}`;
}

function aftermath(row: SlaBreachRow): string {
    if (row.outcome === 'answered') {
        return `Respondió ${row.responder ?? 'el equipo'} el ${formatDateTime(row.responded_at)}`;
    }

    if (row.outcome === 'closed') {
        return `Se cerró sin respuesta el ${formatDateTime(row.ended_at)}`;
    }

    return 'Sigue sin respuesta';
}
</script>

<template>
    <div class="grid gap-4" data-sla-monitor>
        <div
            class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4"
            :aria-busy="data === undefined"
        >
            <StatCard
                label="En tiempo"
                :value="data?.summary.on_time ?? 0"
                :icon="CircleCheck"
                :loading="data === undefined"
                hint="Dentro de su plazo de respuesta"
            />
            <StatCard
                label="Por vencer"
                :value="data?.summary.warning ?? 0"
                :icon="Timer"
                :loading="data === undefined"
                hint="Ya alcanzaron el aviso de «por vencer»"
            />
            <StatCard
                label="Vencidas"
                :value="data?.summary.breached ?? 0"
                :icon="CircleAlert"
                :loading="data === undefined"
                hint="Superaron el plazo y siguen sin respuesta"
            />
            <StatCard
                label="Sin objetivo configurado"
                :value="data?.summary.unmeasured ?? 0"
                :icon="CircleSlash"
                :loading="data === undefined"
                hint="Esperan respuesta, pero ningún objetivo aplicaba"
            />
        </div>

        <SurfaceCard
            title="Solicitudes esperando respuesta"
            description="Cada fila es un cliente que escribió y aún no recibe respuesta de una persona. Primero las más urgentes. El plazo se fija cuando el cliente escribe y no cambia aunque se reasigne la conversación o cambien las reglas."
        >
            <div class="grid gap-3 px-5 pb-5">
                <div
                    class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5"
                    role="group"
                    aria-label="Filtros del monitoreo de SLA"
                >
                    <div
                        v-for="select in selects"
                        :key="select.key"
                        class="grid min-w-0 gap-1"
                    >
                        <Label
                            :for="`sla-filter-${select.key}`"
                            class="text-xs text-muted-foreground"
                            >{{ select.label }}</Label
                        >
                        <Select
                            :model-value="
                                select.value === null
                                    ? 'all'
                                    : String(select.value)
                            "
                            @update:model-value="
                                (value) => filterBy(select.key, value)
                            "
                        >
                            <SelectTrigger
                                :id="`sla-filter-${select.key}`"
                                class="w-full"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">{{
                                    select.all
                                }}</SelectItem>
                                <SelectItem
                                    v-for="item in select.items"
                                    :key="item.value"
                                    :value="item.value"
                                    >{{ item.label }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                <div v-if="hasFilters">
                    <Button variant="ghost" size="sm" @click="clearFilters">
                        <X aria-hidden="true" />
                        Quitar filtros
                    </Button>
                </div>

                <div
                    v-if="data === undefined"
                    class="grid gap-2"
                    aria-busy="true"
                    aria-label="Cargando solicitudes"
                >
                    <Skeleton v-for="index in 5" :key="index" class="h-10" />
                </div>

                <EmptyState
                    v-else-if="data.rows.length === 0 && hasFilters"
                    tone="filtered"
                    title="Ninguna solicitud coincide con los filtros"
                    description="Prueba con otro estado, departamento, categoría, agente o conexión."
                >
                    <Button variant="outline" size="sm" @click="clearFilters"
                        >Quitar filtros</Button
                    >
                </EmptyState>

                <EmptyState
                    v-else-if="data.rows.length === 0 && !data.configured"
                    :icon="CircleSlash"
                    title="No hay objetivos de respuesta configurados"
                    description="Defínelos en Configuración → Objetivos de servicio. Mientras tanto, los tiempos de respuesta se registran, pero no se comparan contra un plazo."
                >
                    <Button
                        v-if="settingsUrl"
                        as-child
                        variant="outline"
                        size="sm"
                    >
                        <Link :href="settingsUrl"
                            >Ir a Objetivos de servicio</Link
                        >
                    </Button>
                </EmptyState>

                <EmptyState
                    v-else-if="data.rows.length === 0"
                    :icon="CircleCheck"
                    title="Ningún cliente espera respuesta"
                    description="Aquí aparecerá cada solicitud en cuanto un cliente escriba y hasta que una persona le responda."
                />

                <template v-else>
                    <p
                        v-if="!data.configured"
                        class="rounded-lg border border-dashed px-3 py-2 text-xs text-muted-foreground"
                    >
                        No hay objetivos de respuesta configurados: estas
                        solicitudes no se comparan contra un plazo.
                        <Link
                            v-if="settingsUrl"
                            :href="settingsUrl"
                            class="font-medium text-foreground underline underline-offset-2"
                            >Defínelos en Configuración → Objetivos de
                            servicio</Link
                        >
                    </p>

                    <div
                        class="overflow-x-auto rounded-lg border transition-opacity"
                        :class="loading && 'opacity-60'"
                        :aria-busy="loading"
                    >
                        <Table data-sla-open-table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Contacto</TableHead>
                                    <TableHead>SLA</TableHead>
                                    <TableHead
                                        >Tiempo restante o vencido</TableHead
                                    >
                                    <TableHead>Tiempo transcurrido</TableHead>
                                    <TableHead>Responsable actual</TableHead>
                                    <TableHead>Departamento</TableHead>
                                    <TableHead>Categoría</TableHead>
                                    <TableHead>Conexión</TableHead>
                                    <TableHead
                                        >Estado de la solicitud</TableHead
                                    >
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow
                                    v-for="row in data.rows"
                                    :key="row.id"
                                    data-sla-row
                                >
                                    <TableCell class="font-medium">
                                        <Link
                                            :href="row.url"
                                            class="inline-flex items-center gap-1 underline-offset-2 hover:underline focus-visible:underline"
                                            :aria-label="`Abrir la conversación con ${row.contact}`"
                                        >
                                            {{ row.contact }}
                                            <ExternalLink
                                                class="size-3.5 shrink-0 text-muted-foreground"
                                                aria-hidden="true"
                                            />
                                        </Link>
                                        <span
                                            class="block text-xs font-normal text-muted-foreground"
                                            >{{ row.kind_label }}</span
                                        >
                                    </TableCell>
                                    <TableCell>
                                        <SlaBadge
                                            v-if="row.sla"
                                            :sla="row.sla"
                                        />
                                        <span
                                            v-else
                                            class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs whitespace-nowrap text-muted-foreground"
                                        >
                                            <CircleSlash
                                                class="size-3.5"
                                                aria-hidden="true"
                                            />
                                            Sin objetivo
                                        </span>
                                    </TableCell>
                                    <TableCell
                                        class="whitespace-nowrap tabular-nums"
                                        :class="
                                            remaining(row).overdue
                                                ? 'font-medium text-destructive'
                                                : row.sla === null
                                                  ? 'text-muted-foreground'
                                                  : ''
                                        "
                                        >{{ remaining(row).text }}</TableCell
                                    >
                                    <TableCell
                                        class="whitespace-nowrap tabular-nums"
                                    >
                                        <time
                                            :datetime="row.started_at"
                                            :title="`Desde ${formatDateTime(row.started_at)}`"
                                            >{{ elapsed(row) }}</time
                                        >
                                    </TableCell>
                                    <TableCell>
                                        {{
                                            row.agent?.name ??
                                            'En cola, sin responsable'
                                        }}
                                    </TableCell>
                                    <TableCell>
                                        <DepartmentTag
                                            v-if="row.department"
                                            :name="row.department.name"
                                            :color="row.department.color"
                                        />
                                        <span
                                            v-else
                                            class="text-muted-foreground"
                                            >Sin departamento</span
                                        >
                                    </TableCell>
                                    <TableCell>
                                        <DepartmentTag
                                            v-if="row.category"
                                            :name="row.category.name"
                                            :color="row.category.color"
                                        />
                                        <span
                                            v-else
                                            class="text-muted-foreground"
                                            >Sin categoría</span
                                        >
                                    </TableCell>
                                    <TableCell>
                                        <template v-if="row.connection">
                                            {{ row.connection.name }}
                                            <span
                                                class="block text-xs text-muted-foreground"
                                                >{{
                                                    row.connection.channel_label
                                                }}</span
                                            >
                                        </template>
                                    </TableCell>
                                    <TableCell>{{
                                        row.status_label
                                    }}</TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </div>

                    <nav
                        class="flex flex-col items-center justify-between gap-2 text-sm sm:flex-row"
                        aria-label="Paginación de solicitudes"
                    >
                        <p class="text-muted-foreground">
                            {{ data.total }}
                            {{ data.total === 1 ? 'solicitud' : 'solicitudes' }}
                            · página {{ data.page }} de {{ data.last_page }}
                        </p>
                        <div v-if="data.last_page > 1" class="flex gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="data.page <= 1 || loading"
                                @click="visit({ page: data.page - 1 })"
                                >Anterior</Button
                            >
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="
                                    data.page >= data.last_page || loading
                                "
                                @click="visit({ page: data.page + 1 })"
                                >Siguiente</Button
                            >
                        </div>
                    </nav>
                </template>
            </div>
        </SurfaceCard>

        <SurfaceCard
            title="Historial de incumplimientos"
            description="Solicitudes que superaron su plazo en el periodo elegido, con quién era responsable en ese momento y qué pasó después. Aplican los mismos filtros de departamento, categoría, agente y conexión."
        >
            <div class="grid gap-3 px-5 pb-5">
                <form
                    class="flex flex-col gap-2 sm:flex-row sm:items-end"
                    @submit.prevent="applyRange"
                >
                    <div class="grid gap-1">
                        <Label
                            for="sla-history-from"
                            class="text-xs text-muted-foreground"
                            >Desde</Label
                        >
                        <Input
                            id="sla-history-from"
                            v-model="from"
                            type="date"
                            :max="to"
                            required
                        />
                    </div>
                    <div class="grid gap-1">
                        <Label
                            for="sla-history-to"
                            class="text-xs text-muted-foreground"
                            >Hasta</Label
                        >
                        <Input
                            id="sla-history-to"
                            v-model="to"
                            type="date"
                            :min="from"
                            required
                        />
                    </div>
                    <Button
                        type="submit"
                        variant="outline"
                        size="sm"
                        :disabled="loading"
                        >Aplicar periodo</Button
                    >
                </form>

                <div
                    v-if="history === undefined"
                    class="grid gap-2"
                    aria-busy="true"
                    aria-label="Cargando historial"
                >
                    <Skeleton v-for="index in 4" :key="index" class="h-10" />
                </div>

                <EmptyState
                    v-else-if="history.rows.length === 0"
                    :icon="CircleCheck"
                    title="Sin incumplimientos en este periodo"
                    :description="`Ninguna solicitud a tu alcance superó su plazo entre el ${history.from} y el ${history.to}.`"
                />

                <template v-else>
                    <div
                        class="overflow-x-auto rounded-lg border transition-opacity"
                        :class="loading && 'opacity-60'"
                        :aria-busy="loading"
                    >
                        <Table data-sla-history-table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Solicitud</TableHead>
                                    <TableHead>Objetivo que aplicaba</TableHead>
                                    <TableHead>Inició</TableHead>
                                    <TableHead>Venció</TableHead>
                                    <TableHead>Responsable al vencer</TableHead>
                                    <TableHead>Excedió por</TableHead>
                                    <TableHead>Qué pasó después</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow
                                    v-for="row in history.rows"
                                    :key="row.id"
                                    data-sla-breach-row
                                >
                                    <TableCell class="font-medium">
                                        <Link
                                            :href="row.url"
                                            class="underline-offset-2 hover:underline focus-visible:underline"
                                            :aria-label="`Abrir la conversación con ${row.contact}`"
                                            >{{ row.contact }}</Link
                                        >
                                        <span
                                            v-if="row.department"
                                            class="block text-xs font-normal text-muted-foreground"
                                            >{{ row.department.name
                                            }}{{
                                                row.category
                                                    ? ` · ${row.category.name}`
                                                    : ''
                                            }}</span
                                        >
                                    </TableCell>
                                    <TableCell>
                                        {{ objective(row) }}
                                        <span
                                            class="block text-xs text-muted-foreground"
                                            >{{
                                                row.business_hours_only
                                                    ? 'En horario de atención'
                                                    : 'En tiempo corrido'
                                            }}</span
                                        >
                                    </TableCell>
                                    <TableCell class="whitespace-nowrap">{{
                                        formatDateTime(row.started_at)
                                    }}</TableCell>
                                    <TableCell class="whitespace-nowrap">{{
                                        formatDateTime(row.due_at)
                                    }}</TableCell>
                                    <TableCell>{{
                                        row.responsible ??
                                        'En cola, sin responsable'
                                    }}</TableCell>
                                    <TableCell
                                        class="whitespace-nowrap tabular-nums"
                                    >
                                        {{
                                            row.exceeded_seconds === null
                                                ? '—'
                                                : formatSlaSpan(
                                                      row.exceeded_seconds,
                                                  )
                                        }}
                                        <span
                                            v-if="row.outcome === 'open'"
                                            class="block text-xs text-destructive"
                                            >y sigue contando</span
                                        >
                                    </TableCell>
                                    <TableCell>{{ aftermath(row) }}</TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </div>

                    <nav
                        class="flex flex-col items-center justify-between gap-2 text-sm sm:flex-row"
                        aria-label="Paginación del historial"
                    >
                        <p class="text-muted-foreground">
                            {{ history.total }}
                            {{
                                history.total === 1
                                    ? 'incumplimiento'
                                    : 'incumplimientos'
                            }}
                            · página {{ history.page }} de
                            {{ history.last_page }}
                        </p>
                        <div v-if="history.last_page > 1" class="flex gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="history.page <= 1 || loading"
                                @click="
                                    visit({ history_page: history.page - 1 })
                                "
                                >Anterior</Button
                            >
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="
                                    history.page >= history.last_page || loading
                                "
                                @click="
                                    visit({ history_page: history.page + 1 })
                                "
                                >Siguiente</Button
                            >
                        </div>
                    </nav>
                </template>
            </div>
        </SurfaceCard>
    </div>
</template>
