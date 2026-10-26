<script setup lang="ts">
import { SlidersHorizontal, X } from '@lucide/vue';
import { createReusableTemplate } from '@vueuse/core';
import { PopoverClose } from 'reka-ui';
import { nextTick, useId, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';

/**
 * The container of the inbox "Filtros" panel. On wide screens it is a
 * non-modal popover sized to the room the viewport really has below (or
 * above) the button (--reka-popover-content-available-height, with 12 px of
 * collision padding), so it is never cut off; on phones and very short
 * windows (200 % zoom and up) it is a modal bottom sheet with a focus trap.
 *
 * Both share one structure: a fixed header (title and how many filters are
 * applied), the panel (the only part that scrolls) and a fixed footer with
 * «Limpiar» and «Aplicar». The panel edits a draft: nothing reaches the
 * list until «Aplicar» (or Enter in a field); «Limpiar» resets and applies.
 * Closing by accident (a click outside, Tab, rotating the phone) keeps the
 * draft, and the panel then says it holds changes not applied yet; Escape
 * and «Cerrar» close on purpose and drop it.
 */
const props = defineProps<{
    /** Bottom sheet (narrow or short viewport) instead of the popover. */
    compact: boolean;
    /** Filters applied to the list right now (the button's counter). */
    appliedCount: number;
    /** Filters the draft would apply (the «Aplicar» counter). */
    draftCount: number;
    /** The draft differs from what is applied (kept across closings). */
    draftPending?: boolean;
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    apply: [];
    clear: [];
    /** Closed on purpose (Escape or «Cerrar»): the draft is dropped. */
    discard: [];
}>();

const titleId = useId();
const bodyId = useId();

/*
 * Switching between popover and sheet while open (rotating the phone,
 * zooming) would leave it orphaned: it closes and reopens in the other
 * surface, and the draft is kept by the list.
 */
watch(
    () => props.compact,
    () => {
        if (!open.value) {
            return;
        }

        open.value = false;
        void nextTick(() => (open.value = true));
    },
);

/*
 * The first control of the panel takes the focus when it opens (never the
 * close button), so a keyboard user starts right on the filters.
 */
function focusFirstControl(event: Event): void {
    event.preventDefault();
    void nextTick(() => {
        document
            .getElementById(bodyId)
            ?.querySelector<HTMLElement>(
                'input:not([disabled]):not([type="hidden"]), select:not([disabled]), button:not([disabled]), [tabindex]:not([tabindex="-1"])',
            )
            ?.focus();
    });
}

function submit(): void {
    emit('apply');
}

const [DefineTrigger, ReuseTrigger] = createReusableTemplate();
const [DefineBody, ReuseBody] = createReusableTemplate();
</script>

<template>
    <DefineTrigger>
        <Button
            variant="outline"
            size="sm"
            class="h-9 shrink-0 pointer-coarse:h-10"
            :aria-label="
                (appliedCount > 0
                    ? `Filtros (${appliedCount} aplicados)`
                    : 'Filtros') +
                (draftPending ? ', con cambios sin aplicar' : '')
            "
            data-inbox-filters
            data-tour="inbox.filters"
        >
            <SlidersHorizontal aria-hidden="true" />
            Filtros
            <span
                v-if="appliedCount > 0"
                class="rounded-full bg-primary px-1.5 text-xs text-primary-foreground tabular-nums"
                aria-hidden="true"
                >{{ appliedCount }}</span
            >
            <span
                v-if="draftPending"
                class="size-2 rounded-full bg-warning"
                aria-hidden="true"
                title="Cambios sin aplicar"
                data-inbox-filter-pending-dot
            />
        </Button>
    </DefineTrigger>

    <DefineBody>
        <form
            novalidate
            class="flex min-h-0 flex-1 flex-col"
            data-inbox-filter-form
            @submit.prevent="submit"
        >
            <div
                :id="bodyId"
                class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 py-3"
                data-inbox-filter-body
            >
                <slot />
            </div>
            <p
                v-if="draftPending"
                class="shrink-0 border-t bg-warning-soft px-4 py-1.5 text-xs font-medium text-warning-soft-foreground"
                data-inbox-filter-pending
            >
                Tienes cambios sin aplicar.
            </p>
            <footer
                class="flex shrink-0 items-center justify-between gap-2 border-t bg-popover px-4 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]"
                data-inbox-filter-footer
            >
                <Button
                    type="button"
                    variant="ghost"
                    class="pointer-coarse:h-10"
                    data-inbox-filter-clear
                    @click="emit('clear')"
                >
                    Limpiar
                </Button>
                <Button
                    type="submit"
                    class="pointer-coarse:h-10"
                    data-inbox-filter-apply
                >
                    {{
                        draftCount > 0
                            ? `Aplicar (${draftCount})`
                            : 'Aplicar filtros'
                    }}
                </Button>
            </footer>
        </form>
    </DefineBody>

    <Sheet v-if="compact" v-model:open="open">
        <SheetTrigger as-child>
            <ReuseTrigger />
        </SheetTrigger>
        <SheetContent
            side="bottom"
            class="flex max-h-[90dvh] flex-col gap-0 rounded-t-2xl bg-popover p-0 text-popover-foreground shadow-elevated"
            data-inbox-filter-surface="sheet"
            @open-auto-focus="focusFirstControl"
            @escape-key-down="emit('discard')"
        >
            <header class="shrink-0 border-b py-3 pr-12 pl-4">
                <SheetTitle class="text-base">Filtros</SheetTitle>
                <SheetDescription class="text-xs text-muted-foreground">
                    {{
                        appliedCount > 0
                            ? `${appliedCount} ${appliedCount === 1 ? 'aplicado' : 'aplicados'} · Pulsa «Aplicar» para ver el resultado.`
                            : 'Elige los filtros y pulsa «Aplicar» para ver el resultado.'
                    }}
                </SheetDescription>
            </header>
            <ReuseBody />
        </SheetContent>
    </Sheet>

    <Popover v-else v-model:open="open">
        <PopoverTrigger as-child>
            <ReuseTrigger />
        </PopoverTrigger>
        <PopoverContent
            align="end"
            side="bottom"
            :collision-padding="12"
            :avoid-collisions="true"
            class="flex max-h-[min(40rem,var(--reka-popover-content-available-height))] w-[22rem] flex-col gap-0 overflow-hidden rounded-lg p-0 shadow-popover"
            :aria-labelledby="titleId"
            data-inbox-filter-surface="popover"
            @open-auto-focus="focusFirstControl"
            @escape-key-down="emit('discard')"
        >
            <header
                class="flex shrink-0 items-center justify-between gap-2 border-b py-2.5 pr-2 pl-4"
            >
                <div class="min-w-0">
                    <h2 :id="titleId" class="text-base font-semibold">
                        Filtros
                    </h2>
                    <p class="text-xs text-muted-foreground">
                        {{
                            appliedCount > 0
                                ? `${appliedCount} ${appliedCount === 1 ? 'aplicado' : 'aplicados'}`
                                : 'Ninguno aplicado'
                        }}
                    </p>
                </div>
                <PopoverClose as-child>
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        class="shrink-0 text-muted-foreground pointer-coarse:size-10"
                        aria-label="Cerrar los filtros"
                        title="Cerrar"
                        @click="emit('discard')"
                    >
                        <X aria-hidden="true" />
                    </Button>
                </PopoverClose>
            </header>
            <ReuseBody />
        </PopoverContent>
    </Popover>
</template>
