<script setup lang="ts" generic="T extends string">
import type { LucideIcon } from '@lucide/vue';
import { ref, watch } from 'vue';
import CountBadge from '@/components/filters/CountBadge.vue';
import { useSlidingIndicator } from '@/composables/useSlidingIndicator';
import { cn } from '@/lib/utils';
import './insights.css';

/**
 * The views of a page (Resumen / Productividad…, Seguimiento / Monitoreo
 * de SLA) as a pill track whose raised thumb springs to the chosen view,
 * the same language as the inbox tabs. Real ARIA tabs: one tab stop (the
 * selected one), ←/→, Inicio and Fin move between them and select; each
 * tab controls `${panelPrefix}-${key}`.
 */
const props = defineProps<{
    items: {
        key: T;
        label: string;
        icon?: LucideIcon;
        /** Live counter beside the label. */
        count?: number | null;
        /** Work waits here: the counter turns to the warning tone. */
        attention?: boolean;
        attrs?: Record<string, string>;
    }[];
    modelValue: T;
    /** Accessible name of the tab list. */
    label: string;
    /** Ids: `${idPrefix}-${key}` for the tab, `${panelPrefix}-${key}` for its panel. */
    idPrefix: string;
    panelPrefix: string;
}>();

const emit = defineEmits<{ select: [key: T] }>();

const list = ref<HTMLElement | null>(null);
const {
    style: thumb,
    animated,
    update,
} = useSlidingIndicator(list, '[aria-selected=true]');

watch(
    () => [props.modelValue, props.items.map((item) => item.key).join()],
    update,
    { flush: 'post' },
);

function focusTab(key: T): void {
    document.getElementById(`${props.idPrefix}-${key}`)?.focus();
}

function onKeydown(event: KeyboardEvent, index: number): void {
    const last = props.items.length - 1;
    const next =
        event.key === 'ArrowRight'
            ? (index + 1) % props.items.length
            : event.key === 'ArrowLeft'
              ? (index - 1 + props.items.length) % props.items.length
              : event.key === 'Home'
                ? 0
                : event.key === 'End'
                  ? last
                  : null;

    if (next === null) {
        return;
    }

    event.preventDefault();
    const item = props.items[next];

    if (item) {
        emit('select', item.key);
        focusTab(item.key);
    }
}
</script>

<template>
    <div class="flex max-w-full min-w-0">
        <div ref="list" class="insight-tabs" role="tablist" :aria-label="label">
            <span
                class="insight-tabs-thumb"
                :data-animated="animated || undefined"
                :style="thumb"
                aria-hidden="true"
            />
            <button
                v-for="(item, index) in items"
                :id="`${idPrefix}-${item.key}`"
                :key="item.key"
                type="button"
                role="tab"
                v-bind="item.attrs"
                :class="
                    cn(
                        'relative z-[1] inline-flex h-9 shrink-0 items-center gap-1.5 rounded-full px-3.5 text-sm font-medium whitespace-nowrap outline-none',
                        'transition-[color,transform] duration-(--duration-fast) ease-(--ease-standard) focus-visible:ring-[3px] focus-visible:ring-primary/30 active:scale-[0.96] pointer-coarse:h-10',
                        modelValue === item.key
                            ? 'font-semibold text-foreground'
                            : 'text-muted-foreground hover:bg-background/60 hover:text-foreground',
                    )
                "
                :aria-selected="modelValue === item.key"
                :aria-controls="`${panelPrefix}-${item.key}`"
                :tabindex="modelValue === item.key ? 0 : -1"
                @click="emit('select', item.key)"
                @keydown="onKeydown($event, index)"
            >
                <component
                    :is="item.icon"
                    v-if="item.icon"
                    class="size-4 shrink-0 transition-colors duration-(--duration-fast)"
                    :class="modelValue === item.key && 'text-primary'"
                    aria-hidden="true"
                />
                {{ item.label }}
                <CountBadge
                    v-if="item.count !== undefined && item.count !== null"
                    :value="item.count"
                    :tone="
                        item.attention && item.count > 0
                            ? 'warning'
                            : modelValue === item.key
                              ? 'primary'
                              : 'muted'
                    "
                />
            </button>
        </div>
    </div>
</template>
