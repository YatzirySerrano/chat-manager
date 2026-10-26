<script setup lang="ts">
import { computed } from 'vue';
import type { ConversationStatusValue } from '@/types';

const props = defineProps<{
    status: ConversationStatusValue;
    label: string;
}>();

type Tone = { badge: string; dot: string };

const tones: Record<ConversationStatusValue, Tone> = {
    pending: { badge: 'bg-warning/10 text-warning', dot: 'bg-warning' },
    open: { badge: 'bg-success/10 text-success', dot: 'bg-success' },
    /* Retired state: only old conversations not migrated yet. */
    on_hold: {
        badge: 'bg-muted text-muted-foreground',
        dot: 'bg-primary',
    },
    closed: {
        badge: 'bg-muted text-muted-foreground',
        dot: 'bg-muted-foreground/50',
    },
};

/* Unknown values (a newer server) never break the badge. */
const tone = computed<Tone>(() => tones[props.status] ?? tones.closed);
const text = computed(() =>
    props.status === 'on_hold' ? 'En espera (estado ya no usado)' : props.label,
);
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap"
        :class="tone.badge"
    >
        <span
            class="size-1.5 rounded-full"
            :class="tone.dot"
            aria-hidden="true"
        />
        {{ text }}
    </span>
</template>
