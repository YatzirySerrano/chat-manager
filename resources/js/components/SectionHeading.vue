<script setup lang="ts">
/**
 * Heading of a page section that is not inside a card (Dashboard, Reportes):
 * an h2 with an optional description and an `actions` slot.
 */
withDefaults(
    defineProps<{
        title: string;
        description?: string;
        id?: string;
    }>(),
    {
        description: undefined,
        id: undefined,
    },
);
</script>

<template>
    <div class="flex flex-wrap items-end justify-between gap-x-4 gap-y-2">
        <div class="min-w-0 space-y-0.5">
            <h2 :id="id" class="text-lg font-semibold">{{ title }}</h2>
            <p
                v-if="description"
                class="max-w-prose text-sm text-pretty text-muted-foreground"
            >
                {{ description }}
            </p>
        </div>
        <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
