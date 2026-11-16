<script setup lang="ts">
import { ArrowDown } from '@lucide/vue';
import { computed } from 'vue';
import '@/components/chat/chat.css';

/**
 * Floating «Ir al final» over the history. With messages that arrived below
 * the reading position it becomes a pill that counts them; the count pops
 * each time it changes. Rises in and out (chat-float), still with reduced
 * motion only as a fade.
 */
const props = withDefaults(
    defineProps<{
        visible: boolean;
        /** Messages that arrived below what is being read. */
        count?: number;
        /** Very short windows: a smaller button closer to the corner. */
        compact?: boolean;
    }>(),
    { count: 0, compact: false },
);

defineEmits<{ click: [] }>();

const label = computed(() =>
    props.count > 0
        ? `${props.count} ${props.count === 1 ? 'mensaje nuevo' : 'mensajes nuevos'}, ir al final`
        : 'Ir al final',
);
</script>

<template>
    <Transition name="chat-float">
        <button
            v-if="visible"
            type="button"
            class="absolute z-20 inline-flex items-center gap-1.5 rounded-full border bg-popover text-sm font-medium text-foreground shadow-(--shadow-popover) transition-[background-color,padding] duration-(--duration-fast) outline-none hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring/50"
            :class="[
                compact
                    ? 'right-2 bottom-2 h-7 px-2'
                    : 'right-4 bottom-4 h-10 px-3 pointer-coarse:h-11',
                count === 0 &&
                    (compact
                        ? 'w-7 justify-center px-0'
                        : 'w-10 justify-center px-0 pointer-coarse:w-11'),
            ]"
            :aria-label="label"
            :title="count > 0 ? 'Ver los mensajes nuevos' : 'Ir al final'"
            data-chat-jump
            @click="$emit('click')"
        >
            <span
                v-if="count > 0"
                :key="count"
                class="grid h-5 min-w-5 place-items-center rounded-full bg-primary px-1.5 text-xs font-semibold text-primary-foreground tabular-nums motion-safe:bump"
                >{{ count > 99 ? '99+' : count }}</span
            >
            <span v-if="count > 0 && !compact">{{
                count === 1 ? 'nuevo' : 'nuevos'
            }}</span>
            <ArrowDown class="size-4" aria-hidden="true" />
        </button>
    </Transition>
</template>
