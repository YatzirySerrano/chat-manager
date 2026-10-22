<script setup lang="ts">
import { computed } from 'vue';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDuration } from '@/lib/format';
import type { AnalyticsBreakdownRow } from '@/types';

/**
 * Figures of the period per agent, department or connection. A missing
 * average shows "—" (not enough data), never zero.
 */
const props = withDefaults(
    defineProps<{
        rows: AnalyticsBreakdownRow[];
        /** Header of the first column, e.g. "Agente". */
        dimension: string;
        showLoad?: boolean;
    }>(),
    { showLoad: false },
);

const hasRows = computed(() => props.rows.length > 0);

function duration(seconds: number | null): string {
    return seconds === null ? '—' : formatDuration(seconds);
}

function number(value: number): string {
    return value.toLocaleString('es-MX');
}

function percent(part: number, total: number): string {
    return total === 0 ? '' : ` (${Math.round((part / total) * 100)} %)`;
}
</script>

<template>
    <div class="overflow-x-auto">
        <Table v-if="hasRows">
            <TableHeader>
                <TableRow class="hover:bg-transparent">
                    <TableHead class="pl-5">{{ dimension }}</TableHead>
                    <TableHead class="text-right">Recibidas</TableHead>
                    <TableHead class="text-right">Atendidas</TableHead>
                    <TableHead class="text-right">Resueltas</TableHead>
                    <TableHead class="text-right">1ª respuesta</TableHead>
                    <TableHead class="hidden text-right lg:table-cell"
                        >Resolución</TableHead
                    >
                    <TableHead class="hidden text-right md:table-cell"
                        >Transferencias</TableHead
                    >
                    <TableHead class="hidden text-right md:table-cell"
                        >Reabiertas</TableHead
                    >
                    <TableHead class="hidden text-right xl:table-cell"
                        >Errores de envío</TableHead
                    >
                    <TableHead v-if="showLoad" class="pr-5 text-right"
                        >Carga actual</TableHead
                    >
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableRow v-for="row in rows" :key="row.id ?? 'none'">
                    <TableCell
                        class="pl-5 font-medium"
                        :class="{ 'text-muted-foreground': row.id === null }"
                    >
                        {{ row.name }}
                    </TableCell>
                    <TableCell class="text-right tabular-nums">{{
                        number(row.received)
                    }}</TableCell>
                    <TableCell class="text-right tabular-nums">
                        {{ number(row.attended)
                        }}<span class="text-muted-foreground">{{
                            percent(row.attended, row.received)
                        }}</span>
                    </TableCell>
                    <TableCell class="text-right tabular-nums">
                        {{ number(row.resolved)
                        }}<span class="text-muted-foreground">{{
                            percent(row.resolved, row.received)
                        }}</span>
                    </TableCell>
                    <TableCell class="text-right tabular-nums">{{
                        duration(row.first_response_seconds)
                    }}</TableCell>
                    <TableCell
                        class="hidden text-right tabular-nums lg:table-cell"
                        >{{ duration(row.resolution_seconds) }}</TableCell
                    >
                    <TableCell
                        class="hidden text-right tabular-nums md:table-cell"
                        >{{ number(row.transfers) }}</TableCell
                    >
                    <TableCell
                        class="hidden text-right tabular-nums md:table-cell"
                        >{{ number(row.reopened) }}</TableCell
                    >
                    <TableCell
                        class="hidden text-right tabular-nums xl:table-cell"
                        :class="{ 'text-destructive': row.failed_out > 0 }"
                    >
                        {{ number(row.failed_out) }}
                    </TableCell>
                    <TableCell
                        v-if="showLoad"
                        class="pr-5 text-right tabular-nums"
                    >
                        {{
                            row.current_load === null
                                ? '—'
                                : number(row.current_load)
                        }}
                    </TableCell>
                </TableRow>
            </TableBody>
        </Table>
        <p v-else class="px-5 py-8 text-center text-sm text-muted-foreground">
            Sin conversaciones recibidas en el periodo con estos filtros.
        </p>
    </div>
</template>
