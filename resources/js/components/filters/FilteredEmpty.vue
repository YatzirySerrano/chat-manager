<script setup lang="ts">
import { FilterX } from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import './filters.css';

/**
 * The empty state of a filtered list. When filters or a search narrow it,
 * it says so («No hay conversaciones con estos filtros») and offers
 * «Limpiar filtros» right there; otherwise it shows the list's own empty
 * copy (`title`, `description`) and the default slot (its next action).
 *
 * Above the text, the list's icon (or a «filter off» icon when the filters
 * are the reason) on a quiet tile. Nothing moves.
 */
withDefaults(
    defineProps<{
        narrowed: boolean;
        /** «No hay conversaciones con estos filtros». */
        narrowedTitle: string;
        narrowedDescription?: string;
        title: string;
        description?: string;
        icon?: LucideIcon;
    }>(),
    {
        narrowedDescription:
            'Quita algún filtro o cambia la búsqueda para ver más resultados.',
        description: undefined,
        icon: undefined,
    },
);

const emit = defineEmits<{ clear: [] }>();
</script>

<template>
    <div
        class="flex flex-col items-center px-6 pt-12 pb-8 text-center"
        :data-filter-empty="narrowed ? 'narrowed' : 'empty'"
        data-slot="empty-state"
    >
        <span
            class="mb-4 grid size-10 place-items-center rounded-lg border bg-muted/50 text-muted-foreground"
            aria-hidden="true"
        >
            <component
                :is="narrowed ? FilterX : icon"
                v-if="narrowed || icon"
                class="size-5"
            />
        </span>
        <p class="text-sm leading-snug font-semibold text-balance">
            {{ narrowed ? narrowedTitle : title }}
        </p>
        <p
            v-if="narrowed ? narrowedDescription : description"
            class="mt-1 max-w-[36ch] text-[0.8125rem] text-pretty text-muted-foreground"
        >
            {{ narrowed ? narrowedDescription : description }}
        </p>
        <div class="mt-4 flex flex-wrap justify-center gap-2">
            <Button
                v-if="narrowed"
                variant="outline"
                size="sm"
                data-filter-empty-clear
                @click="emit('clear')"
            >
                Limpiar filtros
            </Button>
            <slot v-else />
        </div>
    </div>
</template>
