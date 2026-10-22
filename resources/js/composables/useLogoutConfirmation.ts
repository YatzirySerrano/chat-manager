import type { Ref } from 'vue';
import { onBeforeUnmount, ref } from 'vue';

/**
 * Coordinates the hand-off between a dropdown menu item and the logout
 * confirmation dialog.
 *
 * Selecting the menu item only marks the request; the dialog opens once the
 * dropdown has finished closing (its `close-auto-focus` event), so the menu
 * returns focus to its trigger first and the dialog can then trap focus and
 * restore it to that trigger when dismissed. A short fallback timer covers the
 * case where the menu never emits that event.
 */
export function useLogoutConfirmation(): {
    open: Ref<boolean>;
    request: () => void;
    onMenuCloseAutoFocus: () => void;
} {
    const open = ref(false);
    let pending = false;
    let fallbackTimer: ReturnType<typeof setTimeout> | undefined;

    function openDialog(): void {
        if (!pending) {
            return;
        }

        pending = false;
        clearTimeout(fallbackTimer);
        // Let the menu finish moving focus to its trigger before the dialog
        // mounts and captures it.
        setTimeout(() => {
            open.value = true;
        }, 0);
    }

    function request(): void {
        pending = true;
        clearTimeout(fallbackTimer);
        fallbackTimer = setTimeout(openDialog, 400);
    }

    onBeforeUnmount(() => clearTimeout(fallbackTimer));

    return { open, request, onMenuCloseAutoFocus: openDialog };
}
