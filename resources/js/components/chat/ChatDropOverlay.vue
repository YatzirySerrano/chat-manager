<script setup lang="ts">
import { Ban, FileUp } from '@lucide/vue';
import '@/components/chat/chat.css';

/**
 * Shown over the whole chat pane while files are dragged over it: says
 * whether they can be attached here, and what happens when they are
 * dropped. Purely informative (the pane handles the drop).
 */
defineOptions({ inheritAttrs: false });

defineProps<{
    visible: boolean;
    allowed: boolean;
    title: string;
    description: string;
}>();
</script>

<template>
    <Transition name="chat-veil">
        <div
            v-if="visible"
            v-bind="$attrs"
            class="pointer-events-none absolute inset-0 z-30 grid place-items-center drop-veil p-4"
            aria-hidden="true"
        >
            <div
                class="grid w-full max-w-md justify-items-center gap-2 rounded-2xl border-2 border-dashed px-6 py-10 text-center shadow-elevated"
                :class="
                    allowed
                        ? 'border-primary bg-primary-soft/80 text-primary-soft-foreground'
                        : 'border-border-strong bg-card text-muted-foreground'
                "
                data-veil-card
            >
                <span
                    class="grid size-14 place-items-center rounded-full"
                    :class="
                        allowed
                            ? 'bg-primary text-primary-foreground shadow-hover'
                            : 'bg-muted'
                    "
                >
                    <FileUp v-if="allowed" class="size-6" />
                    <Ban v-else class="size-6" />
                </span>
                <p class="font-display text-lg font-semibold">{{ title }}</p>
                <p class="max-w-prose text-sm text-pretty">{{ description }}</p>
            </div>
        </div>
    </Transition>
</template>
