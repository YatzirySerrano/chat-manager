<script setup lang="ts">
import {
    Check,
    CheckCheck,
    CircleAlert,
    Clock3,
    LoaderCircle,
} from '@lucide/vue';
import { computed } from 'vue';
import { statusLabel } from '@/components/conversations/messageFormat';
import type { MessageStatusValue } from '@/types';

/**
 * Delivery state of an outbound message. Two ticks only appear when Meta
 * reported the delivery, and colored ticks only when it reported the read.
 */
const props = defineProps<{
    status: MessageStatusValue;
}>();

const icon = computed(() => {
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

const label = computed(() => statusLabel(props.status));
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
