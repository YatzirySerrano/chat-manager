<script setup lang="ts">
import { computed } from 'vue';
import type { HealthStatusValue } from '@/types/system';

/**
 * Status of a health check, queue or the whole system. Labels are fixed
 * here because the four statuses are a closed set (HealthStatus enum).
 */
const props = defineProps<{
    status: HealthStatusValue;
    label?: string;
    /** A healthy status breathes (slow, soft ring); off with reduced motion. */
    pulse?: boolean;
}>();

const styles: Record<
    HealthStatusValue,
    { badge: string; dot: string; label: string }
> = {
    ok: {
        badge: 'bg-success-soft text-success-soft-foreground',
        dot: 'bg-success',
        label: 'Correcto',
    },
    warning: {
        badge: 'bg-warning-soft text-warning-soft-foreground',
        dot: 'bg-warning',
        label: 'Atención',
    },
    fail: {
        badge: 'bg-destructive-soft text-destructive-soft-foreground',
        dot: 'bg-destructive',
        label: 'Falla',
    },
    skipped: {
        badge: 'bg-muted text-muted-foreground',
        dot: 'bg-muted-foreground/50',
        label: 'No aplica',
    },
};

const style = computed(() => styles[props.status]);
</script>

<template>
    <span
        class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium"
        :class="style.badge"
    >
        <span class="relative flex size-1.5 shrink-0" aria-hidden="true">
            <span
                v-if="pulse && status === 'ok'"
                class="absolute inline-flex size-full animate-ping rounded-full bg-success opacity-60 [animation-duration:2.4s]"
            />
            <span
                class="relative inline-flex size-1.5 rounded-full"
                :class="style.dot"
            />
        </span>
        {{ label ?? style.label }}
    </span>
</template>
