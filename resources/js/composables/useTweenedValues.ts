import { onBeforeUnmount, ref, watch } from 'vue';
import type { Ref } from 'vue';

/**
 * Values that glide to their new targets when the source changes (a live
 * update, a new filter), so a chart morphs instead of jumping. Each list is
 * tweened position by position; a null (not measured) is never
 * interpolated: it switches at once. The first value and reduced motion
 * apply immediately.
 */
export function useTweenedValues(
    source: () => (number | null)[][],
    duration = 480,
): Ref<(number | null)[][]> {
    const shown = ref<(number | null)[][]>(source().map((list) => [...list]));
    let frame = 0;

    function reducedMotion(): boolean {
        return (
            typeof window === 'undefined' ||
            window.matchMedia('(prefers-reduced-motion: reduce)').matches
        );
    }

    watch(
        source,
        (target) => {
            cancelAnimationFrame(frame);

            const from = shown.value;
            const sameShape =
                from.length === target.length &&
                from.every(
                    (list, index) => list.length === target[index]!.length,
                );

            if (!sameShape || reducedMotion()) {
                shown.value = target.map((list) => [...list]);

                return;
            }

            const start = performance.now();

            const tick = (now: number): void => {
                const progress = Math.min(1, (now - start) / duration);
                const eased = 1 - (1 - progress) ** 3;

                shown.value = target.map((list, row) =>
                    list.map((value, index) => {
                        const previous = from[row]![index] ?? null;

                        return value === null || previous === null
                            ? value
                            : previous + (value - previous) * eased;
                    }),
                );

                if (progress < 1) {
                    frame = requestAnimationFrame(tick);
                }
            };

            frame = requestAnimationFrame(tick);
        },
        { deep: true },
    );

    onBeforeUnmount(() => cancelAnimationFrame(frame));

    return shown;
}
