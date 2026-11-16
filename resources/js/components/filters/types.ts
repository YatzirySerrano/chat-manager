import type { LucideIcon } from '@lucide/vue';

/** One tab of FilterTabs (an exclusive view of a list). */
export type FilterTabOption<T extends string = string> = {
    value: T;
    label: string;
    /** Live counter shown in the tab; omitted for views without one. */
    count?: number | null;
    icon?: LucideIcon;
    /** Longer explanation (tooltip). */
    title?: string;
    /** What the counter means, for screen readers («3 por aceptar»). */
    srLabel?: string;
    /** Work waits here: warning tone, bolder label, halo when it grows. */
    attention?: boolean;
    /** Extra attributes for tests and the guided tour (data-*). */
    attrs?: Record<string, string>;
};

/** An applied filter shown as a removable pill (ActiveFilters). */
export type ActiveFilterPill = {
    key: string;
    label: string;
    remove: () => void;
};
