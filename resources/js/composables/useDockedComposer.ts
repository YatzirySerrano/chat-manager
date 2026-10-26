import { router } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import type { ComputedRef, Ref } from 'vue';

/** Space between the docked block and whatever floats above it. */
const GAP_PX = 12;

/**
 * Composers are docked to the bottom of the viewport and take less than
 * this share of its height; the chat column holding them is taller.
 */
const MAX_COMPOSER_SHARE = 0.6;

/** How far (px) from the bottom edge a docked block may end. */
const DOCK_TOLERANCE_PX = 4;

/* Shared state: one measurement for every consumer of the tab. */
const clearance = ref(0);
const composerDocked = ref(false);
const hasDockBar = ref(false);
let consumers = 0;
let observer: ResizeObserver | null = null;
let observed: Element | null = null;
let removeFinishListener: (() => void) | null = null;
let frame = 0;

/**
 * Whether the block is really docked to the bottom edge: its bottom sits on
 * the edge (not merely beyond it, like a card that overflows the viewport),
 * its top is on screen, and it is what the user sees at the bottom edge (not
 * hidden behind another layer or clipped by a scrolling list, like a note
 * being edited in the timeline). Floating layers that make room for it
 * (toasts, the help button) are skipped.
 */
function isDocked(block: HTMLElement, viewportHeight: number): boolean {
    const rect = block.getBoundingClientRect();

    if (
        Math.abs(rect.bottom - viewportHeight) > DOCK_TOLERANCE_PX ||
        rect.top < 0 ||
        rect.width === 0
    ) {
        return false;
    }

    const probe = document
        .elementsFromPoint(
            rect.left + rect.width / 2,
            Math.min(rect.bottom, viewportHeight) - DOCK_TOLERANCE_PX - 1,
        )
        .find(
            (element) =>
                !element.closest('[data-sonner-toaster], [data-tour-layer]'),
        );

    return probe !== undefined && block.contains(probe);
}

/** The docked block around a visible text area (outside dialogs). */
function dockedComposer(viewportHeight: number): HTMLElement | null {
    let found: HTMLElement | null = null;

    for (const textarea of document.querySelectorAll('textarea')) {
        if (
            textarea.offsetParent === null ||
            textarea.closest('[role="dialog"], [role="alertdialog"]')
        ) {
            continue;
        }

        let block: HTMLElement | null = null;
        let current: HTMLElement | null = textarea;

        while (current && current !== document.body) {
            const rect = current.getBoundingClientRect();

            if (rect.height > viewportHeight * MAX_COMPOSER_SHARE) {
                break;
            }

            if (Math.abs(rect.bottom - viewportHeight) <= DOCK_TOLERANCE_PX) {
                block = current;
            }

            current = current.parentElement;
        }

        if (
            block &&
            isDocked(block, viewportHeight) &&
            (!found ||
                block.getBoundingClientRect().top <
                    found.getBoundingClientRect().top)
        ) {
            found = block;
        }
    }

    return found;
}

/** A sticky bar marked `data-dock-bottom` (a form's save bar) on the bottom edge. */
function dockedBar(viewportHeight: number): HTMLElement | null {
    for (const bar of document.querySelectorAll<HTMLElement>(
        '[data-dock-bottom]',
    )) {
        if (bar.offsetParent !== null && isDocked(bar, viewportHeight)) {
            return bar;
        }
    }

    return null;
}

function measure(): void {
    cancelAnimationFrame(frame);
    frame = requestAnimationFrame(() => {
        // While a guided tour makes the page inert nothing in it can be hit
        // tested (every block would look covered): keep the last result.
        if (document.getElementById('app')?.hasAttribute('inert')) {
            return;
        }

        const viewportHeight = window.innerHeight;
        const composer = dockedComposer(viewportHeight);
        const block = composer ?? dockedBar(viewportHeight);

        composerDocked.value = composer !== null;
        hasDockBar.value =
            document.querySelector('[data-dock-bottom]') !== null;
        clearance.value = block
            ? Math.max(
                  0,
                  Math.ceil(
                      viewportHeight -
                          block.getBoundingClientRect().top +
                          GAP_PX,
                  ),
              )
            : 0;

        if (block !== observed) {
            observer?.disconnect();
            observed = block;

            if (block) {
                observer?.observe(block);
            }
        }
    });
}

/**
 * Scrolling a message history (role="log") never moves a docked composer:
 * a text box inside it is clipped by the list and never counts, so those
 * scrolls, one per frame, are not measured.
 */
function measureOnScroll(event: Event): void {
    if (
        event.target instanceof Element &&
        event.target.getAttribute('role') === 'log'
    ) {
        return;
    }

    measure();
}

function attach(): void {
    observer =
        typeof ResizeObserver === 'undefined'
            ? null
            : new ResizeObserver(measure);
    removeFinishListener = router.on('finish', () => {
        void nextTick(measure);
    });
    window.addEventListener('resize', measure, { passive: true });
    // A page that scrolls can bring a text box onto the bottom edge or take
    // it away (measured at most once per frame).
    window.addEventListener('scroll', measureOnScroll, {
        capture: true,
        passive: true,
    });
    measure();
}

function detach(): void {
    cancelAnimationFrame(frame);
    observer?.disconnect();
    observer = null;
    observed = null;
    removeFinishListener?.();
    removeFinishListener = null;
    window.removeEventListener('resize', measure);
    window.removeEventListener('scroll', measureOnScroll, { capture: true });
}

/**
 * What is docked to the bottom edge of the page: a message composer (the
 * conversation and internal chat composers) or a sticky save bar marked
 * with `data-dock-bottom`. Floating layers (toasts, the help button) use it
 * so they never cover the text box, the send button or a save button.
 *
 * A composer is found as the docked block around a visible text area
 * (outside dialogs): its outermost ancestor whose bottom sits on the bottom
 * edge, that is visible there and stays shorter than the viewport share
 * above. A text box in a card that merely overflows the viewport, or one
 * clipped by a scrolling list, does not count. Measured again after every
 * visit, on resize, on scroll and when the block grows (a quoted reply,
 * attachments, a longer text). One shared measurement for the tab; call it
 * from a component's setup.
 */
export function useDockedComposer(): {
    /** Pixels from the bottom edge to just above the docked block (0 = none). */
    clearance: Readonly<Ref<number>>;
    /** A message composer is docked (not a save bar). */
    docked: ComputedRef<boolean>;
    /** The page has a sticky save bar (docked or not). */
    hasDockBar: ComputedRef<boolean>;
    /** Measure again now (e.g. after something floating appeared). */
    refresh: () => void;
} {
    onMounted(() => {
        consumers += 1;

        if (consumers === 1) {
            attach();
        } else {
            measure();
        }
    });

    onBeforeUnmount(() => {
        consumers -= 1;

        if (consumers === 0) {
            detach();
        }
    });

    return {
        clearance,
        docked: computed(() => composerDocked.value),
        hasDockBar: computed(() => hasDockBar.value),
        refresh: measure,
    };
}
