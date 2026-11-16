<script setup lang="ts">
/**
 * A labelled line across the history: where the unread messages start, or
 * where an imported history ends. `accent` is for what asks for attention
 * (new messages); the quiet tone is for context.
 */
withDefaults(
    defineProps<{
        label: string;
        accent?: boolean;
        /** Personal chat colors: the label keeps the theme's background. */
        custom?: boolean;
    }>(),
    { accent: false, custom: false },
);
</script>

<template>
    <div class="flex items-center gap-3 text-[0.6875rem] font-semibold">
        <span
            class="h-px flex-1"
            :class="
                accent
                    ? 'bg-linear-to-r from-transparent to-primary/50'
                    : 'bg-linear-to-r from-transparent to-border-strong'
            "
            aria-hidden="true"
        />
        <span
            class="rounded-full px-2.5 py-0.5"
            :class="
                accent
                    ? 'bg-primary-soft text-primary-soft-foreground ring-1 ring-primary/25'
                    : ['text-muted-foreground', custom && 'bg-background']
            "
            >{{ label }}</span
        >
        <span
            class="h-px flex-1"
            :class="
                accent
                    ? 'bg-linear-to-l from-transparent to-primary/50'
                    : 'bg-linear-to-l from-transparent to-border-strong'
            "
            aria-hidden="true"
        />
    </div>
</template>
