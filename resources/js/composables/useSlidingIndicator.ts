import { useResizeObserver } from '@vueuse/core';
import { nextTick, onMounted, ref } from 'vue';
import type { CSSProperties, Ref } from 'vue';

/**
 * Position of a single highlight that slides between the items of a group
 * (the active item of the sidebar, a segmented control).
 *
 * The indicator is an absolutely positioned element inside `container`
 * (which must be `relative`); `style` moves and sizes it over the element
 * matching `selector`. Call `update()` whenever the active item changes; it
 * also follows resizes. The first placement is instant (`animated` turns on
 * afterwards), so the highlight never flies in from the corner on load.
 */
export function useSlidingIndicator(
    container: Readonly<Ref<HTMLElement | null>>,
    selector: string,
) {
    const style = ref<CSSProperties>({ opacity: 0 });
    const animated = ref(false);

    function measure(): void {
        const root = container.value;
        const target = root?.querySelector<HTMLElement>(selector);

        if (!root || !target) {
            style.value = { ...style.value, opacity: 0 };

            return;
        }

        const rootBox = root.getBoundingClientRect();
        const box = target.getBoundingClientRect();

        style.value = {
            opacity: 1,
            width: `${box.width}px`,
            height: `${box.height}px`,
            transform: `translate(${box.left - rootBox.left - root.clientLeft + root.scrollLeft}px, ${box.top - rootBox.top - root.clientTop + root.scrollTop}px)`,
        };
    }

    async function update(): Promise<void> {
        await nextTick();
        measure();
    }

    onMounted(async () => {
        await update();
        requestAnimationFrame(() => {
            animated.value = true;
        });
    });

    useResizeObserver(container, measure);

    return { style, animated, update };
}
