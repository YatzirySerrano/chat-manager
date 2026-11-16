<script setup lang="ts">
import { computed } from 'vue';
import { isLiveState } from '@/lib/whatsappWeb';
import type { WhatsAppWebState, WhatsAppWebTone } from '@/types';
import '@/components/admin/admin.css';

/**
 * Real state of a WhatsApp Web (QR) session. Label and tone come from the
 * server (the interface never invents a state). A connected session
 * breathes like any healthy connection (live-dot); a state in transit
 * (waiting for the scan, pairing, reconnecting) blinks softly so it reads
 * as "in progress".
 */
const props = defineProps<{
    state: WhatsAppWebState;
    label: string;
    tone: WhatsAppWebTone;
}>();

const tones: Record<WhatsAppWebTone, string> = {
    success: 'success',
    info: 'info',
    warning: 'warning',
    danger: 'danger',
    neutral: 'muted',
};

const inTransit = computed(() => isLiveState(props.state));
</script>

<template>
    <span
        class="admin-pill"
        :data-tone="tones[tone] ?? 'muted'"
        :data-state="state"
    >
        <span
            class="admin-pill-dot"
            :class="[
                state === 'connected' && 'live-dot',
                inTransit && 'animate-pulse',
            ]"
            aria-hidden="true"
        />
        {{ label }}
    </span>
</template>
