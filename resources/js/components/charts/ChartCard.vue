<script setup lang="ts">
import { ChartNoAxesCombined, Table2 } from '@lucide/vue';
import { computed, onMounted, ref, useId, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';

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
</script>

<template>
    <section
        class="@container flex min-w-0 flex-col gap-4 rounded-xl border bg-surface-raised p-4 shadow-card sm:p-5"
        :aria-labelledby="titleId"
        :aria-busy="loading || undefined"
        data-chart-card
    >
        <header
            class="flex flex-wrap items-start justify-between gap-x-3 gap-y-2"
        >
            <div class="min-w-0 flex-1 space-y-0.5">
                <h3 :id="titleId" class="text-base font-semibold">
                    {{ title }}
                </h3>
                <p
                    v-if="description"
                    class="text-sm text-pretty text-muted-foreground"
                >
                    {{ description }}
                </p>
                <p
                    v-if="total && ready"
                    class="pt-1 text-sm font-medium text-foreground"
                    data-chart-total
                >
                    {{ total }}
                </p>
            </div>
            <div class="flex shrink-0 items-center gap-1">
                <slot name="actions" />
                <Button
                    v-if="ready && $slots.table"
                    variant="ghost"
                    size="sm"
                    class="text-muted-foreground"
                    :aria-pressed="showTable"
                    :title="showTable ? 'Ver como gráfica' : 'Ver como tabla'"
                    data-chart-toggle
                    :data-tour="toggleTour"
                    @click="showTable = !showTable"
                >
                    <ChartNoAxesCombined
                        v-if="showTable"
                        class="size-4"
                        aria-hidden="true"
                    />
                    <Table2 v-else class="size-4" aria-hidden="true" />
                    <span class="sr-only @sm:not-sr-only">{{
                        showTable ? 'Ver como gráfica' : 'Ver como tabla'
                    }}</span>
                </Button>
            </div>
        </header>

        <div v-if="loading" aria-hidden="true">
            <div
                v-if="skeleton === 'line'"
                class="flex h-[180px] items-end gap-1.5 sm:h-[220px]"
            >
                <Skeleton
                    v-for="(height, index) in columnHeights"
                    :key="index"
                    class="flex-1 rounded-b-none"
                    :style="{ height: `${height}%` }"
                />
            </div>
            <div v-else class="flex flex-col gap-3 py-2">
                <div
                    v-for="(width, index) in barWidths"
                    :key="index"
                    class="flex items-center gap-3"
                >
                    <Skeleton class="h-3 w-20 shrink-0" />
                    <Skeleton class="h-3" :style="{ width: `${width}%` }" />
                </div>
            </div>
            <span class="sr-only">Cargando gráfica…</span>
        </div>

        <div
            v-else-if="empty"
            class="flex min-h-[180px] flex-col items-center justify-center gap-2 rounded-lg border border-dashed px-4 py-8 text-center"
            data-chart-empty
        >
            <ChartNoAxesCombined
                class="size-6 text-muted-foreground"
                aria-hidden="true"
            />
            <p class="text-sm text-muted-foreground">{{ emptyText }}</p>
        </div>

        <template v-else>
            <div v-if="showTable" data-chart-table>
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

        <p v-if="footnote && ready" class="text-xs text-muted-foreground">
            {{ footnote }}
        </p>
    </section>
</template>
