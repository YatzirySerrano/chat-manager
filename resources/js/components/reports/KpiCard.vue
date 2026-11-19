<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';
import { ArrowDownRight, ArrowRight, ArrowUpRight, Minus } from '@lucide/vue';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { NumberTicker } from '@/components/ui/number-ticker';
import Sparkline from './Sparkline.vue';
import { formatPercent } from '@/composables/useChartTheme';
import './insights.css';

/**
 * A headline figure with its comparison against the previous period.
 *
 * Anatomy: the label (with a small muted icon) on top, the figure in the
 * display face (a count, a grouped count like «1,245» or a percentage like
 * «85 %» counts up to its number; a duration is shown as is), then the
 * change as a colored figure with an arrow (green when it improved, red
 * when it got worse, gray when the direction means nothing) with the
 * previous figure beside it, an optional sparkline (`trend`), the hint and
 * the drill-down link. Calm surface: no gradient, glow or lift.
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
        /** Values of the period for a sparkline under the figure. */
        trend?: number[] | null;
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
        trend: null,
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
            'kpi-card flex min-w-0 flex-col rounded-xl border bg-card shadow-(--shadow-control)',
            compact ? 'gap-1.5 p-4' : 'gap-2 p-5',
        ]"
        :data-tone="props.tone === 'primary' ? undefined : props.tone"
        data-kpi-card
    >
        <!-- In narrow cards «En vivo» wraps below instead of squeezing the label. -->
        <div class="flex min-w-0 flex-wrap items-start gap-x-1.5 gap-y-1">
            <component
                :is="icon"
                v-if="icon"
                class="mt-px size-3.5 shrink-0"
                :class="
                    tone === 'warning'
                        ? 'text-warning'
                        : tone === 'destructive'
                          ? 'text-destructive'
                          : 'text-muted-foreground'
                "
                aria-hidden="true"
            />
            <span
                class="min-w-0 flex-1 basis-16 text-[0.8125rem] font-medium text-pretty break-words hyphens-auto text-muted-foreground"
                data-kpi-label
                >{{ label }}</span
            >
            <span
                v-if="live"
                class="inline-flex shrink-0 items-center gap-1 text-[0.6875rem] font-medium text-muted-foreground"
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
                compact ? 'text-[1.625rem] leading-8' : 'text-3xl leading-9',
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
            class="flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-xs"
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
                vs. {{ previousText }} antes
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

        <Sparkline
            v-if="trend && trend.length > 1"
            class="-mx-0.5 mt-1"
            :values="trend"
            :label="`Tendencia de ${label}`"
        />

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
            class="group/kpi-link mt-auto inline-flex items-center gap-1 self-start rounded-sm pt-0.5 text-xs font-medium text-primary underline-offset-4 outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring"
            data-kpi-link
        >
            {{ hrefLabel }}
            <ArrowRight class="size-3.5" aria-hidden="true" />
        </Link>
    </div>
</template>
