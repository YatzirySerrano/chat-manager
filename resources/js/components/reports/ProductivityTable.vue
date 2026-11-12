<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, ArrowUpDown } from '@lucide/vue';
import { computed, ref } from 'vue';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDuration } from '@/lib/format';
import type { AgentProductivityRow } from '@/types';

/**
 * Productivity per collaborator, sortable by any column. A missing average
 * or rate reads «Sin datos» (never zero) and every average or percentage
 * carries its base (n). «Pendientes actuales» links to the inbox filtered
 * by that person when a link builder is given. While the per-person data of
 * the period is not fully built (`complete` false) a zero of the period is
 * not a measured zero: it reads «Sin datos».
 */
type SortKey =
    | 'name'
    | 'assigned'
    | 'attended'
    | 'closes'
    | 'replies'
    | 'first_response_seconds'
    | 'handling_seconds'
    | 'pending_now'
    | 'overdue_now'
    | 'sla_rate';

const props = withDefaults(
    defineProps<{
        rows: AgentProductivityRow[];
        /** False while the per-person data of the period is partial (analytics not rebuilt). */
        complete?: boolean;
        /** Builds the inbox link of a person's current load (omit to show plain numbers). */
        pendingHref?: (row: AgentProductivityRow) => string | undefined;
    }>(),
    { complete: true, pendingHref: undefined },
);

const columns: { key: SortKey; label: string; hint: string; hide?: string }[] =
    [
        {
            key: 'assigned',
            label: 'Asignadas',
            hint: 'Solicitudes del periodo de las que fue responsable en algún momento',
            hide: 'hidden lg:table-cell',
        },
        {
            key: 'attended',
            label: 'Atendidas',
            hint: 'Solicitudes del periodo en las que participó: las aceptó, se le asignaron o transfirieron, o respondió',
        },
        {
            key: 'closes',
            label: 'Finalizadas',
            hint: 'Cierres hechos mientras era responsable; un recierre tras una reapertura cuenta de nuevo',
        },
        {
            key: 'replies',
            label: 'Respuestas',
            hint: 'Mensajes escritos por la persona y aceptados por el canal (son mensajes, no solicitudes)',
            hide: 'hidden xl:table-cell',
        },
        {
            key: 'first_response_seconds',
            label: 'Espera 1ª respuesta',
            hint: 'Promedio de espera del cliente en las solicitudes cuya primera respuesta escribió',
            hide: 'hidden md:table-cell',
        },
        {
            key: 'handling_seconds',
            label: 'Tiempo de atención',
            hint: 'Promedio del tiempo en que fue responsable de cada solicitud (solo tramos concluidos)',
            hide: 'hidden md:table-cell',
        },
        {
            key: 'pending_now',
            label: 'Pendientes actuales',
            hint: 'Asignadas ahora y sin cerrar (no depende del periodo)',
        },
        {
            key: 'overdue_now',
            label: 'Vencidas',
            hint: 'Esperan respuesta ahora, fuera de su plazo',
        },
        {
            key: 'sla_rate',
            label: 'Cumplimiento SLA',
            hint: 'Ciclos respondidos a tiempo entre los evaluados a su cargo',
        },
    ];

const sortKey = ref<SortKey>('attended');
const descending = ref(true);

function sortBy(key: SortKey): void {
    if (sortKey.value === key) {
        descending.value = !descending.value;

        return;
    }

    sortKey.value = key;
    descending.value = key !== 'name';
}

const sorted = computed(() => {
    const key = sortKey.value;
    const direction = descending.value ? -1 : 1;

    return [...props.rows].sort((a, b) => {
        const left = a[key];
        const right = b[key];

        // Rows without the datum always go last, whatever the direction.
        if (left === null || right === null) {
            return left === right ? 0 : left === null ? 1 : -1;
        }

        if (typeof left === 'string' || typeof right === 'string') {
            return String(left).localeCompare(String(right), 'es') * direction;
        }

        return (left - right) * direction;
    });
});

function ariaSort(key: SortKey): 'ascending' | 'descending' | 'none' {
    return sortKey.value !== key
        ? 'none'
        : descending.value
          ? 'descending'
          : 'ascending';
}

function number(value: number): string {
    return value.toLocaleString('es-MX');
}

/** A count of the period: an unbuilt zero is unknown, not zero. */
function counted(value: number): string {
    return value === 0 && !props.complete ? 'Sin datos' : number(value);
}

function duration(seconds: number | null): string {
    return seconds === null ? 'Sin datos' : formatDuration(seconds);
}
</script>

<template>
    <div class="overflow-x-auto" data-productivity-table>
        <Table v-if="rows.length > 0">
            <TableHeader>
                <TableRow class="hover:bg-transparent">
                    <TableHead class="pl-5" :aria-sort="ariaSort('name')">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 rounded-sm font-medium outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            data-sort="name"
                            @click="sortBy('name')"
                        >
                            Colaborador
                            <component
                                :is="
                                    sortKey !== 'name'
                                        ? ArrowUpDown
                                        : descending
                                          ? ArrowDown
                                          : ArrowUp
                                "
                                class="size-3.5 text-muted-foreground"
                                aria-hidden="true"
                            />
                        </button>
                    </TableHead>
                    <TableHead
                        v-for="column in columns"
                        :key="column.key"
                        class="text-right last:pr-5"
                        :class="column.hide"
                        :aria-sort="ariaSort(column.key)"
                    >
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 rounded-sm text-right font-medium outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            :title="column.hint"
                            :data-sort="column.key"
                            @click="sortBy(column.key)"
                        >
                            {{ column.label }}
                            <component
                                :is="
                                    sortKey !== column.key
                                        ? ArrowUpDown
                                        : descending
                                          ? ArrowDown
                                          : ArrowUp
                                "
                                class="size-3.5 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                        </button>
                    </TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableRow
                    v-for="(row, index) in sorted"
                    :key="row.id ?? `hidden-${index}`"
                    data-productivity-row
                >
                    <TableCell class="pl-5 font-medium">{{
                        row.name
                    }}</TableCell>
                    <TableCell
                        class="hidden text-right tabular-nums lg:table-cell"
                        >{{ counted(row.assigned) }}</TableCell
                    >
                    <TableCell class="text-right tabular-nums">{{
                        counted(row.attended)
                    }}</TableCell>
                    <TableCell class="text-right tabular-nums">{{
                        counted(row.closes)
                    }}</TableCell>
                    <TableCell
                        class="hidden text-right tabular-nums xl:table-cell"
                        >{{ counted(row.replies) }}</TableCell
                    >
                    <TableCell
                        class="hidden text-right tabular-nums md:table-cell"
                    >
                        {{ duration(row.first_response_seconds) }}
                        <span
                            v-if="row.first_response_seconds !== null"
                            class="text-muted-foreground"
                        >
                            (n = {{ number(row.first_responses) }})</span
                        >
                    </TableCell>
                    <TableCell
                        class="hidden text-right tabular-nums md:table-cell"
                    >
                        {{ duration(row.handling_seconds) }}
                        <span
                            v-if="row.handling_seconds !== null"
                            class="text-muted-foreground"
                        >
                            (n = {{ number(row.handling_samples) }})</span
                        >
                    </TableCell>
                    <TableCell class="text-right tabular-nums">
                        <Link
                            v-if="
                                row.pending_now > 0 &&
                                pendingHref &&
                                pendingHref(row)
                            "
                            :href="pendingHref(row) ?? ''"
                            class="underline decoration-border underline-offset-4 hover:decoration-foreground"
                            :title="`Ver en la bandeja las solicitudes de ${row.name}`"
                            data-drill="pending"
                        >
                            {{ number(row.pending_now) }}
                        </Link>
                        <template v-else>{{
                            number(row.pending_now)
                        }}</template>
                    </TableCell>
                    <TableCell
                        class="text-right tabular-nums"
                        :class="{
                            'font-medium text-destructive': row.overdue_now > 0,
                        }"
                        >{{ number(row.overdue_now) }}</TableCell
                    >
                    <TableCell class="pr-5 text-right tabular-nums">
                        <template v-if="row.sla_rate === null"
                            ><span class="text-muted-foreground"
                                >Sin datos</span
                            ></template
                        >
                        <template v-else>
                            {{ number(row.sla_rate) }} %
                            <span class="text-muted-foreground">
                                (n = {{ number(row.sla_samples) }})</span
                            >
                        </template>
                    </TableCell>
                </TableRow>
            </TableBody>
        </Table>
        <p
            v-else-if="!complete"
            class="px-5 py-6 text-sm text-muted-foreground"
        >
            Sin datos: la productividad de este periodo aún no se ha calculado.
        </p>
        <p v-else class="px-5 py-6 text-sm text-muted-foreground">
            Nadie atendió solicitudes recibidas en este periodo con estos
            filtros, ni tiene solicitudes asignadas ahora.
        </p>
    </div>
</template>
