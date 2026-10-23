import { router } from '@inertiajs/vue3';
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

/** Space between the toasts and whatever they make room for. */
const GAP_PX = 12;

/** Default bottom offsets of vue-sonner (desktop and below 600px). */
const DEFAULT_OFFSET_PX = 24;
const DEFAULT_MOBILE_OFFSET_PX = 16;

/**
 * Composers are docked to the bottom of the viewport and take less than
 * this share of its height; the chat column holding them is taller.
 */
const MAX_COMPOSER_SHARE = 0.6;

/** How far (px) from the bottom edge a docked composer may end. */
const DOCK_TOLERANCE_PX = 4;

/**
 * Bottom offset that keeps the toasts above a message composer docked at the
 * bottom of the page (the conversation and internal chat composers), so a
 * toast never covers the text box or the send button. A composer is found as
 * the docked block around a visible text area (outside dialogs): its
 * outermost ancestor whose bottom sits on the bottom edge, that is visible
 * there and stays shorter than the viewport share above. A text box in a
 * card that merely overflows the viewport, or one clipped by a scrolling
 * list, does not count. Measured again after every visit, on resize, on
 * scroll and when the composer grows (a quoted reply, attachments, a
 * longer text).
 */
export function useToastClearance() {
    const clearance = ref(0);
    let observer: ResizeObserver | null = null;
    let observed: Element | null = null;
    let removeFinishListener: (() => void) | null = null;
    let frame = 0;

    /**
     * Whether the block is really docked to the bottom edge: its bottom
     * sits on the edge (not merely beyond it, like a card that overflows
     * the viewport), its top is on screen, and it is what the user sees at
     * the bottom edge (not hidden behind another layer or clipped by a
     * scrolling list, like a note being edited in the timeline).
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

        // The toasts themselves may sit over that point: they are skipped.
        const probe = document
            .elementsFromPoint(
                rect.left + rect.width / 2,
                Math.min(rect.bottom, viewportHeight) - DOCK_TOLERANCE_PX - 1,
            )
            .find((element) => !element.closest('[data-sonner-toaster]'));

        return probe !== undefined && block.contains(probe);
    }

    function dockedComposer(): HTMLElement | null {
        const viewportHeight = window.innerHeight;
        let found: HTMLElement | null = null;

        for (const textarea of document.querySelectorAll('textarea')) {
            // Hidden, or inside a dialog (it floats above the page).
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

                if (
                    Math.abs(rect.bottom - viewportHeight) <= DOCK_TOLERANCE_PX
                ) {
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

    function measure(): void {
        cancelAnimationFrame(frame);
        frame = requestAnimationFrame(() => {
            const composer = dockedComposer();

            clearance.value = composer
                ? Math.max(
                      0,
                      Math.ceil(
                          window.innerHeight -
                              composer.getBoundingClientRect().top +
                              GAP_PX,
                      ),
                  )
                : 0;

            if (composer !== observed) {
                observer?.disconnect();
                observed = composer;

                if (composer) {
                    observer?.observe(composer);
                }
            }
        });
    }

    /**
     * Scrolling a message history (role="log") never moves a docked
     * composer: a text box inside it is clipped by the list and never
     * counts, so those scrolls, one per frame, are not measured.
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

    onMounted(() => {
        observer =
            typeof ResizeObserver === 'undefined'
                ? null
                : new ResizeObserver(measure);
        removeFinishListener = router.on('finish', () => {
            void nextTick(measure);
        });
        window.addEventListener('resize', measure, { passive: true });
        // A page that scrolls can bring a text box onto the bottom edge or
        // take it away (measured at most once per frame).
        window.addEventListener('scroll', measureOnScroll, {
            capture: true,
            passive: true,
        });
        measure();
    });

    onBeforeUnmount(() => {
        cancelAnimationFrame(frame);
        observer?.disconnect();
        removeFinishListener?.();
        window.removeEventListener('resize', measure);
        window.removeEventListener('scroll', measureOnScroll, {
            capture: true,
        });
    });

    return {
        /** Toaster `offset` (desktop). */
        offset: () => ({
            bottom: Math.max(DEFAULT_OFFSET_PX, clearance.value),
        }),
        /** Toaster `mobileOffset` (below 600px). */
        mobileOffset: () => ({
            bottom: Math.max(DEFAULT_MOBILE_OFFSET_PX, clearance.value),
        }),
    };
}
