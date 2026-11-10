<script setup lang="ts">
import { TriangleAlert } from '@lucide/vue';
import { computed, inject } from 'vue';
import {
    ACCEPT_BLOCK,
    noAcceptBlock,
} from '@/components/conversations/acceptBlock';
import { formatDateTime, formatRelative } from '@/lib/format';

/**
 * Why «Aceptar» is disabled: the agent has customers waiting for their
 * answer longer than the tolerance (PendingAttention). It names the oldest
 * ones, each a way straight to that chat, and disappears by itself once
 * they are answered (the list props, this one included, reload with every
 * change).
 */
const emit = defineEmits<{ select: [id: number] }>();

const block = inject(ACCEPT_BLOCK, noAcceptBlock);
const state = computed(() => (block.value?.blocked ? block.value : null));
</script>

<template>
    <div
        v-if="state"
        class="grid gap-2 rounded-lg border border-warning/50 bg-warning-soft p-3 text-sm text-warning-soft-foreground"
        role="status"
        data-accept-block
    >
        <p class="flex gap-2 font-medium">
            <TriangleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <span>{{ state.message }}</span>
        </p>
        <p class="text-xs">
            {{
                state.overdue === 1
                    ? 'Un cliente lleva más de'
                    : `${state.overdue} clientes llevan más de`
            }}
            {{ state.tolerance_minutes }} minutos esperando tu respuesta. Podrás
            aceptar conversaciones nuevas en cuanto les respondas.
        </p>
        <ul
            class="grid gap-1"
            aria-label="Conversaciones que esperan tu respuesta"
        >
            <li v-for="item in state.conversations" :key="item.id">
                <button
                    type="button"
                    class="flex w-full min-w-0 items-center justify-between gap-2 rounded-md bg-background/70 px-2 py-1.5 text-left text-xs text-foreground outline-none hover:bg-background focus-visible:ring-2 focus-visible:ring-ring pointer-coarse:min-h-10"
                    data-accept-block-conversation
                    @click="emit('select', item.id)"
                >
                    <span class="truncate font-medium">{{ item.contact }}</span>
                    <span
                        v-if="item.waiting_since"
                        class="shrink-0 text-muted-foreground"
                        :title="formatDateTime(item.waiting_since)"
                    >
                        espera desde {{ formatRelative(item.waiting_since) }}
                    </span>
                </button>
            </li>
        </ul>
    </div>
</template>
