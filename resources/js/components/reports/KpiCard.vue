<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';
import { ArrowDownRight, ArrowRight, ArrowUpRight, Minus } from '@lucide/vue';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { NumberTicker } from '@/components/ui/number-ticker';
import { formatPercent } from '@/composables/useChartTheme';
import './insights.css';

/**
 * A headline figure with its comparison against the previous period.
 *
 * Anatomy: a gradient icon tile and the label on top, the figure in the
 * display face (a count, a grouped count like «1,245» or a percentage like
 * «85 %» counts up to its number; a duration is shown as is), then the
 * change as a soft pill (green when it improved, red when it got worse,
 * gray when the direction means nothing) with the previous figure beside
 * it, the hint and the drill-down link.
 *
 * `value === null` renders the unavailable label, never a fake zero, and
 * the comparison only shows when both periods have data. Label and hint
 * are never cut (they wrap; long words break and hyphenate): grids of
 * these cards use one column below `sm`.
 */
const props = withDefaults(
    defineProps<{
        label: string;
        /** Already formatted value (or a number), null when there is not enough data. */
        value: string | number | null;
        current?: number | null;
        previous?: number | null;
        /** Which direction is an improvement (decides the tone of the change). */
        better?: 'higher' | 'lower' | 'neutral';
        /** How the previous value is shown, e.g. "12 min" (defaults to the number). */
        previousLabel?: string | null;
        hint?: string;
        icon?: LucideIcon;
        unavailableLabel?: string;
        /** `compact` (default) for metric rows; `default` for a roomier card. */
        size?: 'compact' | 'default';
        /** A live figure has no previous period: «En vivo» instead of a comparison. */
        live?: boolean;
        /** A figure that is never compared (a count of the team, a setting). */
        plain?: boolean;
        /** Color of the icon tile: the palette or a state that needs attention. */
        tone?: 'primary' | 'warning' | 'success' | 'destructive' | 'muted';
        /** Drill-down: the list behind the figure (shown as a link under it). */
        href?: string;
        /** Text of the drill-down link. */
        hrefLabel?: string;
    }>(),
    {
        current: null,
        previous: null,
        better: 'neutral',
        previousLabel: null,
        hint: undefined,
        icon: undefined,
        unavailableLabel: 'Sin datos suficientes',
        size: 'compact',
        live: false,
        plain: false,
        tone: 'primary',
        href: undefined,
        hrefLabel: 'Ver en la bandeja',
    },
);

const change = computed(() => {
    if (props.live || props.plain) {
        return null;
    }

    if (props.current === null || props.previous === null) {
        return null;
    }

    if (props.previous === 0) {
        return props.current === 0
            ? { percent: 0, direction: 'flat' as const }
            : null;
    }

    const percent = ((props.current - props.previous) / props.previous) * 100;

    return {
        percent: Math.abs(percent),
        direction:
            Math.abs(percent) < 0.5
                ? ('flat' as const)
                : percent > 0
                  ? ('up' as const)
                  : ('down' as const),
    };
});

const deltaTone = computed<'good' | 'bad' | 'neutral'>(() => {
    if (
        !change.value ||
        change.value.direction === 'flat' ||
        props.better === 'neutral'
    ) {
        return 'neutral';
    }

    const improved =
        (change.value.direction === 'up') === (props.better === 'higher');

    return improved ? 'good' : 'bad';
});

const toneWord = computed(() =>
    deltaTone.value === 'good'
        ? 'mejora'
        : deltaTone.value === 'bad'
          ? 'empeora'
          : '',
);

/**
 * The value as a number when it can count up: plain or grouped digits
 * («13», «1,245»), or a percentage («85 %», «12.5%»). Otherwise null.
 */
const ticker = computed<{
    value: number;
    decimals: number;
    suffix: string;
} | null>(() => {
    const value = props.value;

    if (typeof value === 'number') {
        return Number.isFinite(value)
            ? {
                  value,
                  decimals: Number.isInteger(value) ? 0 : 1,
                  suffix: '',
              }
            : null;
    }

    if (value === null) {
        return null;
    }

    const grouped = /^\d{1,3}(,\d{3})*$|^\d{1,9}$/.test(value);

    if (grouped) {
        return {
            value: Number(value.replace(/,/g, '')),
            decimals: 0,
            suffix: '',
        };
    }

    const percent = /^(\d{1,3}(?:\.(\d{1,2}))?)(\s?)%$/.exec(value);

    if (percent) {
        return {
            value: Number(percent[1]),
            decimals: percent[2]?.length ?? 0,
            suffix: `${percent[3]}%`,
        };
    }

    return null;
});

const previousText = computed(
    () =>
        props.previousLabel ??
        (props.previous === null
            ? null
            : props.previous.toLocaleString('es-MX')),
);

const compact = computed(() => props.size === 'compact');
</script>

<template>
    <div
        :class="[
            'kpi-card spotlight flex min-w-0 hover-lift flex-col rounded-xl border border-[color-mix(in_oklch,var(--border)_85%,transparent)] bg-card shadow-float',
            compact ? 'gap-2 p-4' : 'gap-3 p-5',
        ]"
        :data-tone="props.tone === 'primary' ? undefined : props.tone"
        data-kpi-card
    >
        <div class="flex min-w-0 items-start gap-2.5">
            <span
                v-if="icon"
                class="insight-tile"
                :data-size="compact ? 'sm' : undefined"
                :data-tone="props.tone === 'primary' ? undefined : props.tone"
                aria-hidden="true"
            >
                <component
                    :is="icon"
                    :class="compact ? 'size-3.5' : 'size-4.5'"
                />
            </span>
            <span
                class="min-w-0 flex-1 font-medium text-pretty break-words hyphens-auto text-muted-foreground"
                :class="[compact ? 'pt-1 text-xs' : 'pt-2 text-sm']"
                data-kpi-label
                >{{ label }}</span
            >
            <!-- Live figures breathe: they follow the inbox in real time. -->
            <span
                v-if="live"
                class="mt-0.5 inline-flex shrink-0 items-center gap-1.5 rounded-full bg-success-soft px-2 py-0.5 text-[0.6875rem] font-semibold text-success-soft-foreground"
                title="Cifra en vivo: no depende del periodo"
            >
                <span
                    class="live-dot size-1.5 rounded-full bg-current text-success"
                    aria-hidden="true"
                />
                En vivo
            </span>
        </div>

        <p
            v-if="value !== null"
            :class="[
                'font-display font-semibold tracking-tight tabular-nums',
                compact ? 'text-[1.75rem] leading-8' : 'text-4xl leading-10',
            ]"
            data-kpi-value
        >
            <NumberTicker
                v-if="ticker"
                :value="ticker.value"
                :decimals="ticker.decimals"
                :suffix="ticker.suffix"
            />
            <template v-else>{{ value }}</template>
        </p>
        <p
            v-else
            class="text-sm font-medium text-pretty text-muted-foreground"
            :class="compact ? 'py-1' : 'py-1.5'"
        >
            {{ unavailableLabel }}
        </p>

        <p
            v-if="change"
            class="flex flex-wrap items-center gap-x-1.5 gap-y-1 text-xs"
            data-kpi-change
        >
            <span class="kpi-delta" :data-tone="deltaTone">
                <ArrowUpRight
                    v-if="change.direction === 'up'"
                    class="size-3.5"
                    aria-hidden="true"
                />
                <ArrowDownRight
                    v-else-if="change.direction === 'down'"
                    class="size-3.5"
                    aria-hidden="true"
                />
                <Minus v-else class="size-3.5" aria-hidden="true" />
                {{
                    change.direction === 'flat'
                        ? 'Igual'
                        : `${change.direction === 'up' ? '+' : '−'}${formatPercent(change.percent / 100)}`
                }}
                <span v-if="toneWord" class="sr-only">({{ toneWord }})</span>
            </span>
            <span class="text-muted-foreground tabular-nums">
                {{
                    change.direction === 'flat'
                        ? 'que el periodo anterior'
                        : 'vs. periodo anterior'
                }}
                · antes {{ previousText }}
            </span>
        </p>
        <p
            v-else-if="!live && !plain && previous === null && value !== null"
            class="text-xs text-muted-foreground"
        >
            Sin datos del periodo anterior
        </p>
        <p
            v-else-if="!live && !plain && previous === 0 && value !== null"
            class="text-xs text-muted-foreground tabular-nums"
        >
            Antes: {{ previousText }}
        </p>

        <p
            v-if="hint"
            class="text-xs text-pretty break-words hyphens-auto text-muted-foreground"
            data-kpi-hint
        >
            {{ hint }}
        </p>
        <Link
            v-if="href"
            :href="href"
            class="group/kpi-link mt-auto inline-flex items-center gap-1 self-start rounded-sm pt-1 text-xs font-semibold text-primary underline-offset-4 outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring"
            data-kpi-link
        >
            {{ hrefLabel }}
            <ArrowRight
                class="size-3.5 transition-transform duration-(--duration-base) ease-(--ease-spring) group-hover/kpi-link:translate-x-0.5"
                aria-hidden="true"
            />
        </Link>
    </div>
</template>
