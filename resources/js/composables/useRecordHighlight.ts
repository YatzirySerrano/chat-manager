import { router } from '@inertiajs/vue3';
import { nextTick, onUnmounted, ref } from 'vue';
import type { Highlight } from '@/types';

/**
 * Point out the record affected by the last confirmed operation. The server
 * flashes `highlight` only after the change is committed; when the record is
 * not part of the current page or filters, `missing` lets the page offer a
 * way to locate it instead of silently hiding it.
 */
export function useRecordHighlight(visibleIds: () => number[]) {
    const highlightedId = ref<number | null>(null);
    const missing = ref<Highlight | null>(null);
    let timer: ReturnType<typeof setTimeout> | undefined;

    const stop = router.on('flash', (event) => {
        const highlight = event.detail.flash?.highlight as
            | Highlight
            | undefined;

        if (!highlight) {
            return;
        }

        void nextTick(() => {
            clearTimeout(timer);

            if (visibleIds().includes(highlight.id)) {
                missing.value = null;
                highlightedId.value = highlight.id;
                timer = setTimeout(() => (highlightedId.value = null), 4000);

                return;
            }

            missing.value = highlight;
        });
    });

    onUnmounted(() => {
        stop();
        clearTimeout(timer);
    });

    function dismissMissing(): void {
        missing.value = null;
    }

    return { highlightedId, missing, dismissMissing };
}
