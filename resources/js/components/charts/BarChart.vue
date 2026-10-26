<script setup lang="ts">
import { onClickOutside } from '@vueuse/core';
import { computed, ref, useTemplateRef } from 'vue';
import {
    chartTokens,
    formatDuration,
    formatNumber,
    formatPercent,
} from '@/composables/useChartTheme';
import ChartTooltip from './ChartTooltip.vue';
import type { BarItem, BarSeries, ChartTooltipRow, ValueFormat } from './types';

/**
 * Horizontal bars (names read best on the left): grouped bars per item on
 * one shared scale, or with `stacked` one 100 % bar per item whose segments
 * are the shares of each series. Every bar carries its value as a direct
 * label; a value above 0 never disappears (2 px minimum) and a 0 has no bar
 * but still reads «0». Pointer and keyboard (↑/↓ or ←/→) show a readout.
 */
const props = withDefaults(
    defineProps<{
        items: BarItem[];
        series: BarSeries[];
        stacked?: boolean;
        valueFormat?: ValueFormat;
        /** Accessible summary of the chart. */
        description: string;
    }>(),
    { stacked: false, valueFormat: 'number' },
);

const root = useTemplateRef<HTMLElement>('root');

function seriesColor(
    series: BarSeries | undefined,
    index: number,
    item?: BarItem,
): string {
    if (props.series.length === 1 && item?.color) {
        return item.color;
    }

    return (
        series?.color ??
        (index === 0
            ? chartTokens.primary
            : index === 1
              ? chartTokens.secondary
              : (chartTokens.categorical[index] ?? chartTokens.comparison))
    );
}

function format(value: number): string {
    if (props.valueFormat === 'duration') {
        return formatDuration(value);
    }

    if (props.valueFormat === 'percent') {
        return formatPercent(value);
    }

    return formatNumber(value);
}

const largest = computed(() =>
    Math.max(0, ...props.items.flatMap((item) => item.values)),
);

function ratio(value: number): number {
    return largest.value === 0 ? 0 : value / largest.value;
}

function total(item: BarItem): number {
    return item.values.reduce((sum, value) => sum + value, 0);
}

function share(item: BarItem, value: number): number {
    const sum = total(item);

    return sum === 0 ? 0 : value / sum;
}

/** Focusable units: rows (grouped) or segments with a value (stacked). */
const units = computed(() =>
    props.stacked
        ? props.items.flatMap((item, itemIndex) =>
              item.values
                  .map((value, seriesIndex) => ({
                      itemIndex,
                      seriesIndex,
                      value,
                  }))
                  .filter((unit) => unit.value > 0),
          )
        : props.items.map((_, itemIndex) => ({
              itemIndex,
              seriesIndex: -1,
              value: 0,
          })),
);

const active = ref<number | null>(null);
const fromKeyboard = ref(false);
const anchor = ref({ x: 0, y: 0, width: 0 });

function place(index: number): void {
    const element = root.value?.querySelector<HTMLElement>(
        `[data-unit="${index}"]`,
    );
    const box = root.value?.getBoundingClientRect();

    if (!element || !box) {
        return;
    }

    const rect = element.getBoundingClientRect();
    anchor.value = {
        x: props.stacked
            ? rect.left - box.left + rect.width / 2
            : box.width / 2,
        y: rect.bottom - box.top + 6,
        width: box.width,
    };
}

function hover(index: number): void {
    fromKeyboard.value = false;
    active.value = index;
    place(index);
}

function onKeydown(event: KeyboardEvent): void {
    const last = units.value.length - 1;
    const current = active.value ?? -1;
    const forward = ['ArrowDown', 'ArrowRight'].includes(event.key);
    const backward = ['ArrowUp', 'ArrowLeft'].includes(event.key);
    let next: number | undefined;

    if (forward) {
        next = Math.min(last, current + 1);
    } else if (backward) {
        next = Math.max(0, current - 1);
    } else if (event.key === 'Home') {
        next = 0;
    } else if (event.key === 'End') {
        next = last;
    }

    if (next === undefined || last < 0) {
        return;
    }

    event.preventDefault();
    fromKeyboard.value = true;
    active.value = next;
    place(next);
}

function onFocus(): void {
    if (active.value === null && units.value.length > 0) {
        fromKeyboard.value = true;
        active.value = 0;
        place(0);
    }
}

function clear(): void {
    active.value = null;
}

/**
 * Only a mouse leaving the chart hides the readout: on touch, pointerleave
 * fires right after the finger lifts, so the readout stays until the next
 * tap elsewhere (or on another bar).
 */
function onPointerLeave(event: PointerEvent): void {
    if (event.pointerType === 'mouse') {
        clear();
    }
}

onClickOutside(root, clear);

const readout = computed<{ title: string; rows: ChartTooltipRow[] } | null>(
    () => {
        const unit =
            active.value === null ? undefined : units.value[active.value];
        const item = unit ? props.items[unit.itemIndex] : undefined;

        if (!unit || !item) {
            return null;
        }

        if (props.stacked) {
            const series = props.series[unit.seriesIndex];

            return {
                title: series?.name ?? '',
                rows: [
                    {
                        key: series?.key ?? 'value',
                        name: `${formatPercent(share(item, unit.value))}${item.label ? ` · ${item.label}` : ''}`,
                        value: format(unit.value),
                        color: seriesColor(series, unit.seriesIndex, item),
                        shape: 'square',
                    },
                ],
            };
        }

        return {
            title: item.label,
            rows: props.series.map((series, index) => ({
                key: series.key,
                name: series.name,
                value: format(item.values[index] ?? 0),
                color: seriesColor(series, index, item),
                shape: 'square',
            })),
        };
    },
);

const announcement = computed(() =>
    readout.value && fromKeyboard.value
        ? `${readout.value.title}: ${readout.value.rows.map((row) => `${row.value} ${row.name}`).join(', ')}`
        : '',
);

function unitIndex(itemIndex: number, seriesIndex: number): number {
    return units.value.findIndex(
        (unit) =>
            unit.itemIndex === itemIndex && unit.seriesIndex === seriesIndex,
    );
}
</script>

<template>
    <div ref="root" class="relative" data-bar-chart>
        <div
            class="rounded-lg focus-visible:outline-offset-4"
            role="img"
            :aria-label="description"
            tabindex="0"
            @keydown="onKeydown"
            @focus="onFocus"
            @blur="clear"
            @pointerleave="onPointerLeave"
        >
            <ul v-if="!stacked" class="flex flex-col gap-1" aria-hidden="true">
                <li
                    v-for="(item, itemIndex) in items"
                    :key="item.key"
                    class="grid grid-cols-1 gap-x-3 gap-y-1 rounded-md px-1.5 py-1 sm:grid-cols-[minmax(6rem,32%)_1fr] sm:items-center"
                    :class="active === itemIndex && 'bg-muted/60'"
                    :data-unit="itemIndex"
                    :data-bar-row="item.key"
                    @pointerenter="hover(itemIndex)"
                    @pointerdown="hover(itemIndex)"
                >
                    <span
                        class="truncate text-sm"
                        :class="
                            item.muted
                                ? 'text-muted-foreground'
                                : 'text-foreground'
                        "
                        :title="item.label"
                    >
                        {{ item.label }}
                    </span>
                    <div class="flex min-w-0 flex-col gap-0.5">
                        <div
                            v-for="(seriesItem, seriesIndex) in series"
                            :key="seriesItem.key"
                            class="flex min-w-0 items-center gap-1.5"
                        >
                            <span
                                v-if="(item.values[seriesIndex] ?? 0) > 0"
                                class="h-3 shrink-0 rounded-r-[4px]"
                                :style="{
                                    width: `calc((100% - 4.5rem) * ${ratio(item.values[seriesIndex] ?? 0)})`,
                                    minWidth: '2px',
                                    backgroundColor: seriesColor(
                                        seriesItem,
                                        seriesIndex,
                                        item,
                                    ),
                                }"
                            />
                            <span
                                class="text-xs whitespace-nowrap text-muted-foreground tabular-nums"
                                :data-bar-value="seriesItem.key"
                            >
                                {{ format(item.values[seriesIndex] ?? 0) }}
                            </span>
                        </div>
                    </div>
                </li>
            </ul>

            <div v-else class="flex flex-col gap-3" aria-hidden="true">
                <div
                    v-for="(item, itemIndex) in items"
                    :key="item.key"
                    class="space-y-1.5"
                >
                    <p v-if="item.label" class="text-sm">{{ item.label }}</p>
                    <div
                        class="flex h-4 w-full gap-0.5 overflow-hidden rounded-[4px]"
                    >
                        <template
                            v-for="(seriesItem, seriesIndex) in series"
                            :key="seriesItem.key"
                        >
                            <span
                                v-if="(item.values[seriesIndex] ?? 0) > 0"
                                class="h-full shrink-0 transition-opacity"
                                :class="
                                    active !== null &&
                                    active !==
                                        unitIndex(itemIndex, seriesIndex) &&
                                    'opacity-60'
                                "
                                :style="{
                                    flexGrow: item.values[seriesIndex],
                                    flexBasis: 0,
                                    minWidth: '2px',
                                    backgroundColor: seriesColor(
                                        seriesItem,
                                        seriesIndex,
                                        item,
                                    ),
                                }"
                                :data-unit="unitIndex(itemIndex, seriesIndex)"
                                :data-segment="seriesItem.key"
                                @pointerenter="
                                    hover(unitIndex(itemIndex, seriesIndex))
                                "
                                @pointerdown="
                                    hover(unitIndex(itemIndex, seriesIndex))
                                "
                            />
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <ChartTooltip
            v-if="readout && active !== null"
            :title="readout.title"
            :rows="readout.rows"
            :x="anchor.x"
            :y="anchor.y"
            :container-width="anchor.width"
            side="center"
        />
        <p class="sr-only" aria-live="polite">{{ announcement }}</p>
    </div>
</template>
