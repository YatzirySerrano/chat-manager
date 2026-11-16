import { useResizeObserver } from '@vueuse/core';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import type { CSSProperties, Ref } from 'vue';

/**
 * The selected row of a list sits on one glider (a tinted slab with an
 * accent bar) that springs from the previous selection to the new one, so
 * the eye follows the move instead of hunting for the highlight. The rail
 * is the positioned container of the rows; the selected row carries
 * `data-selected`. The glider is measured with offsets (not rects), so the
 * FLIP transforms of a TransitionGroup never shift it mid-move.
 *
 * The first placement does not glide (`placed` turns on a frame later),
 * and the global reduced-motion rule turns the glide into a jump.
 */
export function useSelectionGlider(
    rail: Ref<HTMLElement | null>,
    /** Changes whenever the selection or the rows change. */
    deps: () => unknown,
): { style: Ref<CSSProperties>; visible: Ref<boolean>; placed: Ref<boolean> } {
    const box = ref({ top: 0, left: 0, width: 0, height: 0 });
    const visible = ref(false);
    const placed = ref(false);

    function measure(): void {
        const container = rail.value;
        const row = container?.querySelector<HTMLElement>('[data-selected]');

        if (!container || !row) {
            visible.value = false;

            return;
        }

        let top = 0;
        let left = 0;
        let element: HTMLElement | null = row;

        // Sum the offsets up to the rail (it is the rows' positioned ancestor).
        while (element && element !== container) {
            top += element.offsetTop;
            left += element.offsetLeft;
            element = element.offsetParent as HTMLElement | null;

            if (element && !container.contains(element)) {
                visible.value = false;

                return;
            }
        }

        box.value = {
            top,
            left,
            width: row.offsetWidth,
            height: row.offsetHeight,
        };
        visible.value = true;
    }

    onMounted(() => {
        measure();
        requestAnimationFrame(() => (placed.value = true));
    });

    watch(deps, () => void nextTick(measure), { flush: 'post' });
    useResizeObserver(rail, () => measure());

    const style = computed<CSSProperties>(() => ({
        left: `${box.value.left}px`,
        width: `${box.value.width}px`,
        height: `${box.value.height}px`,
        transform: `translateY(${box.value.top}px)`,
        opacity: visible.value ? 1 : 0,
    }));

    return { style, visible, placed };
}
