<script setup lang="ts">
import type { LinkComponentBaseProps } from '@inertiajs/core';
import { Link } from '@inertiajs/vue3';
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';
import './directory.css';

/**
 * One row of a directory list: the record floats on the panel, lifts on
 * hover and shows an accent bar at its edge; its actions appear on hover or
 * keyboard focus (always visible on touch screens).
 *
 * Slots: `leading` (avatar), `title`, `subtitle`, `badges` (chips under the
 * title), `aside` (secondary columns on wide screens, e.g. usage or the last
 * interaction), `actions` (RecordActions) and default (extra content under
 * the title, e.g. a preview).
 *
 * With `href` the title becomes a stretched link that covers the row, while
 * every control inside keeps its own behavior.
 */
const props = defineProps<{
    href?: LinkComponentBaseProps['href'];
    /** Accessible name of the row link; defaults to the title text. */
    label?: string;
    muted?: boolean;
    highlighted?: boolean;
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <li
        :class="
            cn(
                'dir-row flex min-w-0 items-center gap-3 py-2.5 pr-2 pl-3.5 sm:gap-4 sm:pr-3',
                props.class,
            )
        "
        :data-muted="muted ? '' : undefined"
        :data-highlighted="highlighted ? '' : undefined"
    >
        <slot name="leading" />
        <div class="min-w-0 flex-1">
            <div
                class="flex min-w-0 items-center gap-2 font-display text-[0.95rem] leading-snug font-semibold text-foreground"
                :class="muted && 'text-muted-foreground'"
            >
                <Link
                    v-if="href"
                    :href="href"
                    :aria-label="label"
                    class="dir-row-link min-w-0 truncate rounded-sm outline-none hover:underline hover:underline-offset-4"
                >
                    <slot name="title" />
                </Link>
                <div v-else class="min-w-0 truncate">
                    <slot name="title" />
                </div>
            </div>
            <div
                v-if="$slots.subtitle"
                class="mt-0.5 min-w-0 truncate text-[0.8125rem] text-muted-foreground"
            >
                <slot name="subtitle" />
            </div>
            <div
                v-if="$slots.badges"
                class="mt-1.5 flex min-w-0 flex-wrap items-center gap-1"
            >
                <slot name="badges" />
            </div>
            <slot />
        </div>
        <div
            v-if="$slots.aside"
            class="dir-aside hidden shrink-0 items-center gap-6 text-sm text-muted-foreground md:flex"
        >
            <slot name="aside" />
        </div>
        <div v-if="$slots.actions" class="dir-actions shrink-0">
            <slot name="actions" />
        </div>
    </li>
</template>
