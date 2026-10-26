import type {
    ConversationMessage,
    ConversationTimelinePage,
} from './conversations';

/**
 * Whaticket history importer (admin area). Shapes mirror the backend
 * contract (HistoryImportController and its siblings); every format is
 * "not verified" until an authentic export is checked.
 */

export type HistoryDecision = 'import' | 'archive' | 'exclude';

export type HistoryBatchStatus =
    | 'draft'
    | 'queued'
    | 'processing'
    | 'completed'
    | 'failed'
    | 'expired';

export type HistoryFileKind = 'chats' | 'messages' | 'contacts' | 'media';

export type HistoryFileStatus =
    | 'uploaded'
    | 'parsing'
    | 'parsed'
    | 'failed'
    | 'purged';

export type HistoryRowState =
    | 'pending'
    | 'done'
    | 'duplicate'
    | 'skipped'
    | 'failed';

export type HistoryBatchFile = {
    id: number;
    kind: HistoryFileKind;
    kind_label: string;
    status: HistoryFileStatus;
    status_label: string;
    name: string;
    size_bytes: number;
    format: string | null;
    verified_format: false;
    headers: string[];
    /** Logical field → column index (null: without column). */
    mapping: Record<string, number | null>;
    fields: { value: string; label: string; required: boolean }[];
    unknown_headers: string[];
    missing_fields: string[];
    media_entries: number;
    rows: { total: number; accepted: number; rejected: number };
    issues: { code: string; count: number; lines: number[]; message: string }[];
    error: string | null;
    parsed_at: string | null;
};

export type HistoryBatchResult = {
    /** `completed_with_errors`: some conversations failed and can be retried. */
    outcome?: 'completed' | 'completed_with_errors';
    /** Failed conversations «Reintentar fallidas» (resume) would try again. */
    retryable?: number;
    imported: number;
    archived: number;
    duplicates: number;
    failed: number;
    excluded: number;
    messages: number;
    attachments_available: number;
    attachments_missing: number;
    contacts_created: number;
    contacts_saved: number;
};

export type HistoryBatch = {
    id: number;
    label: string | null;
    status: HistoryBatchStatus;
    status_label: string;
    source_instance: string;
    timezone: string;
    owner: string | null;
    created_at: string | null;
    started_at: string | null;
    finished_at: string | null;
    expires_at: string | null;
    progress: { total: number; processed: number; failed: number };
    error: string | null;
    result: HistoryBatchResult | null;
    files: HistoryBatchFile[];
};

export type HistoryBatchListItem = Omit<HistoryBatch, 'files'> & {
    files_count: number;
};

export type HistorySummaryBlocker = {
    code:
        | 'empty_batch'
        | 'files_pending'
        | 'undecided'
        | 'blocking_issues'
        | 'unmapped_connections'
        | 'storage_unavailable';
    message: string;
};

export type HistorySummary = {
    totals: {
        conversations: number;
        import: number;
        archive: number;
        exclude: number;
        undecided: number;
    };
    contacts: {
        involved: number;
        existing: number;
        new: number;
        to_save: number;
    };
    messages: { import: number; archive: number; total: number };
    attachments: {
        recoverable: number;
        missing: number;
        estimated_bytes: number;
    };
    duplicates: number;
    groups: number;
    issues: { blocking: number; warnings: number };
    /** '' = «sin conexión indicada». */
    unmapped_connections: string[];
    files: { pending: number; failed: number; parsed: number };
    ready: boolean;
    blockers: HistorySummaryBlocker[];
    confirmation_token: string;
};

export type HistoryFacets = {
    agents: string[];
    departments: string[];
    connections: string[];
    statuses: string[];
    tags: string[];
};

export type HistoryConnectionTarget = 'connection' | 'placeholder';

export type HistoryMappingRows = {
    connections: {
        source: string;
        conversations: number;
        target: HistoryConnectionTarget | null;
        connection_id: number | null;
    }[];
    departments: {
        source: string;
        conversations: number;
        target_id: number | null;
    }[];
    agents: {
        source: string;
        conversations: number;
        target_id: number | null;
    }[];
    tags: { source: string; conversations: number; target_id: number | null }[];
};

/** Inventory filters (also bulk targets and rule criteria). */
export type HistoryFilters = {
    search?: string;
    date_from?: string;
    date_to?: string;
    inactive_since?: string;
    agent?: string;
    department?: string;
    connection?: string;
    status?: string;
    tag?: string;
    decision?: HistoryDecision | 'undecided';
    kind?: 'ticket' | 'contact' | 'chat_txt';
    state?: HistoryRowState;
    duplicates?: boolean;
    empty?: boolean;
    with_attachments?: boolean;
    missing_attachments?: boolean;
    group?: boolean;
    has_issues?: boolean;
};

export type HistoryRule = {
    id: number;
    name: string;
    criteria: HistoryFilters;
    decision: HistoryDecision;
    decision_label: string;
    overwrite: boolean;
    matched: number;
    applied: number;
    applied_at: string | null;
    created_by: string | null;
};

export type HistoryRuleIssue = {
    code: string;
    severity: 'error' | 'warning';
    message: string;
};

export type HistoryInventoryRow = {
    id: number;
    source_key: string;
    kind: 'ticket' | 'contact' | 'chat_txt';
    ticket_id: string | null;
    contact: {
        name: string | null;
        phone: string | null;
        identifier: string | null;
    };
    is_group: boolean;
    participants: string[];
    business_participants: string[];
    opened_at: string | null;
    closed_at: string | null;
    first_message_at: string | null;
    last_message_at: string | null;
    messages: { total: number; inbound: number; outbound: number };
    attachments: {
        total: number;
        available: number;
        missing: number;
        bytes: number;
    };
    source: {
        agent: string | null;
        department: string | null;
        connection: string | null;
        tags: string[];
        status: string | null;
        details: Record<string, string>;
    };
    in_chats_report: boolean;
    already_imported: boolean;
    issues: HistoryRuleIssue[];
    /** Only a suggestion: the administrator decides. */
    recommendation: null | { decision: HistoryDecision; reason: string };
    decision: HistoryDecision | null;
    decision_source: 'manual' | 'bulk' | 'rule' | null;
    decided_at: string | null;
    applied_rule_id: number | null;
    allowed_decisions: HistoryDecision[];
    save_contact: boolean;
    state: HistoryRowState;
    result: {
        conversation_id: number | null;
        contact_id: number | null;
        error: string | null;
        processed_at: string | null;
    };
};

export type HistoryPageMeta = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
};

export type HistoryInventoryPage = {
    data: HistoryInventoryRow[];
    meta: HistoryPageMeta;
};

export type HistoryPreviewMessage = {
    id: number;
    at: string | null;
    from_me: boolean;
    sender: string | null;
    type: string;
    body: string | null;
    file: { name: string; available: boolean } | null;
};

export type HistoryOptions = {
    connections: {
        id: number;
        name: string;
        channel: string;
        active: boolean;
    }[];
    departments: { id: number; name: string }[];
    agents: { id: number; name: string }[];
    tags: { id: number; name: string }[];
    decisions: { value: HistoryDecision; label: string }[];
    kinds: { value: HistoryFileKind; label: string; extensions: string[] }[];
};

/* Archived (imported) history, read only. */

export type ArchivedConversation = {
    id: number;
    origin: 'whaticket';
    origin_label: string;
    destination: 'archive' | 'import';
    read_only: true;
    contact: {
        id: number;
        name: string | null;
        phone: string | null;
        saved: boolean;
    } | null;
    department: { id: number; name: string } | null;
    agent: { id: number; name: string } | null;
    connection: { id: number; name: string; historical: boolean } | null;
    tags: { id: number; name: string }[];
    started_at: string | null;
    last_message_at: string | null;
    closed_at: string | null;
    archived_at: string | null;
    messages_count: number;
    batch_id: number;
    source: Record<string, string | string[] | null | undefined>;
};

export type ArchivedConversationPage = {
    data: ArchivedConversation[];
    meta: HistoryPageMeta;
};

export type ArchiveFilters = {
    destination?: 'archive' | 'import' | 'all';
    search?: string;
    date_from?: string;
    date_to?: string;
    department_id?: number;
    agent_id?: number;
    tag_id?: number;
    connection_id?: number;
    batch_id?: number;
    contact_id?: number;
};

export type ArchiveOptions = {
    departments: { id: number; name: string }[];
    agents: { id: number; name: string }[];
    tags: { id: number; name: string }[];
    connections: { id: number; name: string }[];
    batches: { id: number; label: string | null; finished_at: string | null }[];
    destinations: { value: 'archive' | 'import' | 'all'; label: string }[];
};

/** A message of the imported history (`imported` tells who wrote it in Whaticket). */
export type ImportedMessage = ConversationMessage;

export type ArchivedTimelinePage = Omit<
    ConversationTimelinePage,
    'messages'
> & {
    messages: ImportedMessage[];
};
