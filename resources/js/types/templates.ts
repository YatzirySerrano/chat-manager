import type { RecordStateValue } from './ui';

export type MessageTemplateStatusValue =
    | 'APPROVED'
    | 'PENDING'
    | 'REJECTED'
    | 'PAUSED'
    | 'DISABLED'
    | 'IN_APPEAL'
    | 'PENDING_DELETION'
    | 'DELETED'
    | 'LIMIT_EXCEEDED'
    | 'ARCHIVED'
    | 'UNKNOWN';

export type TemplateTone =
    | 'success'
    | 'primary'
    | 'warning'
    | 'danger'
    | 'neutral';

export type TemplateParameter = {
    /** "header.1", "body.1", "body.customer_name", "button.0.1", "header.media". */
    key: string;
    component: 'header' | 'body' | 'button';
    label: string;
    kind: 'text' | 'media_url';
    example: string | null;
    max: number;
};

export type TemplateButton = {
    type: string;
    text: string;
    url: string | null;
    phone_number: string | null;
};

/** A WhatsApp template exactly as Meta reports it (synchronized). */
export type MessageTemplateSummary = {
    id: number;
    name: string;
    language: string;
    category: { value: string | null; label: string | null };
    status: {
        value: MessageTemplateStatusValue;
        label: string;
        tone: TemplateTone;
    };
    quality_score: string | null;
    rejected_reason: string | null;
    connection?: {
        id: number;
        name: string;
        display_phone_number: string | null;
    };
    structure: {
        header: { format: string; text: string | null } | null;
        body: string | null;
        footer: string | null;
        buttons: TemplateButton[];
    };
    parameters: TemplateParameter[];
    /** Rendered with Meta's examples. */
    preview: string;
    /** Approved by Meta and fillable from the inbox. */
    sendable: boolean;
    unsupported_reason: string | null;
    is_deleted: boolean;
    deleted_at: string | null;
    status_updated_at: string | null;
    last_synced_at: string | null;
};

export type MessageTemplateFilters = {
    search: string | null;
    connection: number | null;
    status: MessageTemplateStatusValue | null;
    category: string | null;
    language: string | null;
    record: RecordStateValue;
};

export type TemplateConnectionOption = {
    id: number;
    name: string;
    display_phone_number: string | null;
    can_sync: boolean;
    last_synced_at: string | null;
};
