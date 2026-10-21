<script setup lang="ts">
import { LayoutGrid, Table2 } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { ViewMode } from '@/types';

/**
 * Switches a list between the table and the cards presentation. Purely
 * visual: the parent keeps its data, filters and pagination.
 */
const mode = defineModel<ViewMode>({ required: true });

const OPTIONS = [
    { value: 'table', label: 'Tabla', icon: Table2 },
    { value: 'cards', label: 'Tarjetas', icon: LayoutGrid },
] as const;
</script>

<template>
    <div
        class="inline-flex shrink-0 items-center rounded-md border bg-background p-0.5"
        role="group"
        aria-label="Tipo de vista"
    >
        <Tooltip v-for="option in OPTIONS" :key="option.value">
            <TooltipTrigger as-child>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    class="size-9 sm:size-8"
                    :class="
                        mode === option.value &&
                        'bg-muted text-foreground shadow-xs'
                    "
                    :aria-pressed="mode === option.value"
                    :aria-label="`Vista de ${option.label.toLowerCase()}`"
                    @click="mode = option.value"
                >
                    <component :is="option.icon" aria-hidden="true" />
                </Button>
            </TooltipTrigger>
            <TooltipContent>{{ option.label }}</TooltipContent>
        </Tooltip>
    </div>
</template>
