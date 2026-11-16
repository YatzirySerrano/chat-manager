<script setup lang="ts">
import { CircleCheck } from '@lucide/vue';
import { computed } from 'vue';
import { formatDateTime } from '@/lib/format';

/**
 * «Completada»: the conversation was closed as «Solicitud completada»
 * (`completed_at`), so the customer's next message starts a new request.
 * Text and icon on the soft success pair, never color alone. Meant for the
 * list rows and any header that shows the conversation's state.
 */
const props = defineProps<{
    /** When it was completed (ISO 8601), for the tooltip. */
    at?: string | null;
}>();

const title = computed(() =>
    props.at
        ? `Cerrada como solicitud completada el ${formatDateTime(props.at)}`
        : 'Cerrada como solicitud completada',
);
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded-full border border-success/40 bg-success-soft px-2 py-0.5 text-xs font-semibold whitespace-nowrap text-success-soft-foreground"
        :title="title"
        data-completed-badge
    >
        <CircleCheck class="size-3 shrink-0" aria-hidden="true" />
        Completada
    </span>
</template>
