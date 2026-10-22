import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import type { RecordStateValue } from '@/types';
import type { RouteQueryOptions } from '@/wayfinder';

/**
 * "Estado del registro" filter of a list without other filters: reloads the
 * page with `?record=` (omitted for the default, the active records).
 */
export function useRecordStateFilter(
    index: { url: (options?: RouteQueryOptions) => string },
    initial: RecordStateValue,
    only: string[],
) {
    const record = ref<RecordStateValue>(initial);

    function apply(): void {
        router.get(
            index.url({
                query:
                    record.value === 'active' ? {} : { record: record.value },
            }),
            undefined,
            { preserveState: true, preserveScroll: true, replace: true, only },
        );
    }

    return { record, apply };
}
