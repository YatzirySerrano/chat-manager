<script setup lang="ts">
import { CircleQuestionMark, TriangleAlert } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, useId } from 'vue';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
} from '@/components/ui/popover';
import type { FieldHelpContent } from '@/types';

/**
 * Accessible contextual help for a form field, the single help component of
 * the app.
 *
 * - Mouse/pen: hovering opens it after a short delay and leaving closes it,
 *   with a grace period so the pointer can travel into the content. A click
 *   pins it open (click again, Escape or clicking outside closes it).
 * - Keyboard: focusing the icon opens it; Escape or moving focus away closes
 *   it. Enter/Space toggle it.
 * - Touch: an explicit tap on the icon opens/closes it (hover is ignored).
 *
 * Pointer and click events never leave the icon, so it can sit next to a
 * Switch, checkbox or <label> without toggling them or submitting a form.
 *
 * The trigger is a focusable element with the button role instead of a
 * <button>, so a read-only form wrapped in <fieldset disabled> keeps its help
 * working (a disabled fieldset disables every descendant button).
 */
const props = defineProps<{
    /** Field label, used for the accessible name of the button. */
    title: string;
    help?: FieldHelpContent;
}>();

const OPEN_DELAY_MS = 280;
const CLOSE_GRACE_MS = 150;

const heading = computed(() => props.help?.title ?? props.title);
const descriptionId = `field-help-${useId()}`;

const open = ref(false);
/** Opened by an explicit click/tap/key, so pointer leave and blur keep it. */
const pinned = ref(false);
const pointerInsideContent = ref(false);
const trigger = ref<HTMLElement | null>(null);

let openTimer: ReturnType<typeof setTimeout> | undefined;
let closeTimer: ReturnType<typeof setTimeout> | undefined;

function clearTimers(): void {
    clearTimeout(openTimer);
    clearTimeout(closeTimer);
}

function show(): void {
    clearTimers();
    open.value = true;
}

function hide(): void {
    clearTimers();
    open.value = false;
    pinned.value = false;
}

function scheduleOpen(): void {
    clearTimeout(closeTimer);

    if (!open.value) {
        clearTimeout(openTimer);
        openTimer = setTimeout(show, OPEN_DELAY_MS);
    }
}

function scheduleClose(): void {
    clearTimeout(openTimer);

    if (pinned.value) {
        return;
    }

    clearTimeout(closeTimer);
    closeTimer = setTimeout(() => {
        if (!pinned.value && !pointerInsideContent.value) {
            hide();
        }
    }, CLOSE_GRACE_MS);
}

function toggle(): void {
    if (open.value && pinned.value) {
        hide();

        return;
    }

    show();
    pinned.value = true;
}

/** Focus already shows it, so Enter/Space simply flip the visible state. */
function toggleFromKeyboard(): void {
    if (open.value) {
        hide();

        return;
    }

    show();
    pinned.value = true;
}

function onTriggerPointerDown(event: PointerEvent): void {
    // Keeps a mouse press from moving focus here (which would open it a second
    // way) and a touch from emulating mouse events on nearby controls.
    event.preventDefault();
}

function onTriggerPointerEnter(event: PointerEvent): void {
    if (event.pointerType !== 'touch') {
        scheduleOpen();
    }
}

function onTriggerPointerLeave(event: PointerEvent): void {
    if (event.pointerType !== 'touch') {
        scheduleClose();
    }
}

/**
 * Only keyboard focus opens it: focus set by code (e.g. a dialog focusing its
 * first focusable element when it opens) must not pop the explanation open.
 */
function onTriggerFocus(event: FocusEvent): void {
    if ((event.target as HTMLElement | null)?.matches(':focus-visible')) {
        show();
    }
}

function onTriggerBlur(): void {
    if (!pointerInsideContent.value) {
        hide();
    }
}

function onContentPointerEnter(event: PointerEvent): void {
    if (event.pointerType !== 'touch') {
        pointerInsideContent.value = true;
        clearTimeout(closeTimer);
    }
}

function onContentPointerLeave(event: PointerEvent): void {
    if (event.pointerType !== 'touch') {
        pointerInsideContent.value = false;
        scheduleClose();
    }
}

/** Escape and outside clicks reported by the popover close it. */
function onOpenChange(value: boolean): void {
    if (value) {
        show();
    } else {
        pointerInsideContent.value = false;
        hide();
    }
}

/** A press on the icon itself is handled by its own click toggle. */
function ignoreTriggerInteraction(event: Event): void {
    const target = (event as CustomEvent<{ originalEvent?: Event }>).detail
        ?.originalEvent?.target;

    if (target instanceof Node && trigger.value?.contains(target)) {
        event.preventDefault();
    }
}

onBeforeUnmount(clearTimers);
</script>

<template>
    <Popover :open="open" @update:open="onOpenChange">
        <PopoverAnchor as-child>
            <span
                ref="trigger"
                role="button"
                tabindex="0"
                :aria-label="`Ayuda: ${title}`"
                :aria-expanded="open"
                :aria-describedby="open ? descriptionId : undefined"
                data-slot="field-help"
                class="relative inline-flex size-5 shrink-0 cursor-pointer items-center justify-center rounded-full text-muted-foreground transition-colors after:absolute after:-inset-1 after:content-[''] hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                @pointerdown.stop="onTriggerPointerDown"
                @mousedown.stop.prevent
                @click.stop.prevent="toggle"
                @pointerenter="onTriggerPointerEnter"
                @pointerleave="onTriggerPointerLeave"
                @focus="onTriggerFocus"
                @blur="onTriggerBlur"
                @keydown.enter.stop.prevent="toggleFromKeyboard"
                @keydown.space.stop.prevent="toggleFromKeyboard"
                @keyup.space.stop.prevent
            >
                <CircleQuestionMark class="size-4" aria-hidden="true" />
            </span>
        </PopoverAnchor>
        <PopoverContent
            align="start"
            class="w-80 text-sm"
            :aria-label="heading"
            @open-auto-focus.prevent
            @close-auto-focus.prevent
            @pointer-down-outside="ignoreTriggerInteraction"
            @focus-outside="ignoreTriggerInteraction"
            @pointerenter="onContentPointerEnter"
            @pointerleave="onContentPointerLeave"
        >
            <div :id="descriptionId" class="space-y-3">
                <p class="font-medium text-foreground">{{ heading }}</p>
                <slot>
                    <p v-if="help?.description" class="text-muted-foreground">
                        {{ help.description }}
                    </p>
                    <div v-if="help?.howTo" class="space-y-1">
                        <p class="text-xs font-medium text-foreground">
                            Cómo llenarlo
                        </p>
                        <p class="text-muted-foreground">{{ help.howTo }}</p>
                    </div>
                    <div v-if="help?.example" class="space-y-1">
                        <p class="text-xs font-medium text-foreground">
                            Ejemplo
                        </p>
                        <p
                            class="rounded-md bg-muted px-2 py-1 font-mono text-xs break-words text-foreground"
                        >
                            {{ help.example }}
                        </p>
                    </div>
                    <div
                        v-if="help?.warning"
                        class="flex gap-2 rounded-md bg-warning-soft p-2 text-foreground"
                    >
                        <TriangleAlert
                            class="mt-0.5 size-4 shrink-0 text-warning"
                            aria-hidden="true"
                        />
                        <p>
                            <span class="font-medium">Importante:</span>
                            {{ help.warning }}
                        </p>
                    </div>
                </slot>
            </div>
        </PopoverContent>
    </Popover>
</template>
