import { onBeforeUnmount, onMounted } from 'vue';

/** Pinch zoom above this scale: the visible area is not the screen any more. */
const ZOOMED_SCALE = 1.01;

/**
 * Full-height chat screens (conversations and internal chat) on phones.
 *
 * - `--app-height` on <html> follows the visible area (visualViewport), so
 *   the page shrinks when the on-screen keyboard opens and the composer
 *   stays right above it, with the header still on screen. Android Chrome
 *   already resizes the page (viewport meta `interactive-widget`); iOS
 *   Safari only shrinks the visual viewport and pans the page to the focused
 *   field, so that pan is undone (the page fits the visible area and has
 *   nothing to scroll). While the person pinch-zooms nothing is changed.
 * - The page itself never scrolls nor bounces: pull-to-refresh (Android)
 *   and the rubber band (iOS) of the whole page no longer fight with the
 *   message history and the list, which scroll on their own.
 *
 * Without visualViewport the screens keep `100dvh`.
 */
export function useChatViewport(): void {
    let frame = 0;

    function apply(): void {
        const viewport = globalThis.visualViewport;
        const root = document.documentElement;

        if (!viewport || viewport.scale > ZOOMED_SCALE) {
            root.style.removeProperty('--app-height');

            return;
        }

        root.style.setProperty(
            '--app-height',
            `${Math.round(viewport.height)}px`,
        );

        // A field inside a dialog or sheet is left to the browser, which
        // brings it into view on its own.
        const inLayer = document.activeElement?.closest(
            '[role="dialog"], [role="alertdialog"]',
        );

        if (globalThis.scrollY !== 0 && !inLayer) {
            globalThis.scrollTo(0, 0);
        }
    }

    function schedule(): void {
        cancelAnimationFrame(frame);
        frame = requestAnimationFrame(apply);
    }

    onMounted(() => {
        const root = document.documentElement;

        root.style.overscrollBehaviorY = 'none';
        globalThis.visualViewport?.addEventListener('resize', schedule);
        globalThis.visualViewport?.addEventListener('scroll', schedule);
        apply();
    });

    onBeforeUnmount(() => {
        const root = document.documentElement;

        cancelAnimationFrame(frame);
        globalThis.visualViewport?.removeEventListener('resize', schedule);
        globalThis.visualViewport?.removeEventListener('scroll', schedule);
        root.style.removeProperty('--app-height');
        root.style.removeProperty('overscroll-behavior-y');
    });
}
