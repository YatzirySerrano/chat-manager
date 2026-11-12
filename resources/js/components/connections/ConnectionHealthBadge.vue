<script setup lang="ts">
import type { ConnectionHealthDescriptor, ConnectionTone } from '@/types';

/**
 * Derived health of a connection. The label comes from the backend
 * (ConnectionHealth), so the interface never invents a state.
 *
 * A healthy connection breathes: its dot sends a slow, soft ring, the only
 * looping motion of the screen (off with reduced motion). Every other tone
 * stays still, so a problem never competes with it for attention.
 */
defineProps<{
    health: ConnectionHealthDescriptor;
}>();

const toneClasses: Record<ConnectionTone, { badge: string; dot: string }> = {
    success: {
        badge: 'bg-success-soft text-success-soft-foreground',
        dot: 'bg-success',
    },
    primary: {
        badge: 'bg-primary-soft text-primary-soft-foreground',
        dot: 'bg-primary',
    },
    warning: {
        badge: 'bg-warning-soft text-warning-soft-foreground',
        dot: 'bg-warning',
    },
    danger: {
        badge: 'bg-destructive-soft text-destructive-soft-foreground',
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
        <span class="relative flex size-1.5 shrink-0" aria-hidden="true">
            <span
                v-if="health.tone === 'success'"
                class="absolute inline-flex size-full animate-ping rounded-full bg-success opacity-60 [animation-duration:2.4s]"
            />
            <span
                class="relative inline-flex size-1.5 rounded-full"
                :class="toneClasses[health.tone].dot"
            />
        </span>
        {{ health.label }}
    </span>
</template>
