<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useRealtimeStatus } from '@/composables/useRealtimeStatus';

/**
 * Small connection indicator for the header. It stays out of the way while
 * real-time works (a dot), says so plainly when it does not and pages are
 * refreshing periodically instead, and shows nothing when real-time is not
 * configured (pages refresh periodically, as before).
 *
 * A short "connecting" phase is not shown, so page loads never flicker.
 */
const { state, fallbackSeconds } = useRealtimeStatus();

const CONNECTING_GRACE_MS = 4000;
const showConnecting = ref(false);
let connectingTimer: ReturnType<typeof setTimeout> | null = null;

watch(
    state,
    (current) => {
        if (connectingTimer) {
            clearTimeout(connectingTimer);
            connectingTimer = null;
        }

        showConnecting.value = false;

        if (current === 'connecting') {
            connectingTimer = setTimeout(() => {
                showConnecting.value = true;
            }, CONNECTING_GRACE_MS);
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    if (connectingTimer) {
        clearTimeout(connectingTimer);
    }
});

const refreshText = computed(() =>
    fallbackSeconds.value === null
        ? 'actualiza la página para ver cambios recientes'
        : `actualizando cada ${fallbackSeconds.value} s`,
);

const view = computed(() => {
    if (state.value === 'connected') {
        return {
            label: 'Tiempo real activo',
            detail: 'Los cambios aparecen al momento.',
            short: null,
            dot: 'bg-success',
            pill: false,
        };
    }

    if (
        state.value === 'unavailable' ||
        (state.value === 'connecting' && showConnecting.value)
    ) {
        return {
            label: `Tiempo real no disponible — ${refreshText.value}`,
            detail:
                state.value === 'connecting'
                    ? 'Conectando con el servidor de tiempo real…'
                    : 'Se perdió la conexión en tiempo real; reintentando. Al reconectar se recuperan los cambios.',
            short: 'Sin tiempo real',
            dot: 'bg-warning animate-pulse',
            pill: true,
        };
    }

    return null;
});
</script>

<template>
    <Tooltip v-if="view">
        <TooltipTrigger as-child>
            <span
                role="status"
                :aria-label="view.label"
                class="inline-flex shrink-0 items-center gap-1.5 rounded-full text-xs font-medium"
                :class="
                    view.pill
                        ? 'border border-transparent bg-warning-soft px-2.5 py-1 text-warning-soft-foreground'
                        : 'size-9 justify-center'
                "
                tabindex="0"
            >
                <span
                    class="size-2 rounded-full"
                    :class="view.dot"
                    aria-hidden="true"
                />
                <span v-if="view.short" class="hidden md:inline xl:hidden">{{
                    view.short
                }}</span>
                <span v-if="view.pill" class="hidden xl:inline">{{
                    view.label
                }}</span>
            </span>
        </TooltipTrigger>
        <TooltipContent class="max-w-72">
            <p class="font-medium">{{ view.label }}</p>
            <p class="text-xs opacity-80">{{ view.detail }}</p>
        </TooltipContent>
    </Tooltip>
</template>
