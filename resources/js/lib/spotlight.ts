/**
 * Pointer tracking for the `spotlight` utility (resources/css/app.css): one
 * passive listener for the whole document writes the pointer position, in
 * pixels relative to the element, into --mx / --my of the closest
 * `.spotlight` under it. Elements only need the class.
 */
export function initializeSpotlight(): void {
    if (typeof window === 'undefined') {
        return;
    }

    let frame = 0;
    let last: PointerEvent | null = null;

    const apply = (): void => {
        frame = 0;

        const target =
            last?.target instanceof Element
                ? last.target.closest<HTMLElement>('.spotlight')
                : null;

        if (!target || !last) {
            return;
        }

        const box = target.getBoundingClientRect();

        target.style.setProperty('--mx', `${last.clientX - box.left}px`);
        target.style.setProperty('--my', `${last.clientY - box.top}px`);
    };

    window.addEventListener(
        'pointermove',
        (event) => {
            if (event.pointerType === 'touch') {
                return;
            }

            last = event;

            if (frame === 0) {
                frame = requestAnimationFrame(apply);
            }
        },
        { passive: true },
    );
}
