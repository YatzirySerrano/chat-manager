<script setup lang="ts">
import { PlayCircle } from '@lucide/vue';
import { onMounted, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { plannedStepCount, useGuidedTour } from '@/composables/useGuidedTour';
import { loadTourSteps } from '@/tours';

/**
 * Tours available on the current screen, each one a button that starts it.
 * Used by the help popover and at the top of the full module guide; the
 * parent closes itself on `launch` and the tour starts once it is gone.
 */
const emit = defineEmits<{
    launch: [tourId: string];
}>();

const tours = useGuidedTour();

/** Step count per tour id (known once its module file is loaded). */
const counts = ref<Record<string, number>>({});

async function loadCounts(): Promise<void> {
    for (const entry of tours.available.value) {
        try {
            const steps = (await loadTourSteps(entry.file))[entry.id] ?? [];
            counts.value = {
                ...counts.value,
                [entry.id]: plannedStepCount(steps),
            };
        } catch {
            // The count is only a hint; the tour can still start.
        }
    }
}

onMounted(loadCounts);
watch(tours.available, loadCounts);

function launch(tourId: string): void {
    emit('launch', tourId);
    void tours.start(tourId);
}
</script>

<template>
    <p
        v-if="tours.available.value.length === 0"
        class="text-sm text-muted-foreground"
    >
        Esta pantalla aún no tiene recorrido guiado.
    </p>
    <ul v-else class="flex flex-col gap-1" aria-label="Recorridos guiados">
        <li v-for="entry in tours.available.value" :key="entry.id">
            <button
                type="button"
                class="group/tour flex w-full items-start gap-3 rounded-lg px-2.5 py-2 text-left transition-colors duration-(--duration-fast) hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :data-tour-launch="entry.id"
                @click="launch(entry.id)"
            >
                <PlayCircle
                    class="mt-0.5 size-5 shrink-0 text-primary"
                    aria-hidden="true"
                />
                <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                    <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span class="text-sm font-medium">{{
                            entry.title
                        }}</span>
                        <Badge v-if="!tours.isSeen(entry.id)" variant="warning"
                            >Nuevo</Badge
                        >
                    </span>
                    <span class="text-sm text-pretty text-muted-foreground">{{
                        entry.summary
                    }}</span>
                    <span
                        v-if="counts[entry.id]"
                        class="text-xs text-muted-foreground tabular-nums"
                        >{{ counts[entry.id] }}
                        {{ counts[entry.id] === 1 ? 'paso' : 'pasos' }}</span
                    >
                </span>
            </button>
        </li>
    </ul>
</template>
