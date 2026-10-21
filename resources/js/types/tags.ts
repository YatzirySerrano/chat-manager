import type { ColorSwatch } from './admin';

/**
 * Minimal tag shape used by badges and pickers (structurally compatible with
 * ContactTag).
 */
export type TagOption = {
    id: number;
    name: string;
    color: ColorSwatch;
    is_active: boolean;
};

export type TagSummary = TagOption & {
    slug: string;
    description: string | null;
    contacts_count?: number;
    conversations_count?: number;
    created_at: string | null;
    can: { update: boolean; changeStatus: boolean };
};

export type TagFilters = {
    search: string;
    status: 'active' | 'inactive' | null;
};
