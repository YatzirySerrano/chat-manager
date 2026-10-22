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
