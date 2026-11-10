import { onScopeDispose } from 'vue';

/** How long a touch must be held to open the preview. */
const HOLD_MS = 500;
/** A finger moving farther than this is scrolling, not holding. */
const MOVE_TOLERANCE_PX = 10;

/**
 * «Mantener presionado» on touch screens and the context menu (right click,
 * the keyboard's menu key or Shift+F10) on computers, for a list row that
 * also opens normally with a tap or a click.
 *
 * A held touch opens it after HOLD_MS (with a short vibration where the
 * device has one) and swallows the click that follows, so lifting the
 * finger never also opens the chat. Moving the finger (scrolling the list)
 * cancels it. Browsers that also fire `contextmenu` on a long touch never
 * open it twice.
 *
 * Bind `handlers` on the row element (`v-on="handlers"`) and start its
 * click handler with `consumeClick()`.
 */
export function useLongPress(onPress: () => void) {
    let timer: ReturnType<typeof setTimeout> | null = null;
    let startX = 0;
    let startY = 0;
    let pressedAt = 0;
    let swallowClick = false;

    function cancel(): void {
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }
    }

    function press(): void {
        cancel();
        pressedAt = Date.now();
        swallowClick = true;
        // Only the click that ends this touch is swallowed: a later keyboard
        // or assistive activation of the row must still open it.
        setTimeout(() => (swallowClick = false), 1_500);

        try {
            navigator.vibrate?.(10);
        } catch {
            // Vibration is optional.
        }

        onPress();
    }

    onScopeDispose(cancel);

    const handlers = {
        pointerdown(event: PointerEvent): void {
            swallowClick = false;

            if (event.pointerType === 'mouse' || !event.isPrimary) {
                return;
            }

            startX = event.clientX;
            startY = event.clientY;
            cancel();
            timer = setTimeout(press, HOLD_MS);
        },
        pointermove(event: PointerEvent): void {
            if (
                timer !== null &&
                Math.hypot(event.clientX - startX, event.clientY - startY) >
                    MOVE_TOLERANCE_PX
            ) {
                cancel();
            }
        },
        pointerup: cancel,
        pointercancel: cancel,
        pointerleave: cancel,
        contextmenu(event: MouseEvent): void {
            event.preventDefault();

            // The long touch already opened it.
            if (Date.now() - pressedAt < 1_000) {
                return;
            }

            press();
            // A right click is not followed by a click.
            swallowClick = false;
        },
    };

    /**
     * Whether the click that just happened ends a long touch (and must not
     * open the row). Call it first in the row's own click handler.
     */
    function consumeClick(): boolean {
        const swallowed = swallowClick;
        swallowClick = false;

        return swallowed;
    }

    return { handlers, consumeClick };
}
