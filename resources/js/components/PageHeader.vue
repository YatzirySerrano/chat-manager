<script setup lang="ts">
import { onBeforeUnmount, watch } from 'vue';
import { useGuidedTour } from '@/composables/useGuidedTour';
import type { ModuleHelpKey } from '@/help';

/**
 * Page introduction: the page's single <h1>, its description and the
 * page-level actions. It spans the full width of the page, the title at
 * the left edge in the display face and the actions at the right.
 *
 * `help` declares the module of the screen: the global help button (in the
 * layout) offers its guided tours and, secondary, its full guide.
 *
 * The title is visible by default: on a full-width workspace it is the
 * anchor of the page. Pass `hideTitle` only where the page already shows
 * the same name prominently (the <h1> stays for screen readers and the
 * document outline). `showTitle` is accepted for compatibility with pages
 * written before the title became visible by default.
 *
 * The text block has a 20rem flex basis and grows, so the actions sit next to
 * it only when both fit; otherwise they wrap to their own row. On phones every
 * action link or button is at least 44px tall.
 */
const props = withDefaults(
    defineProps<{
        title: string;
        description?: string;
        /** Module of the screen (guided tours and full guide of the help button). */
        help?: ModuleHelpKey;
        /** Deprecated: the title is visible unless `hideTitle` is set. */
        showTitle?: boolean;
        /** Keep the title only for screen readers. */
        hideTitle?: boolean;
    }>(),
    {
        description: undefined,
        help: undefined,
        showTitle: undefined,
        hideTitle: false,
    },
);

const tours = useGuidedTour();

let unregister: () => void = () => {};

watch(
    () => props.help,
    (key) => {
        unregister();
        unregister = key ? tours.registerPageModule(key) : () => {};
    },
    { immediate: true },
);

onBeforeUnmount(() => unregister());
</script>

<template>
    <header
        class="flex flex-wrap items-end justify-between gap-x-6 gap-y-3"
        data-slot="page-header"
    >
        <div class="min-w-0 grow basis-80 space-y-1.5">
            <slot name="eyebrow" />
            <h1
                :class="
                    hideTitle
                        ? 'sr-only'
                        : 'font-display text-[1.75rem] leading-[1.15] font-semibold break-words sm:text-3xl'
                "
            >
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
            data-tour="page.actions"
            class="flex min-w-0 flex-wrap items-center gap-2 max-sm:w-full max-sm:[&_a]:min-h-11 max-sm:[&_button]:min-h-11"
        >
            <slot name="actions" />
        </div>
    </header>
</template>
