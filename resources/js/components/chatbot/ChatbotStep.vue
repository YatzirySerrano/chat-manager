<script setup lang="ts">
/**
 * One numbered step of the welcome menu editor: a big number, a short
 * title, one line saying what it is for, and its fields.
 */
defineProps<{
    id: string;
    number: number;
    title: string;
    hint?: string;
}>();
</script>

<template>
    <section
        class="grid gap-4 rounded-xl border bg-card p-4 sm:p-5"
        :aria-labelledby="`${id}-title`"
    >
        <header class="flex items-start gap-3">
            <span
                class="grid size-8 shrink-0 place-items-center rounded-full bg-primary font-display text-sm font-semibold text-primary-foreground tabular-nums"
                aria-hidden="true"
                >{{ number }}</span
            >
            <div class="grid min-w-0 flex-1 gap-0.5">
                <h3
                    :id="`${id}-title`"
                    class="font-display text-base font-semibold"
                >
                    <span class="sr-only">Paso {{ number }}: </span>{{ title }}
                </h3>
                <p v-if="hint" class="text-sm text-muted-foreground">
                    {{ hint }}
                </p>
            </div>
            <slot name="aside" />
        </header>
        <slot />
    </section>
</template>
