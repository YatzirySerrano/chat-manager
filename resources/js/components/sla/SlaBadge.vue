<script setup lang="ts">
import { CircleAlert, CircleCheck, Timer } from '@lucide/vue';
import { computed, ref } from 'vue';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { useServerNow } from '@/composables/useServerNow';
import { formatDateTime } from '@/lib/format';
import {
    formatSlaSpan,
    formatSlaTarget,
    SLA_STATE_CLASS,
    SLA_STATE_LABEL,
    slaSecondsElapsed,
    slaSecondsLeft,
    slaState,
    slaTimeText,
} from '@/lib/sla';
import { cn } from '@/lib/utils';
import type { ConversationSla } from '@/types';

/**
 * Traffic light of a request against its response objective, with icon and
 * text (never color alone): «En tiempo», «Por vencer», «Vencida», and the
 * time left or overdue. It is classified here from the frozen instants of
 * the response cycle against the shared server clock, so it moves by itself
 * without any request.
 *
 * - `compact`: for list rows and the chat header. Not interactive (both
 *   live inside a button); the full detail is its accessible name and
 *   native tooltip.
 * - `badge` (default): opens the detail on hover, focus or click.
 * - `detail`: the badge plus the detail always visible (details panel).
 *
 * `announce` adds a polite live region that only holds the state, so a
 * screen reader hears «Por vencer» / «Vencida» when it changes and never
 * the countdown ticking.
 */
const props = withDefaults(
    defineProps<{
        sla: ConversationSla;
        variant?: 'compact' | 'badge' | 'detail';
        announce?: boolean;
    }>(),
    { variant: 'badge', announce: false },
);

const now = useServerNow();

const state = computed(() => slaState(props.sla, now.value));
const label = computed(() => SLA_STATE_LABEL[state.value]);
const icon = computed(
    () =>
        ({ on_time: CircleCheck, warning: Timer, breached: CircleAlert })[
            state.value
        ],
);
const timeText = computed(() => slaTimeText(props.sla, now.value));
const left = computed(() => slaSecondsLeft(props.sla, now.value));
const elapsed = computed(() => slaSecondsElapsed(props.sla, now.value));

const objective = computed(
    () =>
        `${props.sla.kind === 'first_response' ? 'Primera respuesta' : 'Respuesta'} en ${formatSlaTarget(props.sla.target_seconds)}`,
);
const counting = computed(() =>
    props.sla.business_hours_only
        ? 'Solo cuenta el horario de atención'
        : 'Cuenta el tiempo corrido (24 horas)',
);

const rows = computed(() => [
    { term: 'Objetivo', value: objective.value },
    { term: 'Esperando desde hace', value: formatSlaSpan(elapsed.value) },
    left.value !== null && left.value >= 0
        ? { term: 'Tiempo restante', value: formatSlaSpan(left.value) }
        : {
              term: 'Tiempo vencido',
              value: formatSlaSpan(-(left.value ?? 0)),
          },
    { term: 'Fecha límite', value: formatDateTime(props.sla.due_at) },
    { term: 'Cómo se cuenta', value: counting.value },
]);

/** One sentence with everything, for the compact variant. */
const summary = computed(
    () =>
        `SLA: ${label.value}. ${timeText.value}. ${objective.value}. Fecha límite: ${formatDateTime(props.sla.due_at)}. ${counting.value}.`,
);

const badgeClass = computed(() =>
    cn(
        'inline-flex max-w-full shrink-0 items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium whitespace-nowrap tabular-nums',
        SLA_STATE_CLASS[state.value],
    ),
);

const open = ref(false);
let closeTimer: ReturnType<typeof setTimeout> | null = null;

function show(): void {
    if (closeTimer !== null) {
        clearTimeout(closeTimer);
        closeTimer = null;
    }

    open.value = true;
}

function hideSoon(): void {
    closeTimer = setTimeout(() => (open.value = false), 150);
}
</script>

<template>
    <span
        v-if="variant === 'compact'"
        :class="badgeClass"
        :title="summary"
        :data-sla-state="state"
        data-sla-badge
    >
        <component :is="icon" class="size-3.5 shrink-0" aria-hidden="true" />
        <span aria-hidden="true">{{ label }}</span>
        <span class="hidden font-normal sm:inline" aria-hidden="true"
            >· {{ timeText.replace(/^Vence en |^Venció hace /, '') }}</span
        >
        <span class="sr-only">{{ summary }}</span>
        <span v-if="announce" class="sr-only" aria-live="polite"
            >SLA: {{ label }}</span
        >
    </span>

    <span
        v-else
        class="inline-flex max-w-full flex-col gap-2"
        :data-sla-state="state"
        data-sla-badge
    >
        <span v-if="announce" class="sr-only" aria-live="polite"
            >SLA: {{ label }}</span
        >

        <Popover v-if="variant === 'badge'" v-model:open="open">
            <PopoverTrigger as-child>
                <button
                    type="button"
                    :class="
                        cn(
                            badgeClass,
                            'cursor-default focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                        )
                    "
                    :aria-label="`SLA: ${label}. ${timeText}. Ver detalle`"
                    @mouseenter="show"
                    @mouseleave="hideSoon"
                    @focus="show"
                    @blur="hideSoon"
                >
                    <component
                        :is="icon"
                        class="size-3.5 shrink-0"
                        aria-hidden="true"
                    />
                    <span>{{ label }}</span>
                    <span class="font-normal">· {{ timeText }}</span>
                </button>
            </PopoverTrigger>
            <PopoverContent
                align="start"
                class="w-72 p-3"
                @mouseenter="show"
                @mouseleave="hideSoon"
                @open-auto-focus.prevent
            >
                <p class="text-sm font-semibold">
                    Tiempo de respuesta (SLA): {{ label }}
                </p>
                <dl class="mt-2 grid gap-1.5 text-xs">
                    <div
                        v-for="row in rows"
                        :key="row.term"
                        class="flex justify-between gap-3"
                    >
                        <dt class="text-muted-foreground">{{ row.term }}</dt>
                        <dd class="text-right font-medium tabular-nums">
                            {{ row.value }}
                        </dd>
                    </div>
                </dl>
                <p class="mt-2 text-xs text-muted-foreground">
                    El plazo se fijó cuando el cliente escribió y no cambia
                    aunque la conversación se reasigne o cambien las reglas.
                </p>
            </PopoverContent>
        </Popover>

        <template v-else>
            <span :class="cn(badgeClass, 'self-start')">
                <component
                    :is="icon"
                    class="size-3.5 shrink-0"
                    aria-hidden="true"
                />
                <span>{{ label }}</span>
                <span class="font-normal">· {{ timeText }}</span>
            </span>
            <dl class="grid gap-1.5 text-xs">
                <div
                    v-for="row in rows"
                    :key="row.term"
                    class="flex justify-between gap-3"
                >
                    <dt class="text-muted-foreground">{{ row.term }}</dt>
                    <dd class="text-right font-medium tabular-nums">
                        {{ row.value }}
                    </dd>
                </div>
            </dl>
        </template>
    </span>
</template>
