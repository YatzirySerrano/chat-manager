import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';

export type Appearance = 'light' | 'dark' | 'system';
export type ResolvedAppearance = 'light' | 'dark';

export type AppVariant = 'header' | 'sidebar';

export type FlashToast = {
    type: 'success' | 'info' | 'warning' | 'error';
    message: string;
    /** Optional link shown as the toast action (e.g. "Ver exportaciones"). */
    action?: { label: string; href: string };
};

/**
 * Contextual help shown next to a form field.
 */
export type FieldHelpContent = {
    /** Heading of the help; defaults to the field label. */
    title?: string;
    /** What the field is for. */
    description: string;
    /** How to fill it in. */
    howTo?: string;
    /** A valid example value. */
    example?: string;
    /** Consequence worth knowing before saving. */
    warning?: string;
};

/**
 * How a list module presents its records. The preference is per user and
 * module, and switching never reloads the data.
 */
export type ViewMode = 'table' | 'cards';

/**
 * One operation available on a record (row or card). Build only the actions
 * the server allows (per-record `can` flags); RecordActions renders each one
 * as a visible icon button with a tooltip and an accessible name.
 */
export type RecordAction = {
    /** Stable identifier within the record, e.g. "edit". */
    key: string;
    /** Name of the operation, e.g. "Editar". */
    label: string;
    icon: LucideIcon;
    /** Navigates to a page (rendered as an Inertia link). */
    href?: NonNullable<InertiaLinkProps['href']>;
    /** Runs a client action: opens a dialog or sends a request. */
    onSelect?: () => void;
    /** Irreversible or destructive operation (styled as such). */
    destructive?: boolean;
    disabled?: boolean;
    /**
     * Rarely used operation. It only moves into the "Más acciones" menu
     * when the record has more than four actions.
     */
    secondary?: boolean;
};

/**
 * "Estado del registro" of a list (see App\Enums\RecordState). Deleted
 * records are only listed for people who can consult them: the server sends
 * no options otherwise and always answers with the active records.
 */
export type RecordStateValue = 'active' | 'deleted' | 'all';

export type RecordStateOption = { value: RecordStateValue; label: string };

/** Per-record permissions of a logically deletable record. */
export type DeletionAbilities = { delete: boolean; restore: boolean };
