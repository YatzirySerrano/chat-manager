import { ref, watch } from 'vue';
import type { Ref } from 'vue';

/** Rows that may enter at once and still animate (a live update, not a page). */
const MAX_ENTERING = 6;
/** Longer lists swap without motion: measuring every row would cost frames. */
const MAX_ROWS = 150;

/**
 * Whether a list's TransitionGroup should animate the change about to be
 * rendered. Only small live changes do (a conversation arrives, a chat moves
 * up, a row leaves after being accepted): a new filter, a new page of rows,
 * the first render or a long list swap at once, so the person never waits
 * for an animation to see their results. Runs before the render (flush
 * 'pre'), so the TransitionGroup already knows when it patches the rows.
 *
 * Use the returned name as the TransitionGroup `name`: "filter-row" when it
 * animates, a name without styles when it does not.
 */
export function useRowMotion(
    rows: () => readonly { id: number }[],
    /** Changes whenever the filters change (their object from the server). */
    filters: () => unknown,
): Ref<string> {
    const name = ref('filter-row-off');
    let previousFilters = filters();

    watch(
        rows,
        (next, previous) => {
            const currentFilters = filters();
            const filtered = currentFilters !== previousFilters;

            previousFilters = currentFilters;

            if (
                filtered ||
                !previous ||
                previous.length === 0 ||
                next.length > MAX_ROWS ||
                previous.length > MAX_ROWS
            ) {
                name.value = 'filter-row-off';

                return;
            }

            const known = new Set(previous.map((row) => row.id));
            const entering = next.filter((row) => !known.has(row.id)).length;

            name.value =
                entering > MAX_ENTERING ? 'filter-row-off' : 'filter-row';
        },
        { flush: 'pre' },
    );

    return name;
}
