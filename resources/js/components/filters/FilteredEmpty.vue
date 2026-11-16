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
 * Above the text, a small composition: two floating row cards behind the
 * list's icon tile, on a soft glow in the palette color (muted when the
 * list is empty only because of the filters). It rises once, never loops.
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
        class="flex flex-col items-center px-6 pt-10 pb-8 text-center"
        :data-filter-empty="narrowed ? 'narrowed' : 'empty'"
        data-slot="empty-state"
    >
        <div
            class="list-empty-stage mb-6"
            :data-tone="narrowed ? 'filtered' : 'neutral'"
            aria-hidden="true"
        >
            <!-- Two ghost rows behind, slightly tilted. -->
            <span
                class="list-empty-card top-1 left-0 flex h-9 w-24 items-center gap-1.5 px-2"
                :data-tone="narrowed ? 'filtered' : undefined"
                style="--r: -7deg; --i: 0"
            >
                <span class="size-5 rounded-full bg-muted" />
                <span class="grid flex-1 gap-1">
                    <span class="h-1.5 w-4/5 rounded-full bg-muted" />
                    <span class="h-1.5 w-1/2 rounded-full bg-muted" />
                </span>
            </span>
            <span
                class="list-empty-card top-9 right-0 flex h-9 w-24 items-center gap-1.5 px-2"
                :data-tone="narrowed ? 'filtered' : undefined"
                style="--r: 6deg; --i: 1"
            >
                <span
                    class="size-5 rounded-full"
                    :class="narrowed ? 'bg-muted' : 'bg-primary/25'"
                />
                <span class="grid flex-1 gap-1">
                    <span class="h-1.5 w-3/4 rounded-full bg-muted" />
                    <span class="h-1.5 w-2/5 rounded-full bg-muted" />
                </span>
            </span>
            <!-- The list's icon, in front. -->
            <span
                class="list-empty-card bottom-0 left-1/2 -ml-6 flex size-12 items-center justify-center rounded-2xl text-muted-foreground"
                :data-tone="narrowed ? 'filtered' : 'accent'"
                style="--i: 2"
            >
                <component
                    :is="narrowed ? FilterX : icon"
                    v-if="narrowed || icon"
                    class="size-6"
                />
            </span>
        </div>
        <p class="font-display text-lg leading-snug font-semibold text-balance">
            {{ narrowed ? narrowedTitle : title }}
        </p>
        <p
            v-if="narrowed ? narrowedDescription : description"
            class="mt-1.5 max-w-[34ch] text-sm text-pretty text-muted-foreground"
        >
            {{ narrowed ? narrowedDescription : description }}
        </p>
        <div class="mt-5 flex flex-wrap justify-center gap-2">
            <Button
                v-if="narrowed"
                variant="outline"
                size="sm"
                class="rounded-full"
                data-filter-empty-clear
                @click="emit('clear')"
            >
                Limpiar filtros
            </Button>
            <slot v-else />
        </div>
    </div>
</template>
