/**
 * Contacts importer (reserved to the Superadministrador).
 */
export type ContactImportStatus =
    | 'draft'
    | 'queued'
    | 'processing'
    | 'completed'
    | 'failed'
    | 'expired';

export type ContactImportSummary = {
    created: number;
    updated: number;
    skipped: number;
    failed: number;
};

export type ContactImport = {
    id: number;
    status: { value: ContactImportStatus; label: string };
    file: string | null;
    rows_total: number | null;
    rows_processed: number | null;
    rows_failed: number | null;
    summary: ContactImportSummary | null;
    error: string | null;
    created_at: string | null;
    finished_at: string | null;
    expires_at: string | null;
    report_url: string | null;
    url: string;
    source: { value: string; label: string; validated: boolean };
    rollback: ImportRollbackState;
};

/**
 * Source adapters of the importer (App\Services\Migration). A source that
 * is not validated is a heuristic pending a real export of that system.
 */
export type ImportSourceOption = {
    value: string;
    label: string;
    description: string;
    validated: boolean;
    validation_note: string | null;
};

export type ImportSourceDetection = {
    key: string;
    label: string;
    score: number;
    signals: string[];
};

export type ImportSourceInfo = {
    current: string;
    detection: ImportSourceDetection[];
    suggested_mappings: Record<string, ContactImportMapping>;
};

export type ImportRollbackStatus =
    | 'queued'
    | 'processing'
    | 'completed'
    | 'failed';

export type ImportRollbackSummary = {
    records: number;
    contacts_archived: number;
    contacts_kept: number;
    fields_restored: number;
    fields_kept: number;
    tags_removed: number;
    notes_kept: number;
    reverted: number;
    partial: number;
    kept: number;
};

export type ImportRollbackState = {
    status: ImportRollbackStatus | null;
    requested_at: string | null;
    finished_at: string | null;
    summary: ImportRollbackSummary | null;
    error: string | null;
    available: boolean;
    unavailable_reason: string | null;
};

export type ImportRollbackRecord = {
    line: number | null;
    contact_id: number;
    name: string;
    action: 'created' | 'filled';
    outcome: 'reverted' | 'partial' | 'kept' | null;
    reason: string | null;
};

export type ImportRollbackPreview = {
    summary: ImportRollbackSummary;
    kept: ImportRollbackRecord[];
    limit: number;
};

export type ContactImportField = 'name' | 'phone' | 'email' | 'tags' | 'notes';

export type ContactImportDecision = 'skip' | 'fill_empty';

export type ContactImportMapping = Record<ContactImportField, number | null>;

export type ContactImportPreviewRow = {
    line: number;
    status: 'valid' | 'duplicate' | 'error';
    name: string;
    phone: string;
    email: string;
    tags: string[];
    notes: string | null;
    errors: string[];
    warnings: string[];
    duplicate_of_line: number | null;
    existing: { id: number; name: string }[];
};

export type ContactImportPreview = {
    summary: {
        total: number;
        valid: number;
        new: number;
        existing: number;
        duplicates_in_file: number;
        errors: number;
        warnings: number;
    };
    mapping_errors: string[];
    rows: ContactImportPreviewRow[];
    issues: ContactImportPreviewRow[];
    limit: number;
};

export type ContactImportMappingOptions = {
    headers: string[];
    fields: { value: ContactImportField; label: string }[];
    current: ContactImportMapping;
    decision: ContactImportDecision;
    decisions: { value: ContactImportDecision; label: string }[];
};
