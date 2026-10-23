<script setup lang="ts">
import {
    Check,
    CheckCheck,
    CircleAlert,
    CircleQuestionMark,
    Clock3,
    LoaderCircle,
} from '@lucide/vue';
import { computed } from 'vue';
import {
    DELIVERY_UNKNOWN,
    statusLabel,
} from '@/components/conversations/messageFormat';
import type { MessageStatusValue } from '@/types';

/**
 * Delivery state of an outbound message. Two ticks only appear when Meta
 * reported the delivery, and colored ticks only when it reported the read.
 * A failure of unknown outcome (Meta may have received it) is a warning,
 * not a definitive failure.
 */
const props = defineProps<{
    status: MessageStatusValue;
    errorCode?: string | null;
}>();

const unknownDelivery = computed(
    () => props.status === 'failed' && props.errorCode === DELIVERY_UNKNOWN,
);

const icon = computed(() => {
    if (unknownDelivery.value) {
        return CircleQuestionMark;
    }

    switch (props.status) {
        case 'queued':
            return Clock3;
        case 'sending':
            return LoaderCircle;
        case 'delivered':
        case 'read':
            return CheckCheck;
        case 'failed':
            return CircleAlert;
        default:
            return Check;
    }
});

const tone = computed(() => {
    if (unknownDelivery.value) {
        return 'text-warning';
    }

    switch (props.status) {
        case 'read':
            return 'text-sky-600 dark:text-sky-400';
        case 'failed':
            return 'text-destructive';
        case 'accepted':
            return 'text-muted-foreground/70';
        default:
            return 'text-muted-foreground';
    }
});

const label = computed(() => statusLabel(props.status, props.errorCode));
</script>

<template>
    <span class="inline-flex items-center" :title="label">
        <component
            :is="icon"
            class="size-3.5"
            :class="[tone, status === 'sending' && 'animate-spin']"
            aria-hidden="true"
        />
        <span class="sr-only">{{ label }}</span>
    </span>
</template>
