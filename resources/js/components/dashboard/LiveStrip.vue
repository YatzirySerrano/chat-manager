<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { NumberTicker } from '@/components/ui/number-ticker';

export type LiveFigure = {
    key: string;
    label: string;
    value: number | null;
    /** Shown under the label on wide screens and as the tooltip. */
    hint?: string;
    /** Drill-down to the list behind the figure. */
    href?: string;
    /** `attention`: the figure needs someone (pending requests). */
    tone?: 'attention';
};

/**
 * The inbox right now as one dense strip of counters (no card per figure):
 * a pulsing «En vivo» mark, then each figure with its label; separators
 * between them. Figures count to their new value when the page refreshes
 * them in the background; a figure with `href` opens its list.
 */
defineProps<{
    figures: LiveFigure[];
    unavailableLabel?: string;
}>();

/** The last figure fills its row (2 columns on phones, 3 on tablets). */
function fill(index: number, count: number): string[] {
    if (index !== count - 1) {
        return [];
    }

    const classes: string[] = [];

    if (count % 2 === 1) {
        classes.push('max-sm:col-span-2');
    }

    if (count > 3 && count % 3 === 1) {
        classes.push('sm:max-lg:col-span-3');
    } else if (count > 3 && count % 3 === 2) {
        classes.push('sm:max-lg:col-span-2');
    }

    return classes;
}
</script>

<template>
    <section
        class="flex flex-col overflow-hidden rounded-xl border bg-card shadow-(--shadow-control) md:flex-row md:items-stretch"
        aria-label="Ahora en la bandeja"
        data-tour="dashboard.kpis"
    >
        <div
            class="flex items-center gap-2 border-b px-4 py-2.5 text-xs font-medium text-muted-foreground md:w-28 md:shrink-0 md:flex-col md:items-start md:justify-center md:gap-1 md:border-r md:border-b-0"
            title="Cifras en vivo: no dependen del periodo"
        >
            <span class="flex items-center gap-1.5 text-foreground">
                <span
                    class="live-dot size-2 rounded-full bg-current text-success"
                    aria-hidden="true"
                />
                En vivo
            </span>
            <span class="md:text-[0.6875rem]">Ahora mismo</span>
        </div>
        <dl
            class="grid flex-1 grid-cols-2 gap-px bg-border sm:grid-cols-3"
            :class="figures.length > 3 && 'lg:grid-cols-(--live-cols)'"
            :style="{
                '--live-cols': `repeat(${figures.length}, minmax(0, 1fr))`,
            }"
        >
            <div
                v-for="(figure, index) in figures"
                :key="figure.key"
                class="relative flex min-w-0 flex-col gap-0.5 bg-card px-4 py-3"
                :class="fill(index, figures.length)"
                :title="figure.hint"
                :data-kpi="figure.key"
            >
                <dt
                    class="flex items-center gap-1.5 truncate text-xs font-medium text-muted-foreground"
                >
                    <span
                        v-if="figure.tone === 'attention' && figure.value"
                        class="size-1.5 shrink-0 rounded-full bg-warning"
                        aria-hidden="true"
                    />
                    {{ figure.label }}
                </dt>
                <dd
                    class="font-display text-[1.375rem] leading-7 font-semibold tracking-tight tabular-nums"
                    data-kpi-value
                >
                    <template v-if="figure.value === null">
                        <span
                            class="text-sm font-medium text-muted-foreground"
                            >{{ unavailableLabel ?? 'Sin datos' }}</span
                        >
                    </template>
                    <Link
                        v-else-if="figure.href"
                        :href="figure.href"
                        class="rounded-sm underline decoration-transparent underline-offset-4 outline-none hover:decoration-current focus-visible:ring-2 focus-visible:ring-ring motion-safe:transition-[text-decoration-color]"
                        title="Ver en la bandeja"
                    >
                        <NumberTicker :value="figure.value" />
                    </Link>
                    <NumberTicker v-else :value="figure.value" />
                </dd>
            </div>
        </dl>
    </section>
</template>
