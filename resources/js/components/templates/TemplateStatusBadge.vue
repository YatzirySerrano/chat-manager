<script setup lang="ts">
import type { MessageTemplateSummary, TemplateTone } from '@/types';

/**
 * Review status of a template as Meta reports it; the label comes from the
 * backend, so the interface never claims an approval Meta did not confirm.
 * The dot repeats the tone; the label always says it in words.
 */
defineProps<{
    status: MessageTemplateSummary['status'];
}>();

const toneClasses: Record<TemplateTone, string> = {
    success: 'bg-success-soft text-success-soft-foreground',
    primary: 'bg-primary-soft text-primary-soft-foreground',
    warning: 'bg-warning-soft text-warning-soft-foreground',
    danger: 'bg-destructive-soft text-destructive-soft-foreground',
    neutral: 'bg-muted text-muted-foreground',
};

const dotClasses: Record<TemplateTone, string> = {
    success: 'bg-success',
    primary: 'bg-primary',
    warning: 'bg-warning',
    danger: 'bg-destructive',
    neutral: 'bg-muted-foreground/60',
};
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap"
        :class="toneClasses[status.tone]"
    >
        <span
            class="size-1.5 shrink-0 rounded-full"
            :class="dotClasses[status.tone]"
            aria-hidden="true"
        />
        {{ status.label }}
    </span>
</template>
