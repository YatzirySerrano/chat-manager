import type { AppColor } from './admin';
import type { RecordStateValue } from './ui';

/**
 * Minimal tag shape used by badges and pickers (structurally compatible with
 * ContactTag).
 */
export type TagOption = {
    id: number;
    name: string;
    color: AppColor;
    is_active: boolean;
};

export type TagSummary = TagOption & {
    is_deleted: boolean;
    slug: string;
    description: string | null;
    contacts_count?: number;
    conversations_count?: number;
    created_at: string | null;
    can: { update: boolean; delete: boolean; restore: boolean };
};

export type TagFilters = {
    search: string;
    record: RecordStateValue;
};
