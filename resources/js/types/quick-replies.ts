import type { DepartmentOption } from './admin';

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
    is_global: boolean;
    departments: DepartmentOption[];
    updated_at: string | null;
    can: {
        update: boolean;
        changeStatus: boolean;
        delete: boolean;
    };
};

export type QuickReplyFilters = {
    search: string;
    department: number | null;
    status: 'active' | 'inactive' | null;
    availability: 'global' | 'departments' | null;
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
    /** Departments of the reply that the user reaches (empty when global). */
    departments: { id: number; name: string }[];
    text: string;
    missing: QuickReplyVariableValue[];
};
