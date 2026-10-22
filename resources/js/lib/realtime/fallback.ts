import { computed, shallowReactive } from 'vue';

/**
 * Refresh intervals that pages fall back to while real-time is not
 * available, so the status indicator can tell how often data is refreshed.
 */
const intervals = shallowReactive(new Map<symbol, number>());

export function registerFallbackInterval(intervalMs: number): () => void {
    const id = Symbol('fallback');
    intervals.set(id, intervalMs);

    return () => intervals.delete(id);
}

/** Shortest fallback interval of the current page in seconds, or null. */
export const fallbackSeconds = computed<number | null>(() => {
    if (intervals.size === 0) {
        return null;
    }

    return Math.round(Math.min(...intervals.values()) / 1000);
});
