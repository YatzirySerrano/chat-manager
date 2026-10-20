<script setup lang="ts">
import type { ConnectionHealthDescriptor, ConnectionTone } from '@/types';

/**
 * Derived health of a connection. The label comes from the backend
 * (ConnectionHealth), so the interface never invents a state.
 */
defineProps<{
    health: ConnectionHealthDescriptor;
}>();

const toneClasses: Record<ConnectionTone, { badge: string; dot: string }> = {
    success: { badge: 'bg-success/10 text-success', dot: 'bg-success' },
    primary: {
        badge: 'bg-primary-soft text-primary-soft-foreground',
        dot: 'bg-primary',
    },
    warning: {
        badge: 'bg-warning/15 text-amber-700 dark:text-amber-300',
        dot: 'bg-warning',
    },
    danger: {
        badge: 'bg-destructive/10 text-destructive',
        dot: 'bg-destructive',
    },
    neutral: {
        badge: 'bg-muted text-muted-foreground',
        dot: 'bg-muted-foreground/50',
    },
};
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap"
        :class="toneClasses[health.tone].badge"
        :title="health.description"
    >
        <span
            class="size-1.5 rounded-full"
            :class="toneClasses[health.tone].dot"
            aria-hidden="true"
        />
        {{ health.label }}
    </span>
</template>
