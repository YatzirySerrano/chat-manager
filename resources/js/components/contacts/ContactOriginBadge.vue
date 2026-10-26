<script setup lang="ts">
import { BadgeCheck, CircleQuestionMark, Hand } from '@lucide/vue';
import { computed } from 'vue';
import type { ContactOriginSummary } from '@/types';

/**
 * Compact indicator of the current origin of a contact. Without evidence it
 * says "No identificado"; it never assumes an "organic" origin.
 */
const props = defineProps<{
    origin: ContactOriginSummary | null | undefined;
}>();

const unidentified = computed(
    () => !props.origin || props.origin.origin_type === 'unknown',
);
const confirmed = computed(
    () => props.origin?.reliability === 'provider_confirmed',
);
</script>

<template>
    <span
        v-if="unidentified"
        class="inline-flex items-center gap-1 text-xs text-muted-foreground"
    >
        <CircleQuestionMark class="size-3.5" aria-hidden="true" />
        No identificado
    </span>
    <span
        v-else
        class="inline-flex max-w-44 items-center gap-1 text-xs"
        :title="
            confirmed
                ? `${origin?.origin_label} (confirmado por el proveedor)`
                : `${origin?.origin_label} (declarado manualmente)`
        "
    >
        <BadgeCheck
            v-if="confirmed"
            class="size-3.5 shrink-0 text-success"
            aria-label="Confirmado por Meta"
        />
        <Hand
            v-else
            class="size-3.5 shrink-0 text-muted-foreground"
            aria-label="Declarado manualmente"
        />
        <span class="truncate">{{ origin?.origin_label }}</span>
    </span>
</template>
