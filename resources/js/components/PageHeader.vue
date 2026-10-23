<script setup lang="ts">
/**
 * Page title, description and page-level actions.
 *
 * The text block has a 20rem flex basis and grows, so the actions sit next to
 * it only when both fit; otherwise they wrap to their own row below the text
 * instead of squeezing it into a narrow column. The description keeps a
 * reading width and the title breaks long unspaced values (contact names,
 * numbers). On phones every action link or button is at least 44px tall.
 */
defineProps<{
    title: string;
    description?: string;
}>();
</script>

<template>
    <header class="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
        <div class="min-w-0 grow basis-80 space-y-1">
            <slot name="eyebrow" />
            <h1 class="text-2xl font-semibold tracking-tight break-words">
                {{ title }}
            </h1>
            <p
                v-if="description"
                class="max-w-prose text-sm text-pretty text-muted-foreground"
            >
                {{ description }}
            </p>
        </div>
        <div
            v-if="$slots.actions"
            class="flex min-w-0 flex-wrap items-center gap-2 max-sm:w-full max-sm:[&_a]:min-h-11 max-sm:[&_button]:min-h-11"
        >
            <slot name="actions" />
        </div>
    </header>
</template>
