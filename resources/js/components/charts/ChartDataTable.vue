<script setup lang="ts">
import { formatNumber } from '@/composables/useChartTheme';
import type { ChartTableColumn } from './types';

/**
 * The exact data of a chart as a table: the «Ver como tabla» view and the
 * screen reader equivalent of the drawing. Scrolls inside its card when the
 * columns do not fit, never the page.
 */
defineProps<{
    columns: ChartTableColumn[];
    rows: Record<string, unknown>[];
    caption: string;
}>();

function cell(column: ChartTableColumn, row: Record<string, unknown>): string {
    const value = row[column.key];

    if (column.format) {
        return column.format(value, row);
    }

    if (value === null || value === undefined) {
        return '—';
    }

    return typeof value === 'number' ? formatNumber(value) : String(value);
}
</script>

<template>
    <div class="max-h-96 overflow-auto rounded-lg border">
        <table class="w-full text-sm">
            <caption class="sr-only">
                {{
                    caption
                }}
            </caption>
            <thead class="sticky top-0 bg-surface-sunken">
                <tr>
                    <th
                        v-for="column in columns"
                        :key="column.key"
                        scope="col"
                        class="px-3 py-2 text-xs font-medium whitespace-nowrap text-muted-foreground"
                        :class="
                            column.align === 'right'
                                ? 'text-right'
                                : 'text-left'
                        "
                    >
                        {{ column.label }}
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="(row, index) in rows"
                    :key="index"
                    class="border-t"
                    data-chart-row
                >
                    <component
                        :is="columnIndex === 0 ? 'th' : 'td'"
                        v-for="(column, columnIndex) in columns"
                        :key="column.key"
                        :scope="columnIndex === 0 ? 'row' : undefined"
                        class="px-3 py-1.5"
                        :class="[
                            column.align === 'right'
                                ? 'text-right tabular-nums'
                                : 'text-left',
                            columnIndex === 0 ? 'font-medium' : 'font-normal',
                        ]"
                        :data-column="column.key"
                    >
                        {{ cell(column, row) }}
                    </component>
                </tr>
            </tbody>
        </table>
    </div>
</template>
