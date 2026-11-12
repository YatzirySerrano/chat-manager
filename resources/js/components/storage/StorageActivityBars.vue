<script setup lang="ts">
import { computed, ref } from 'vue';
import { formatFileSize } from '@/lib/format';
import type { StorageHour } from '@/types/storage';

/**
 * Files saved per hour over the last 24 hours. One bar per hour, the
 * current hour last; hovering or focusing a bar shows its figures in the
 * line above the chart (no floating tooltip to chase).
 */
const props = defineProps<{
    hours: StorageHour[];
}>();

const active = ref<number | null>(null);

const peak = computed(() =>
    Math.max(1, ...props.hours.map((hour) => hour.files)),
);

const total = computed(() =>
    props.hours.reduce((sum, hour) => sum + hour.files, 0),
);

const hourFormat = new Intl.DateTimeFormat('es-MX', {
    hour: 'numeric',
    hour12: true,
});

function hourLabel(iso: string): string {
    return hourFormat.format(new Date(iso));
}

const summary = computed(() => {
    const index = active.value;

    if (index === null || !props.hours[index]) {
        return total.value === 0
            ? 'Sin archivos en las últimas 24 horas.'
            : `${total.value.toLocaleString('es-MX')} ${total.value === 1 ? 'archivo' : 'archivos'} en las últimas 24 horas.`;
    }

    const hour = props.hours[index];

    return hour.files === 0
        ? `${hourLabel(hour.hour)}: sin archivos.`
        : `${hourLabel(hour.hour)}: ${hour.files.toLocaleString('es-MX')} ${hour.files === 1 ? 'archivo' : 'archivos'}, ${formatFileSize(hour.bytes)}.`;
});

/** Every sixth hour is labelled on the axis, plus the current one. */
function showTick(index: number): boolean {
    return index % 6 === 0 || index === props.hours.length - 1;
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <p class="text-sm font-medium tabular-nums" aria-live="polite">
            {{ summary }}
        </p>
        <ol
            class="flex h-36 items-end gap-1"
            aria-label="Archivos guardados por hora"
            @mouseleave="active = null"
        >
            <li
                v-for="(hour, index) in hours"
                :key="hour.hour"
                class="group/bar flex h-full min-w-0 flex-1 items-end rounded-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                tabindex="0"
                :aria-label="`${hourLabel(hour.hour)}: ${hour.files} archivos`"
                @mouseenter="active = index"
                @focus="active = index"
                @blur="active = null"
            >
                <span
                    class="bar w-full rounded-t-sm transition-[height,background-color] duration-(--duration-panel) ease-(--ease-out-quint)"
                    :class="
                        hour.files === 0
                            ? 'bg-muted'
                            : active === index
                              ? 'bg-primary'
                              : 'bg-primary/55 group-hover/bar:bg-primary'
                    "
                    :style="{
                        height:
                            hour.files === 0
                                ? '2px'
                                : `${Math.max(6, (hour.files / peak) * 100)}%`,
                    }"
                />
            </li>
        </ol>
        <ol
            class="flex gap-1 text-xs text-muted-foreground tabular-nums"
            aria-hidden="true"
        >
            <li
                v-for="(hour, index) in hours"
                :key="hour.hour"
                class="flex min-w-0 flex-1 whitespace-nowrap"
                :class="
                    index === hours.length - 1 ? 'justify-end' : 'justify-start'
                "
            >
                <span v-if="showTick(index)" class="inline-block">
                    {{
                        index === hours.length - 1
                            ? 'ahora'
                            : hourLabel(hour.hour)
                    }}
                </span>
            </li>
        </ol>
    </div>
</template>
