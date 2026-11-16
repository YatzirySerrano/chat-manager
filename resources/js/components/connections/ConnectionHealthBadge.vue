<script setup lang="ts">
import type { ConnectionHealthDescriptor, ConnectionTone } from '@/types';
import '@/components/admin/admin.css';

/**
 * Derived health of a connection. The label comes from the backend
 * (ConnectionHealth), so the interface never invents a state.
 *
 * A healthy connection breathes: its dot sends a slow, soft ring (live-dot),
 * the only looping motion of the screen (off with reduced motion). Every
 * other tone stays still, so a problem never competes with it for attention.
 */
defineProps<{
    health: ConnectionHealthDescriptor;
}>();

const tones: Record<ConnectionTone, string> = {
    success: 'success',
    primary: 'primary',
    warning: 'warning',
    danger: 'danger',
    neutral: 'muted',
};
</script>

<template>
    <span
        class="admin-pill"
        :data-tone="tones[health.tone] ?? 'muted'"
        :data-health="health.value"
        :title="health.description"
    >
        <span
            class="admin-pill-dot"
            :class="health.tone === 'success' && 'live-dot'"
            aria-hidden="true"
        />
        {{ health.label }}
    </span>
</template>
