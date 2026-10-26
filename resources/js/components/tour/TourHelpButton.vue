<script setup lang="ts">
import { BookOpen, Compass, RotateCcw } from '@lucide/vue';
import { useMediaQuery } from '@vueuse/core';
import { computed, ref } from 'vue';
import ModuleHelpSheet from '@/components/help/ModuleHelpSheet.vue';
import TourLauncherList from '@/components/tour/TourLauncherList.vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Separator } from '@/components/ui/separator';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useDockedComposer } from '@/composables/useDockedComposer';
import { useGuidedTour } from '@/composables/useGuidedTour';

/**
 * Global help: the guided tours of the current screen and, secondary, the
 * full guide of its module.
 *
 * `floating` sits at the bottom right of every signed-in screen and rises
 * above a sticky save bar; whenever a message composer is docked at the
 * bottom (an open chat), it gives way to the `inline` variant in the top
 * bar, so it never covers the send button or «Ir al último mensaje». It
 * also moves to the top bar on very short windows (400 % zoom), where a
 * floating button would cover a large part of the screen. Mount one of each
 * (the layout and the top bar); only one shows at a time.
 */
const props = withDefaults(
    defineProps<{
        variant?: 'floating' | 'inline';
    }>(),
    { variant: 'floating' },
);

const tours = useGuidedTour();
const { clearance, docked } = useDockedComposer();

const open = ref(false);
const guideOpen = ref(false);

const veryShort = useMediaQuery('(max-height: 20rem)');
const inTopBar = computed(() => docked.value || veryShort.value);

const visible = computed(() =>
    props.variant === 'floating' ? !inTopBar.value : inTopBar.value,
);

const label = computed(() =>
    tours.hasUnseen.value
        ? 'Ayuda y recorridos guiados, hay recorridos nuevos'
        : 'Ayuda y recorridos guiados',
);

const floatingStyle = computed(() => ({
    bottom: `calc(${Math.max(16, clearance.value)}px + env(safe-area-inset-bottom))`,
}));

function openGuide(): void {
    open.value = false;
    guideOpen.value = true;
}

function resetSeen(): void {
    open.value = false;
    tours.resetSeen();
}
</script>

<template>
    <div
        v-if="visible"
        :class="
            variant === 'floating'
                ? 'fixed right-[max(1rem,env(safe-area-inset-right))] z-40 transition-[bottom] duration-(--duration-base) ease-standard print:hidden'
                : 'inline-flex'
        "
        :style="variant === 'floating' ? floatingStyle : undefined"
        data-tour-layer
    >
        <Popover v-model:open="open">
            <PopoverAnchor as-child>
                <span class="relative inline-flex" data-tour="shell.help">
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <PopoverTrigger as-child>
                                <Button
                                    v-if="variant === 'floating'"
                                    type="button"
                                    class="size-12 rounded-full shadow-elevated transition-transform duration-(--duration-fast) ease-standard hover:scale-105 hover:bg-primary focus-visible:ring-[3px] motion-reduce:hover:scale-100"
                                    :aria-label="label"
                                    aria-haspopup="dialog"
                                >
                                    <Compass
                                        class="size-[22px]"
                                        aria-hidden="true"
                                    />
                                </Button>
                                <Button
                                    v-else
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    class="size-9 rounded-lg text-muted-foreground pointer-coarse:size-10"
                                    :aria-label="label"
                                    aria-haspopup="dialog"
                                >
                                    <Compass
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                </Button>
                            </PopoverTrigger>
                        </TooltipTrigger>
                        <TooltipContent>Ayuda</TooltipContent>
                    </Tooltip>
                    <span
                        v-if="tours.hasUnseen.value"
                        class="pointer-events-none absolute flex size-2.5"
                        :class="
                            variant === 'floating'
                                ? 'top-0.5 right-0.5'
                                : 'top-1.5 right-1.5'
                        "
                        aria-hidden="true"
                        data-tour-new
                    >
                        <span
                            class="absolute inline-flex size-full animate-ping rounded-full bg-warning opacity-70 [animation-duration:1.6s] [animation-iteration-count:3]"
                        />
                        <span
                            class="relative inline-flex size-2.5 rounded-full bg-warning ring-2 ring-background"
                        />
                    </span>
                </span>
            </PopoverAnchor>

            <PopoverContent
                :align="'end'"
                :side="variant === 'floating' ? 'top' : 'bottom'"
                :side-offset="8"
                fit-viewport
                class="flex w-80 flex-col gap-3 p-3"
            >
                <h2 class="px-1 pt-1 text-sm font-semibold">
                    Ayuda de esta pantalla
                </h2>
                <TourLauncherList @launch="open = false" />
                <Separator />
                <div class="flex flex-col gap-0.5">
                    <Button
                        v-if="tours.guideModule.value"
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="justify-start font-normal"
                        @click="openGuide"
                    >
                        <BookOpen aria-hidden="true" />
                        Ver la guía completa del módulo
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="justify-start font-normal text-muted-foreground"
                        @click="resetSeen"
                    >
                        <RotateCcw aria-hidden="true" />
                        Volver a mostrar los recorridos
                    </Button>
                </div>
            </PopoverContent>
        </Popover>

        <ModuleHelpSheet
            v-if="tours.guideModule.value"
            v-model:open="guideOpen"
            :module="tours.guideModule.value"
        />
    </div>
</template>
