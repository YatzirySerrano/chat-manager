<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';
import './directory.css';

/**
 * The header of a record's own page (a contact, a department, a template):
 * a wide floating surface with a soft wash of the palette color, the large
 * avatar on the left and the page header (its <h1>, badges and actions) next
 * to it. On phones the avatar sits above the text.
 *
 * Slots: `avatar`, default (usually PageHeader) and `footer` (a strip of
 * facts under a hairline: channels, counts, dates).
 */
const props = defineProps<{ class?: HTMLAttributes['class'] }>();
</script>

<template>
    <section :class="cn('dir-hero', props.class)">
        <div
            class="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:gap-5 sm:p-5"
        >
            <div v-if="$slots.avatar" class="shrink-0">
                <slot name="avatar" />
            </div>
            <div class="min-w-0 flex-1">
                <slot />
            </div>
        </div>
        <div
            v-if="$slots.footer"
            class="flex flex-wrap items-center gap-2 border-t px-4 py-2.5 sm:px-5"
        >
            <slot name="footer" />
        </div>
    </section>
</template>
