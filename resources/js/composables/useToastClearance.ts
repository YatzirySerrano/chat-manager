import { useDockedComposer } from '@/composables/useDockedComposer';

/** Default bottom offsets of vue-sonner (desktop and below 600px). */
const DEFAULT_OFFSET_PX = 24;
const DEFAULT_MOBILE_OFFSET_PX = 16;

/**
 * Room for the floating help button (48 px tall plus a gap) at the bottom
 * right: toasts stack above it instead of covering it.
 */
export const HELP_BUTTON_SPACE_PX = 64;

/**
 * Bottom offset that keeps the toasts above a message composer or a sticky
 * save bar docked at the bottom of the page (see useDockedComposer), so a
 * toast never covers the text box, the send button or the save button, and
 * above the floating help button while it is shown (it is replaced by an
 * inline header button whenever a composer is docked).
 */
export function useToastClearance() {
    const { clearance, docked } = useDockedComposer();

    const helpSpace = (): number => (docked.value ? 0 : HELP_BUTTON_SPACE_PX);

    return {
        /** Toaster `offset` (desktop). */
        offset: () => ({
            bottom: Math.max(DEFAULT_OFFSET_PX, clearance.value) + helpSpace(),
        }),
        /** Toaster `mobileOffset` (below 600px). */
        mobileOffset: () => ({
            bottom:
                Math.max(DEFAULT_MOBILE_OFFSET_PX, clearance.value) +
                helpSpace(),
        }),
    };
}
