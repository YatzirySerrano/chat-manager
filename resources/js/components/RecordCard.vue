<script setup lang="ts">
import type { LinkComponentBaseProps } from '@inertiajs/core';
import { Link } from '@inertiajs/vue3';
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

/**
 * Compact card of one record for the "Tarjetas" view of a list: identifier,
 * key information, status and the same actions as the table row.
 *
 * Slots: `leading` (avatar or icon), `title`, `subtitle`, `summary` (one
 * summary line under the title), `badges`, default (key information), `meta`
 * (footer metadata) and `actions`.
 *
 * With `href` the whole card opens that record ("stretched link"): the title
 * becomes the card's only link and its ::after covers the card, while every
 * interactive element inside (actions, buttons, links, inputs) is raised
 * above it, so it keeps its own behavior without navigating.
 */
const props = defineProps<{
    /** Detail page the card opens; omit it when the user may not view it. */
    href?: LinkComponentBaseProps['href'];
    /** Accessible name of the card link; defaults to the title text. */
    label?: string;
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
                'relative flex h-full min-w-0 flex-col gap-3 rounded-xl border bg-card p-4 shadow-card',
                !href &&
                    'transition-colors duration-(--duration-panel) ease-(--ease-standard)',
                muted && 'bg-muted/30',
                href &&
                    'interactive-surface [&_:is(a,button,input,select,textarea,label,[role=button],[role=checkbox],[role=switch],[tabindex]):not([data-record-card-link])]:relative [&_:is(a,button,input,select,textarea,label,[role=button],[role=checkbox],[role=switch],[tabindex]):not([data-record-card-link])]:z-10',
                highlighted && 'border-primary/40 bg-primary-soft/60',
                props.class,
            )
        "
    >
        <header class="flex min-w-0 items-start gap-3">
            <slot name="leading" />
            <div class="min-w-0 flex-1 space-y-0.5">
                <div class="min-w-0 text-sm font-semibold text-foreground">
                    <Link
                        v-if="href"
                        :href="href"
                        :aria-label="label"
                        data-record-card-link
                        class="block truncate rounded-sm outline-none after:absolute after:inset-0 after:rounded-xl after:content-[''] hover:underline focus-visible:underline focus-visible:after:ring-2 focus-visible:after:ring-ring focus-visible:after:ring-offset-2 focus-visible:after:ring-offset-background"
                    >
                        <slot name="title" />
                    </Link>
                    <slot v-else name="title" />
                </div>
                <div
                    v-if="$slots.subtitle"
                    class="min-w-0 truncate text-xs text-muted-foreground"
                >
                    <slot name="subtitle" />
                </div>
                <p
                    v-if="$slots.summary"
                    class="min-w-0 pt-0.5 text-sm text-pretty text-muted-foreground"
                >
                    <slot name="summary" />
                </p>
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
            <div v-if="$slots.actions" class="relative z-10 shrink-0">
                <slot name="actions" />
            </div>
        </footer>
    </article>
</template>
