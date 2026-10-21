<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

/**
 * Compact card of one record for the "Tarjetas" view of a list: identifier,
 * key information, status and the same actions as the table row.
 *
 * Slots: `leading` (avatar or icon), `title`, `subtitle`, `badges`, default
 * (key information), `meta` (footer metadata) and `actions`.
 */
const props = defineProps<{
    /** Inactive or archived records are rendered subdued. */
    muted?: boolean;
    /** Briefly highlights a record that was just created or edited. */
    highlighted?: boolean;
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <article
        :class="
            cn(
                'flex h-full min-w-0 flex-col gap-3 rounded-xl border bg-card p-4 shadow-card transition-colors duration-700',
                muted && 'bg-muted/30',
                highlighted && 'border-primary/40 bg-primary-soft/60',
                props.class,
            )
        "
    >
        <header class="flex min-w-0 items-start gap-3">
            <slot name="leading" />
            <div class="min-w-0 flex-1 space-y-0.5">
                <div class="min-w-0 text-sm font-medium text-foreground">
                    <slot name="title" />
                </div>
                <div
                    v-if="$slots.subtitle"
                    class="min-w-0 truncate text-xs text-muted-foreground"
                >
                    <slot name="subtitle" />
                </div>
            </div>
        </header>

        <div v-if="$slots.badges" class="flex flex-wrap items-center gap-1.5">
            <slot name="badges" />
        </div>

        <div v-if="$slots.default" class="min-w-0 space-y-2 text-sm">
            <slot />
        </div>

        <footer
            v-if="$slots.meta || $slots.actions"
            class="mt-auto flex min-w-0 items-center justify-between gap-2 border-t pt-2"
        >
            <div
                class="flex min-w-0 flex-1 flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground"
            >
                <slot name="meta" />
            </div>
            <slot name="actions" />
        </footer>
    </article>
</template>
