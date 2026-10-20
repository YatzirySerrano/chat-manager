<script setup lang="ts">
import { CircleQuestionMark, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import type { FieldHelpContent } from '@/types';

/**
 * Accessible contextual help for a form field. It opens on click, tap or
 * keyboard (Enter/Space) and closes with Escape or by clicking outside; it
 * never depends on hover, so it works on touch screens.
 *
 * The trigger is a focusable element with the button role instead of a
 * <button>, so a read-only form wrapped in <fieldset disabled> keeps its help
 * working (a disabled fieldset disables every descendant button).
 */
const props = defineProps<{
    /** Field label, used for the accessible name of the button. */
    title: string;
    help?: FieldHelpContent;
}>();

const heading = computed(() => props.help?.title ?? props.title);

function activate(event: KeyboardEvent): void {
    (event.currentTarget as HTMLElement).click();
}
</script>

<template>
    <Popover>
        <PopoverTrigger as-child>
            <span
                role="button"
                tabindex="0"
                :aria-label="`Ayuda: ${title}`"
                class="inline-flex size-5 shrink-0 cursor-pointer items-center justify-center rounded-full text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                @keydown.enter.prevent="activate"
                @keydown.space.prevent="activate"
            >
                <CircleQuestionMark class="size-4" aria-hidden="true" />
            </span>
        </PopoverTrigger>
        <PopoverContent
            align="start"
            class="w-80 space-y-3 text-sm"
            :aria-label="heading"
        >
            <p class="font-medium text-foreground">{{ heading }}</p>
            <slot>
                <p v-if="help?.description" class="text-muted-foreground">
                    {{ help.description }}
                </p>
                <div v-if="help?.howTo" class="space-y-1">
                    <p class="text-xs font-medium text-foreground">
                        Cómo llenarlo
                    </p>
                    <p class="text-muted-foreground">{{ help.howTo }}</p>
                </div>
                <div v-if="help?.example" class="space-y-1">
                    <p class="text-xs font-medium text-foreground">Ejemplo</p>
                    <p
                        class="rounded-md bg-muted px-2 py-1 font-mono text-xs break-words text-foreground"
                    >
                        {{ help.example }}
                    </p>
                </div>
                <div
                    v-if="help?.warning"
                    class="flex gap-2 rounded-md bg-warning/10 p-2 text-foreground"
                >
                    <TriangleAlert
                        class="mt-0.5 size-4 shrink-0 text-warning"
                        aria-hidden="true"
                    />
                    <p>
                        <span class="font-medium">Importante:</span>
                        {{ help.warning }}
                    </p>
                </div>
            </slot>
        </PopoverContent>
    </Popover>
</template>
