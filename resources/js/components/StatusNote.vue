<script setup lang="ts">
import { CircleCheck, Info, OctagonAlert, TriangleAlert } from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import { computed } from 'vue';
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

type StatusNoteTone = 'info' | 'success' | 'warning' | 'danger';

/**
 * Inline notice that communicates a state with icon + text (never colour
 * alone). Replaces ad-hoc `border-warning/40 bg-warning/10` boxes.
 *
 * A note is plain content by default: notes present when the page opens
 * are read in order like any other text and may hold links. Only a note
 * that appears or changes in response to something the person just did
 * sets `live`: `polite` is announced as a status, `assertive` as an alert
 * (keep those free of links and buttons).
 */
const props = withDefaults(
    defineProps<{
        tone?: StatusNoteTone;
        title?: string;
        icon?: LucideIcon;
        live?: 'polite' | 'assertive';
        class?: HTMLAttributes['class'];
    }>(),
    {
        tone: 'info',
        title: undefined,
        icon: undefined,
        live: undefined,
        class: undefined,
    },
);

const defaultIcons: Record<StatusNoteTone, LucideIcon> = {
    info: Info,
    success: CircleCheck,
    warning: TriangleAlert,
    danger: OctagonAlert,
};

const toneClasses: Record<StatusNoteTone, string> = {
    info: 'bg-info-soft text-info-soft-foreground',
    success: 'bg-success-soft text-success-soft-foreground',
    warning: 'bg-warning-soft text-warning-soft-foreground',
    danger: 'bg-destructive-soft text-destructive-soft-foreground',
};

const liveRole = computed<'status' | 'alert' | undefined>(() => {
    if (props.live === 'assertive') {
        return 'alert';
    }

    return props.live === 'polite' ? 'status' : undefined;
});

const displayedIcon = computed<LucideIcon>(
    () => props.icon ?? defaultIcons[props.tone],
);
</script>

<template>
    <div
        :role="liveRole"
        :class="
            cn(
                'flex min-w-0 items-start gap-2.5 rounded-lg px-3.5 py-3 text-sm',
                toneClasses[tone],
                props.class,
            )
        "
    >
        <component
            :is="displayedIcon"
            class="mt-0.5 size-4 shrink-0"
            aria-hidden="true"
        />
        <div class="min-w-0 flex-1 space-y-0.5 text-pretty">
            <p v-if="title" class="font-semibold">{{ title }}</p>
            <div v-if="$slots.default" :class="title && 'opacity-90'">
                <slot />
            </div>
        </div>
        <div v-if="$slots.actions" class="shrink-0 self-center">
            <slot name="actions" />
        </div>
    </div>
</template>
