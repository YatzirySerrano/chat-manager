import type { DepartmentOption } from './admin';
import type { RecordStateValue } from './ui';

export type QuickReplyVariableValue =
    | 'contacto.nombre'
    | 'agente.nombre'
    | 'departamento.nombre'
    | 'empresa.nombre';

export type QuickReplyVariableOption = {
    value: QuickReplyVariableValue;
    token: string;
    label: string;
    example: string;
};

export type QuickReplySummary = {
    id: number;
    title: string;
    shortcut: string;
    body: string;
    is_active: boolean;
    is_deleted: boolean;
    is_global: boolean;
    /** Personal replies belong to one user and are never shared. */
    is_personal: boolean;
    /** Whether the personal reply belongs to the viewer. */
    is_own: boolean;
    /** Owner of another user's personal reply (supervision segment only). */
    owner?: { id: number; name: string } | null;
    departments: DepartmentOption[];
    updated_at: string | null;
    can: {
        update: boolean;
        delete: boolean;
        restore: boolean;
    };
};

export type QuickReplySegment = 'corporate' | 'mine' | 'others';

export type QuickReplySort = 'shortcut' | 'title' | 'recent';

export type QuickReplySegmentOption = {
    value: QuickReplySegment;
    label: string;
    count: number;
};

export type QuickReplyFilters = {
    segment: QuickReplySegment;
    search: string;
    /** Department of the reply, or of its owner in the "others" segment. */
    department: number | null;
    owner: number | null;
    record: RecordStateValue;
    availability: 'global' | 'departments' | null;
    sort: QuickReplySort;
};

/**
 * A reply returned by the "available" endpoint, already rendered for the
 * current context.
 */
export type AvailableQuickReply = {
    id: number;
    title: string;
    shortcut: string;
    body: string;
    is_global: boolean;
    /** One of the user's own personal replies. */
    is_personal: boolean;
    /** Departments of the reply that the user reaches (empty when global). */
    departments: { id: number; name: string }[];
    text: string;
    missing: QuickReplyVariableValue[];
};
