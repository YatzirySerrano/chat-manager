<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { useRealtimeStatus } from '@/composables/useRealtimeStatus';

/**
 * Small connection indicator for the header. It stays out of the way while
 * real-time works (a dot), says so plainly when it does not and pages are
 * refreshing periodically instead, and shows nothing when real-time is not
 * configured (pages refresh periodically, as before).
 *
 * A short "connecting" phase is not shown, so page loads never flicker.
 *
 * The explanation opens on hover with a mouse and on tap, click or Enter
 * (below md only the dot is visible, so it must be reachable by touch too).
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

const detailOpen = ref(false);
let closeTimer: ReturnType<typeof setTimeout> | null = null;
let openedByHover = false;
let pointerInside = false;

function cancelClose(): void {
    if (closeTimer !== null) {
        clearTimeout(closeTimer);
        closeTimer = null;
    }
}

function hoverIn(event: PointerEvent): void {
    pointerInside = true;

    if (event.pointerType !== 'mouse') {
        return;
    }

    cancelClose();

    if (!detailOpen.value) {
        openedByHover = true;
        detailOpen.value = true;
    }
}

function hoverOut(event: PointerEvent): void {
    pointerInside = false;

    if (event.pointerType !== 'mouse' || !openedByHover) {
        return;
    }

    cancelClose();
    closeTimer = setTimeout(() => {
        detailOpen.value = false;
        openedByHover = false;
    }, 150);
}

function setDetailOpen(value: boolean): void {
    cancelClose();

    // A click on an indicator already previewed by hover keeps it open.
    if (!value && openedByHover && pointerInside) {
        openedByHover = false;

        return;
    }

    detailOpen.value = value;

    if (!value) {
        openedByHover = false;
    }
}

onBeforeUnmount(cancelClose);
</script>

<template>
    <span
        v-if="view"
        role="status"
        :aria-label="view.label"
        class="inline-flex shrink-0"
    >
        <Popover :open="detailOpen" @update:open="setDetailOpen">
            <PopoverTrigger as-child>
                <button
                    type="button"
                    :aria-label="`${view.label}. Ver detalle`"
                    class="inline-flex shrink-0 cursor-default items-center gap-1.5 rounded-full text-xs font-medium focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :class="
                        view.pill
                            ? 'border border-transparent bg-warning-soft px-2.5 py-1 text-warning-soft-foreground'
                            : 'size-10 justify-center'
                    "
                    @pointerenter="hoverIn"
                    @pointerleave="hoverOut"
                >
                    <span
                        class="size-2 rounded-full"
                        :class="view.dot"
                        aria-hidden="true"
                    />
                    <span
                        v-if="view.short"
                        class="hidden md:inline xl:hidden"
                        aria-hidden="true"
                        >{{ view.short }}</span
                    >
                    <span
                        v-if="view.pill"
                        class="hidden xl:inline"
                        aria-hidden="true"
                        >{{ view.label }}</span
                    >
                </button>
            </PopoverTrigger>
            <PopoverContent
                align="end"
                class="w-72 p-3"
                @open-auto-focus.prevent
                @pointerenter="hoverIn"
                @pointerleave="hoverOut"
            >
                <p class="text-sm font-medium">{{ view.label }}</p>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ view.detail }}
                </p>
            </PopoverContent>
        </Popover>
    </span>
</template>
