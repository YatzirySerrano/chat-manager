<script setup lang="ts">
import { onBeforeUnmount, watch } from 'vue';
import { useGuidedTour } from '@/composables/useGuidedTour';
import type { ModuleHelpKey } from '@/help';

/**
 * Page introduction: the page's single <h1>, its description and the
 * page-level actions.
 *
 * `help` declares the module of the screen: the global help button (in the
 * layout) offers its guided tours and, secondary, its full guide. The page
 * header no longer shows a help button of its own.
 *
 * The sticky top bar already shows the page name as the last breadcrumb (on
 * every viewport), so by default the <h1> is visually hidden but kept for
 * screen readers and the document outline; the visible row starts with the
 * description. Pass `showTitle` when the title adds information the
 * breadcrumb does not (a greeting, a record name with its avatar, a form
 * title such as "Nueva conexión").
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
        /** Show the title visually (it is always present for screen readers). */
        showTitle?: boolean;
    }>(),
    {
        description: undefined,
        help: undefined,
        showTitle: false,
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
    <header class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2">
        <div
            v-if="showTitle || description || $slots.eyebrow"
            class="min-w-0 grow basis-80 space-y-1"
        >
            <slot name="eyebrow" />
            <h1
                :class="
                    showTitle
                        ? 'text-2xl font-semibold tracking-tight break-words'
                        : 'sr-only'
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
        <h1 v-else class="sr-only">{{ title }}</h1>
        <div
            v-if="$slots.actions"
            data-tour="page.actions"
            class="flex min-w-0 flex-wrap items-center gap-2 max-sm:w-full max-sm:[&_a]:min-h-11 max-sm:[&_button]:min-h-11"
            :class="!(showTitle || description || $slots.eyebrow) && 'ml-auto'"
        >
            <slot name="actions" />
        </div>
    </header>
</template>
