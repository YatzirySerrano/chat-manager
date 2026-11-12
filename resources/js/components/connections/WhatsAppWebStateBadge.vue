<script setup lang="ts">
import { computed } from 'vue';
import { isLiveState } from '@/lib/whatsappWeb';
import type { WhatsAppWebState, WhatsAppWebTone } from '@/types';

/**
 * Real state of a WhatsApp Web (QR) session. Label and tone come from the
 * server (the interface never invents a state). A connected session
 * breathes like any healthy connection; a state in transit (waiting for the
 * scan, pairing, reconnecting) blinks softly so it reads as "in progress".
 */
const props = defineProps<{
    state: WhatsAppWebState;
    label: string;
    tone: WhatsAppWebTone;
}>();

const toneClasses: Record<WhatsAppWebTone, { badge: string; dot: string }> = {
    success: {
        badge: 'bg-success-soft text-success-soft-foreground',
        dot: 'bg-success',
    },
    info: {
        badge: 'bg-info-soft text-info-soft-foreground',
        dot: 'bg-info',
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

const style = computed(() => toneClasses[props.tone] ?? toneClasses.neutral);
const inTransit = computed(() => isLiveState(props.state));
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap"
        :class="style.badge"
        :data-state="state"
    >
        <span class="relative flex size-1.5 shrink-0" aria-hidden="true">
            <span
                v-if="state === 'connected'"
                class="absolute inline-flex size-full animate-ping rounded-full bg-success opacity-60 [animation-duration:2.4s]"
            />
            <span
                class="relative inline-flex size-1.5 rounded-full"
                :class="[style.dot, inTransit && 'animate-pulse']"
            />
        </span>
        {{ label }}
    </span>
</template>
