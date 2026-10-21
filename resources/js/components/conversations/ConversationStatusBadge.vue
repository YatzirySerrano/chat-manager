<script setup lang="ts">
import type { ConversationStatusValue } from '@/types';

defineProps<{
    status: ConversationStatusValue;
    label: string;
}>();

const tones: Record<ConversationStatusValue, { badge: string; dot: string }> = {
    pending: { badge: 'bg-warning/10 text-warning', dot: 'bg-warning' },
    open: { badge: 'bg-success/10 text-success', dot: 'bg-success' },
    on_hold: {
        badge: 'bg-primary-soft text-primary-soft-foreground',
        dot: 'bg-primary',
    },
    closed: {
        badge: 'bg-muted text-muted-foreground',
        dot: 'bg-muted-foreground/50',
    },
};
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap"
        :class="tones[status].badge"
    >
        <span
            class="size-1.5 rounded-full"
            :class="tones[status].dot"
            aria-hidden="true"
        />
        {{ label }}
    </span>
</template>
