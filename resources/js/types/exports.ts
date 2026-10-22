export type ExportFormat = 'xlsx' | 'pdf';

export type DataTransferStatus =
    | 'queued'
    | 'processing'
    | 'completed'
    | 'failed'
    | 'expired';

/**
 * A file generated (or being generated) for the current user.
 */
export type DataTransfer = {
    id: number;
    type: 'export' | 'import';
    report: string;
    report_title: string;
    format: { value: ExportFormat; label: string };
    status: { value: DataTransferStatus; label: string };
    /** Human readable filters applied when the export was requested. */
    filters: string[];
    file_name: string | null;
    rows_total: number | null;
    error: string | null;
    created_at: string | null;
    finished_at: string | null;
    expires_at: string | null;
    /** Present only while the file can be downloaded. */
    download_url: string | null;
};

/**
 * Filters of a module list sent as query parameters to an export. Empty
 * values are dropped; the server validates the rest.
 */
export type ExportFilters = Record<
    string,
    string | number | boolean | null | undefined | (string | number)[]
>;
