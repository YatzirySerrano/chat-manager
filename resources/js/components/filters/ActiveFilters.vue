<script setup lang="ts">
import { X } from '@lucide/vue';
import type { ActiveFilterPill } from '@/components/filters/types';
import './filters.css';

/**
 * The applied filters that are not visible elsewhere (department, tags,
 * channel…) as removable pills, and «Limpiar filtros» whenever anything
 * narrows the list. Pills pop in and out where they are, so the person sees
 * what a click removed. The default slot goes first, before the pills (the
 * quick toggles), so the whole row wraps as one.
 */
defineProps<{
    pills: ActiveFilterPill[];
    /** Something narrows the list: offer «Limpiar filtros». */
    clearable: boolean;
}>();

const emit = defineEmits<{ clear: [] }>();
</script>

<template>
    <div
        class="relative flex flex-wrap items-center gap-1.5"
        role="group"
        aria-label="Filtros aplicados"
    >
        <slot />
        <TransitionGroup name="filter-pill">
            <span
                v-for="pill in pills"
                :key="pill.key"
                class="inline-flex h-7 max-w-full min-w-0 items-center gap-0.5 rounded-full border border-primary/30 bg-primary-soft pr-0.5 pl-2.5 text-xs font-medium text-primary-soft-foreground shadow-(--shadow-control) pointer-coarse:h-9"
                data-inbox-applied
                data-filter-pill
            >
                <span class="truncate">{{ pill.label }}</span>
                <button
                    type="button"
                    class="inline-flex size-6 shrink-0 items-center justify-center rounded-full text-primary-soft-foreground/70 outline-none hover:bg-primary/15 hover:text-primary-soft-foreground focus-visible:ring-2 focus-visible:ring-ring active:scale-90 motion-safe:transition-colors pointer-coarse:size-8"
                    :aria-label="`Quitar el filtro ${pill.label}`"
                    @click="pill.remove()"
                >
                    <X class="size-3.5" aria-hidden="true" />
                </button>
            </span>
        </TransitionGroup>
        <Transition
            enter-active-class="motion-safe:transition-opacity duration-(--duration-fast)"
            enter-from-class="opacity-0"
            leave-active-class="motion-safe:transition-opacity duration-(--duration-fast)"
            leave-to-class="opacity-0"
        >
            <button
                v-if="clearable"
                type="button"
                class="ml-auto inline-flex h-7 items-center rounded-full px-2 text-xs font-medium text-primary underline-offset-2 outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring pointer-coarse:h-9"
                data-inbox-clear
                data-filter-clear
                @click="emit('clear')"
            >
                Limpiar filtros
            </button>
        </Transition>
    </div>
</template>
