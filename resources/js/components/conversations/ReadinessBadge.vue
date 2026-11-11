<script setup lang="ts">
import { CircleCheck, CircleHelp } from '@lucide/vue';
import type { ConversationReadinessValue } from '@/types';

/**
 * Preparation state of a request, with icon and text (never color alone):
 * «Necesita información» or «Lista para atención». It is independent of
 * the conversation status.
 */
defineProps<{
    value: ConversationReadinessValue;
    label: string;
}>();
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium whitespace-nowrap"
        :class="
            value === 'needs_info'
                ? 'border-info/40 bg-info-soft text-info-soft-foreground'
                : 'border-success/40 bg-success-soft text-success-soft-foreground'
        "
        :data-readiness="value"
    >
        <component
            :is="value === 'needs_info' ? CircleHelp : CircleCheck"
            class="size-3 shrink-0"
            aria-hidden="true"
        />
        {{ label }}
    </span>
</template>
