<script setup lang="ts">
import { ChartNoAxesCombined, Table2 } from '@lucide/vue';
import { computed, onMounted, ref, useId, watch } from 'vue';
import '@/components/reports/insights.css';

/**
 * Frame shared by every chart: title, short description, optional total,
 * the drawing (default slot) with its legend, a «Ver como tabla» switch to
 * the exact data (table slot, also read by screen readers while the chart is
 * shown), a skeleton with the shape of the chart while loading and an
 * explicit empty state (never a flat line of fake zeros).
 */
const props = withDefaults(
    defineProps<{
        title: string;
        description?: string;
        /** Headline figure of the card, already formatted. */
        total?: string;
        loading?: boolean;
        empty?: boolean;
        emptyText?: string;
        /** Remembers the chart/table choice per card in this browser. */
        storageKey?: string;
        /** Shape of the loading skeleton. */
        skeleton?: 'line' | 'bars';
        /** Small print under the chart (e.g. the time zone). */
        footnote?: string;
        /** data-tour of the «Ver como tabla» switch (guided tours). */
        toggleTour?: string;
    }>(),
    {
        description: undefined,
        total: undefined,
        loading: false,
        empty: false,
        emptyText: 'Sin conversaciones en este periodo',
        storageKey: undefined,
        skeleton: 'line',
        footnote: undefined,
        toggleTour: undefined,
    },
);

const titleId = useId();
const showTable = ref(false);

function storageName(): string | null {
    return props.storageKey ? `chart-view:${props.storageKey}` : null;
}

onMounted(() => {
    const name = storageName();

    if (!name) {
        return;
    }

    try {
        showTable.value = window.localStorage.getItem(name) === 'table';
    } catch {
        // Storage blocked: the chart view stays the default.
    }
});

watch(showTable, (value) => {
    const name = storageName();

    if (!name) {
        return;
    }

    try {
        window.localStorage.setItem(name, value ? 'table' : 'chart');
    } catch {
        // Storage blocked: the choice only lasts for this visit.
    }
});

const ready = computed(() => !props.loading && !props.empty);

const columnHeights = [42, 58, 50, 72, 64, 80, 55, 68, 88, 61, 74, 52];
const barWidths = [92, 76, 64, 48, 36, 22];
/* Ghost bars of the empty state (decorative). */
const emptyHeights = [30, 48, 38, 62, 44, 70, 52];
</script>

<template>
    <section
        class="@container flex min-w-0 flex-col gap-3 rounded-xl border bg-card p-4 shadow-(--shadow-control)"
        :aria-labelledby="titleId"
        :aria-busy="loading || undefined"
        data-chart-card
    >
        <header
            class="flex flex-wrap items-start justify-between gap-x-3 gap-y-2"
        >
            <div class="min-w-0 flex-1 basis-56 space-y-0.5">
                <h3
                    :id="titleId"
                    class="text-sm leading-5 font-semibold tracking-tight text-balance"
                >
                    {{ title }}
                </h3>
                <p
                    v-if="description"
                    class="text-xs leading-5 text-pretty text-muted-foreground"
                >
                    {{ description }}
                </p>
                <p
                    v-if="total && ready"
                    class="max-w-full pt-1 text-[0.8125rem] font-medium text-foreground tabular-nums"
                    data-chart-total
                >
                    <span class="text-pretty">{{ total }}</span>
                </p>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-1.5">
                <slot name="actions" />
                <button
                    v-if="ready && $slots.table"
                    type="button"
                    class="inline-flex h-7 items-center gap-1.5 rounded-md px-2 text-xs font-medium text-muted-foreground transition-colors duration-(--duration-fast) outline-none hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring aria-pressed:bg-muted aria-pressed:text-foreground pointer-coarse:h-10"
                    :aria-pressed="showTable"
                    :title="showTable ? 'Ver como gráfica' : 'Ver como tabla'"
                    data-chart-toggle
                    :data-tour="toggleTour"
                    @click="showTable = !showTable"
                >
                    <ChartNoAxesCombined
                        v-if="showTable"
                        class="size-3.5"
                        aria-hidden="true"
                    />
                    <Table2 v-else class="size-3.5" aria-hidden="true" />
                    <span class="sr-only @sm:not-sr-only">{{
                        showTable ? 'Ver como gráfica' : 'Ver como tabla'
                    }}</span>
                </button>
            </div>
        </header>

        <div v-if="loading" aria-hidden="true">
            <div
                v-if="skeleton === 'line'"
                class="flex h-[180px] items-end gap-1.5 border-b border-dashed pb-px sm:h-[220px]"
            >
                <span
                    v-for="(height, index) in columnHeights"
                    :key="index"
                    class="flex-1 shimmer rounded-t-md"
                    :style="{
                        height: `${height}%`,
                        opacity: 0.55 + (index % 4) * 0.12,
                    }"
                />
            </div>
            <div v-else class="flex flex-col gap-3.5 py-2">
                <div
                    v-for="(width, index) in barWidths"
                    :key="index"
                    class="flex items-center gap-3"
                >
                    <span class="h-3 w-20 shrink-0 shimmer rounded-full" />
                    <span
                        class="h-3 shimmer rounded-full"
                        :style="{ width: `${width}%` }"
                    />
                </div>
            </div>
            <span class="sr-only">Cargando gráfica…</span>
        </div>

        <div
            v-else-if="empty"
            class="relative flex min-h-[160px] flex-col items-center justify-center gap-3 overflow-hidden rounded-lg bg-surface-sunken/50 px-4 py-6 text-center"
            data-chart-empty
        >
            <div
                class="flex h-14 items-end gap-1.5 opacity-70"
                aria-hidden="true"
            >
                <span
                    v-for="(height, index) in emptyHeights"
                    :key="index"
                    class="chart-empty-bar w-3 rounded-t-[4px] bg-[color-mix(in_oklch,var(--muted-foreground)_18%,transparent)]"
                    :style="{ height: `${height}%`, '--i': index }"
                />
            </div>
            <p class="max-w-[36ch] text-sm text-pretty text-muted-foreground">
                {{ emptyText }}
            </p>
        </div>

        <template v-else>
            <div v-if="showTable" class="enter-fade" data-chart-table>
                <slot name="table" />
            </div>
            <template v-else>
                <div class="min-w-0">
                    <slot />
                </div>
                <div v-if="$slots.legend">
                    <slot name="legend" />
                </div>
                <div v-if="$slots.table" class="sr-only">
                    <slot name="table" />
                </div>
            </template>
        </template>

        <p
            v-if="footnote && ready"
            class="text-xs text-pretty text-muted-foreground"
        >
            {{ footnote }}
        </p>
    </section>
</template>
