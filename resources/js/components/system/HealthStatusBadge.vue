<script setup lang="ts">
import { computed } from 'vue';
import StatusPill from '@/components/admin/StatusPill.vue';
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
    { tone: 'success' | 'warning' | 'danger' | 'muted'; label: string }
> = {
    ok: { tone: 'success', label: 'Correcto' },
    warning: { tone: 'warning', label: 'Atención' },
    fail: { tone: 'danger', label: 'Falla' },
    skipped: { tone: 'muted', label: 'No aplica' },
};

const style = computed(() => styles[props.status]);
</script>

<template>
    <StatusPill
        class="shrink-0"
        :label="label ?? style.label"
        :tone="style.tone"
        :live="pulse && status === 'ok'"
        :data-health="status"
    />
</template>
