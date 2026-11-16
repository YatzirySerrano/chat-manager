<script setup lang="ts">
import { MousePointer2, Sparkles } from '@lucide/vue';
import { computed, ref } from 'vue';
import './insights.css';

/**
 * Demand per weekday and local hour: a single-hue sequential scale (the
 * platform color, from transparent to solid). Pointing at a cell writes
 * its exact count in the readout under the grid (no floating box over the
 * cells); every cell also carries it as accessible text, so the table
 * reads whole with a screen reader. The busiest cell is outlined.
 */
const props = defineProps<{
    /** 7 rows (Monday first) × 24 hours. */
    cells: number[][];
    max: number;
}>();

const days = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
const fullDays = [
    'lunes',
    'martes',
    'miércoles',
    'jueves',
    'viernes',
    'sábado',
    'domingo',
];
const hours = Array.from({ length: 24 }, (_, hour) => hour);

function opacity(count: number): number {
    if (count === 0 || props.max === 0) {
        return 0;
    }

    return 0.15 + (count / props.max) * 0.85;
}

const busiest = computed(() => {
    let best: { day: number; hour: number; count: number } | null = null;

    props.cells.forEach((row, day) =>
        row.forEach((count, hour) => {
            if (count > 0 && (best === null || count > best.count)) {
                best = { day, hour, count };
            }
        }),
    );

    return best as { day: number; hour: number; count: number } | null;
});

const hovered = ref<{ day: number; hour: number; count: number } | null>(null);

function pad(hour: number): string {
    return String(hour).padStart(2, '0');
}

function plural(count: number): string {
    return `${count.toLocaleString('es-MX')} ${count === 1 ? 'solicitud' : 'solicitudes'}`;
}

function describe(day: number, hour: number, count: number): string {
    return `${fullDays[day]} de ${pad(hour)}:00 a ${pad(hour)}:59: ${plural(count)}`;
}

function isBusiest(day: number, hour: number): boolean {
    return busiest.value?.day === day && busiest.value?.hour === hour;
}
</script>

<template>
    <div class="space-y-3" @pointerleave="hovered = null">
        <div class="-mx-1 overflow-x-auto px-1 pb-1">
            <table
                class="w-full min-w-[600px] border-separate border-spacing-[3px] text-xs"
            >
                <caption class="sr-only">
                    Solicitudes recibidas por día de la semana y hora
                </caption>
                <thead>
                    <tr>
                        <th scope="col" class="w-9"></th>
                        <th
                            v-for="hour in hours"
                            :key="hour"
                            scope="col"
                            class="pb-1 font-normal text-muted-foreground tabular-nums"
                            :class="hovered?.hour === hour && 'text-foreground'"
                        >
                            <span v-if="hour % 3 === 0">{{ pad(hour) }}</span>
                            <span v-else class="sr-only">{{ pad(hour) }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, day) in cells" :key="day">
                        <th
                            scope="row"
                            class="pr-1.5 text-left font-medium text-muted-foreground transition-colors duration-(--duration-fast)"
                            :class="hovered?.day === day && 'text-foreground'"
                        >
                            {{ days[day] }}
                        </th>
                        <td
                            v-for="(count, hour) in row"
                            :key="hour"
                            class="heat-cell relative h-7 rounded-[5px] bg-[color-mix(in_oklch,var(--muted)_80%,transparent)] transition-[scale,box-shadow] duration-(--duration-fast) ease-(--ease-spring) hover:z-10 hover:scale-[1.25] hover:shadow-[0_0_0_2px_var(--card),0_0_0_4px_var(--ring)]"
                            :class="
                                isBusiest(day, hour) &&
                                'shadow-[0_0_0_2px_var(--card),0_0_0_3.5px_var(--primary)]'
                            "
                            :style="{ '--h': hour, '--d': day }"
                            :data-heat-cell="`${day}-${hour}`"
                            @pointerenter="hovered = { day, hour, count }"
                            @pointerdown="hovered = { day, hour, count }"
                        >
                            <span
                                class="absolute inset-0 rounded-[5px] bg-primary"
                                :style="{ opacity: opacity(count) }"
                                aria-hidden="true"
                            />
                            <span class="sr-only">{{
                                describe(day, hour, count)
                            }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div
            class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 text-xs text-muted-foreground"
        >
            <p
                class="flex min-h-5 min-w-0 items-center gap-1.5"
                aria-hidden="true"
                data-heatmap-readout
            >
                <template v-if="hovered">
                    <MousePointer2
                        class="size-3.5 shrink-0 text-primary"
                        aria-hidden="true"
                    />
                    <span>
                        <span
                            class="font-semibold text-foreground capitalize"
                            >{{ fullDays[hovered.day] }}</span
                        >
                        de {{ pad(hovered.hour) }}:00 a
                        {{ pad(hovered.hour) }}:59 ·
                        <span
                            class="font-semibold text-foreground tabular-nums"
                            >{{ plural(hovered.count) }}</span
                        >
                    </span>
                </template>
                <template v-else-if="busiest">
                    <Sparkles
                        class="size-3.5 shrink-0 text-primary"
                        aria-hidden="true"
                    />
                    <span>
                        Mayor demanda:
                        <span class="font-semibold text-foreground">{{
                            fullDays[busiest.day]
                        }}</span>
                        a las {{ pad(busiest.hour) }}:00 ({{
                            plural(busiest.count)
                        }}). Hora local.
                    </span>
                </template>
                <span v-else>Sin solicitudes recibidas en el periodo.</span>
            </p>
            <p v-if="busiest" class="sr-only">
                Mayor demanda: {{ fullDays[busiest.day] }} a las
                {{ pad(busiest.hour) }}:00 ({{ plural(busiest.count) }}). Hora
                local.
            </p>
            <span
                class="flex items-center gap-1.5 tabular-nums"
                aria-hidden="true"
            >
                0
                <span
                    class="h-2 w-20 rounded-full bg-linear-to-r from-primary/15 to-primary"
                />
                {{ max.toLocaleString('es-MX') }}
            </span>
        </div>
    </div>
</template>
