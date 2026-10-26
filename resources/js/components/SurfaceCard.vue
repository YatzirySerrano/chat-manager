<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

/**
 * Card surface used by every module for tables, lists and settings
 * sections. Wide tables scroll inside it, never the page.
 *
 * Slots: `actions` (header, right side), default (body) and `footer`
 * (separated by a top border, e.g. the section's save button).
 */
const props = withDefaults(
    defineProps<{
        title?: string;
        description?: string;
        as?: 'section' | 'div';
        class?: HTMLAttributes['class'];
        bodyClass?: HTMLAttributes['class'];
    }>(),
    {
        title: undefined,
        description: undefined,
        as: 'section',
        class: undefined,
        bodyClass: undefined,
    },
);
</script>

<template>
    <component
        :is="props.as"
        :class="
            cn(
                'overflow-hidden rounded-xl border bg-card shadow-card',
                props.class,
            )
        "
    >
        <header
            v-if="title || $slots.actions"
            class="flex flex-col gap-3 border-b px-4 py-3.5 sm:flex-row sm:items-center sm:justify-between sm:px-5 sm:py-4"
        >
            <div class="min-w-0 space-y-0.5">
                <h2 v-if="title" class="text-base font-semibold">
                    {{ title }}
                </h2>
                <p
                    v-if="description"
                    class="text-sm text-pretty text-muted-foreground"
                >
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
        <footer
            v-if="$slots.footer"
            class="flex flex-wrap items-center justify-end gap-2 border-t px-4 py-3 sm:px-5"
        >
            <slot name="footer" />
        </footer>
    </component>
</template>
