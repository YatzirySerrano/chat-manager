<script setup lang="ts">
import { computed } from 'vue';

/**
 * Demand per weekday and local hour: a single-hue sequential scale (the
 * platform color, from transparent to solid). Each cell carries its exact
 * count as a tooltip and as accessible text.
 */
const props = defineProps<{
    /** 7 rows (Monday first) × 24 hours. */
    cells: number[][];
    max: number;
    timezone: string;
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

function describe(day: number, hour: number, count: number): string {
    return `${fullDays[day]} de ${String(hour).padStart(2, '0')}:00 a ${String(hour).padStart(2, '0')}:59: ${count.toLocaleString('es-MX')} ${count === 1 ? 'conversación' : 'conversaciones'}`;
}
</script>

<template>
    <div class="space-y-3">
        <div class="overflow-x-auto">
            <table
                class="w-full min-w-[560px] border-separate border-spacing-0.5 text-[10px]"
            >
                <caption class="sr-only">
                    Conversaciones recibidas por día de la semana y hora
                </caption>
                <thead>
                    <tr>
                        <th scope="col" class="w-9"></th>
                        <th
                            v-for="hour in hours"
                            :key="hour"
                            scope="col"
                            class="font-normal text-muted-foreground"
                        >
                            {{
                                hour % 3 === 0
                                    ? String(hour).padStart(2, '0')
                                    : ''
                            }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, day) in cells" :key="day">
                        <th
                            scope="row"
                            class="pr-1 text-left font-normal text-muted-foreground"
                        >
                            {{ days[day] }}
                        </th>
                        <td
                            v-for="(count, hour) in row"
                            :key="hour"
                            class="relative h-5 rounded-sm bg-muted/60"
                            :title="describe(day, hour, count)"
                        >
                            <span
                                class="absolute inset-0 rounded-sm bg-primary"
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
            class="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground"
        >
            <p v-if="busiest">
                Mayor demanda: {{ fullDays[busiest.day] }} a las
                {{ String(busiest.hour).padStart(2, '0') }}:00 ({{
                    busiest.count.toLocaleString('es-MX')
                }}). Hora local de {{ timezone }}.
            </p>
            <p v-else>Sin conversaciones recibidas en el periodo.</p>
            <span class="flex items-center gap-1.5" aria-hidden="true">
                0
                <span
                    class="h-2 w-16 rounded-sm bg-gradient-to-r from-primary/15 to-primary"
                />
                {{ max.toLocaleString('es-MX') }}
            </span>
        </div>
    </div>
</template>
