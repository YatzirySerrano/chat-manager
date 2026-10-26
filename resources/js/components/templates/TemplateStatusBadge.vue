<script setup lang="ts">
import type { MessageTemplateSummary, TemplateTone } from '@/types';

/**
 * Review status of a template as Meta reports it; the label comes from the
 * backend, so the interface never claims an approval Meta did not confirm.
 */
defineProps<{
    status: MessageTemplateSummary['status'];
}>();

const toneClasses: Record<TemplateTone, string> = {
    success: 'bg-success-soft text-success-soft-foreground',
    primary: 'bg-primary-soft text-primary-soft-foreground',
    warning: 'bg-warning/15 text-amber-700 dark:text-amber-300',
    danger: 'bg-destructive-soft text-destructive-soft-foreground',
    neutral: 'bg-muted text-muted-foreground',
};
</script>

<template>
    <span
        class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap"
        :class="toneClasses[status.tone]"
    >
        {{ status.label }}
    </span>
</template>
