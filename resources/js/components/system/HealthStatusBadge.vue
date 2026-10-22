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
}>();

const styles: Record<
    HealthStatusValue,
    { badge: string; dot: string; label: string }
> = {
    ok: {
        badge: 'bg-success/10 text-success',
        dot: 'bg-success',
        label: 'Correcto',
    },
    warning: {
        badge: 'bg-warning/15 text-amber-700 dark:text-amber-300',
        dot: 'bg-warning',
        label: 'Atención',
    },
    fail: {
        badge: 'bg-destructive/10 text-destructive',
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
        <span
            class="size-1.5 rounded-full"
            :class="style.dot"
            aria-hidden="true"
        />
        {{ label ?? style.label }}
    </span>
</template>
