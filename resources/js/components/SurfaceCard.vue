<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

/**
 * White, rounded surface used by every module for tables and sections.
 */
const props = defineProps<{
    title?: string;
    description?: string;
    class?: HTMLAttributes['class'];
    bodyClass?: HTMLAttributes['class'];
}>();
</script>

<template>
    <section
        :class="
            cn(
                'overflow-hidden rounded-xl border bg-card shadow-card',
                props.class,
            )
        "
    >
        <header
            v-if="title || $slots.actions"
            class="flex flex-col gap-3 border-b px-5 py-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <div class="min-w-0">
                <h2 v-if="title" class="text-sm font-semibold">{{ title }}</h2>
                <p v-if="description" class="text-sm text-muted-foreground">
                    {{ description }}
                </p>
            </div>
            <div
                v-if="$slots.actions"
                class="flex flex-wrap items-center gap-2"
            >
                <slot name="actions" />
            </div>
        </header>
        <div :class="cn(props.bodyClass)">
            <slot />
        </div>
    </section>
</template>
