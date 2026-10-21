import { usePage } from '@inertiajs/vue3';
import type { Ref } from 'vue';
import { ref, watch } from 'vue';
import type { ViewMode } from '@/types';

const DEFAULT_MODE: ViewMode = 'table';

function isViewMode(value: unknown): value is ViewMode {
    return value === 'table' || value === 'cards';
}

function read(key: string): ViewMode {
    try {
        const stored =
            typeof window === 'undefined'
                ? null
                : window.localStorage.getItem(key);

        return isViewMode(stored) ? stored : DEFAULT_MODE;
    } catch {
        return DEFAULT_MODE;
    }
}

function write(key: string, mode: ViewMode): void {
    try {
        window.localStorage.setItem(key, mode);
    } catch {
        // Storage can be unavailable (private mode, blocked site data); the
        // choice is simply not remembered.
    }
}

/**
 * Table or cards presentation of a list module. The choice is remembered
 * per user and module in this browser; switching is purely visual, so it
 * never reloads the data nor touches filters, pagination or scroll.
 */
export function useViewMode(moduleKey: string): { mode: Ref<ViewMode> } {
    const page = usePage();
    const userId = page.props.auth?.user?.id ?? 'guest';
    const storageKey = `chat-manager:view-mode:${userId}:${moduleKey}`;

    const mode = ref<ViewMode>(read(storageKey));

    watch(mode, (value) => write(storageKey, value));

    return { mode };
}
