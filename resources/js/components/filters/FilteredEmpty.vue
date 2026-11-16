<script setup lang="ts">
import { FilterX } from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import { Button } from '@/components/ui/button';

/**
 * The empty state of a filtered list. When filters or a search narrow it,
 * it says so («No hay conversaciones con estos filtros») and offers
 * «Limpiar filtros» right there; otherwise it shows the list's own empty
 * copy (`title`, `description`) and the default slot (its next action).
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
    <EmptyState
        v-if="narrowed"
        tone="filtered"
        :icon="FilterX"
        :title="narrowedTitle"
        :description="narrowedDescription"
        class="m-3 border-none"
        data-filter-empty="narrowed"
    >
        <Button
            variant="outline"
            size="sm"
            data-filter-empty-clear
            @click="emit('clear')"
        >
            Limpiar filtros
        </Button>
    </EmptyState>
    <EmptyState
        v-else
        :icon="icon"
        :title="title"
        :description="description"
        class="m-3 border-none"
        data-filter-empty="empty"
    >
        <slot />
    </EmptyState>
</template>
