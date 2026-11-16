<script setup lang="ts">
import { CornerUpLeft, X } from '@lucide/vue';

/**
 * The message being answered, pinned on top of the composer until it is
 * sent or dismissed. The same strip as the quote it will become inside the
 * bubble.
 */
defineProps<{
    title: string;
    excerpt: string;
    /** Name and hint of the dismiss button. */
    cancelLabel?: string;
}>();

defineEmits<{ cancel: [] }>();
</script>

<template>
    <div class="flex items-center gap-2.5 px-3 pt-3 pb-1 text-sm md:text-xs">
        <span
            class="grid size-7 shrink-0 place-items-center rounded-full accent-gradient shadow-(--shadow-control)"
            aria-hidden="true"
        >
            <CornerUpLeft class="size-3.5" />
        </span>
        <div
            class="min-w-0 flex-1 rounded-[0.8rem] border-l-[3px] border-primary bg-primary-soft/45 px-2.5 py-1.5"
        >
            <p class="truncate font-semibold text-foreground">{{ title }}</p>
            <p class="line-clamp-2 wrap-anywhere text-muted-foreground">
                {{ excerpt }}
            </p>
        </div>
        <button
            type="button"
            class="grid size-8 shrink-0 place-items-center rounded-full text-muted-foreground transition-[color,background-color,scale] duration-(--duration-fast) outline-none hover:rotate-90 hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring active:scale-90 motion-safe:transition-[color,background-color,scale,rotate] pointer-coarse:size-10"
            :aria-label="cancelLabel ?? 'Quitar la cita'"
            :title="cancelLabel ?? 'Quitar la cita'"
            @click="$emit('cancel')"
        >
            <X class="size-4" aria-hidden="true" />
        </button>
    </div>
</template>
