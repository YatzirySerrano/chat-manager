<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Whaticket history importer
    |--------------------------------------------------------------------------
    |
    | Limits of the history importer (Fase 5.2). Uploaded files live on the
    | private imports disk (config/imports.php "disk") under "directory",
    | never in a public folder and never in the database, and are deleted
    | when the batch finishes or expires. Reading and execution run on the
    | exports queue (config/exports.php "queue").
    |
    */

    'directory' => env('HISTORY_IMPORT_DIRECTORY', 'history-imports'),

    // Maximum upload size in kilobytes (Whaticket exports up to 450,000
    // messages per CSV; media ZIPs may be large).
    'max_kilobytes' => (int) env('HISTORY_IMPORT_MAX_KILOBYTES', 512000),

    // Rows of one CSV / spreadsheet (header not counted). Whaticket splits
    // message exports above 450,000 rows into several files.
    'max_rows' => (int) env('HISTORY_IMPORT_MAX_ROWS', 460000),

    'max_columns' => (int) env('HISTORY_IMPORT_MAX_COLUMNS', 80),

    // Characters kept of one message text (longer texts are cut and flagged).
    'max_body_characters' => (int) env('HISTORY_IMPORT_MAX_BODY_CHARACTERS', 10000),

    // ZIP protection (media ZIPs and .xlsx packages): entries, total
    // uncompressed size, size of one entry and the largest compression
    // ratio accepted for entries above 1 MB (zip bombs).
    'zip' => [
        'max_entries' => (int) env('HISTORY_IMPORT_ZIP_MAX_ENTRIES', 20000),
        'max_uncompressed_bytes' => (int) env('HISTORY_IMPORT_ZIP_MAX_UNCOMPRESSED_BYTES', 4 * 1024 * 1024 * 1024),
        'max_entry_bytes' => (int) env('HISTORY_IMPORT_ZIP_MAX_ENTRY_BYTES', 100 * 1024 * 1024),
        'max_compression_ratio' => (int) env('HISTORY_IMPORT_ZIP_MAX_COMPRESSION_RATIO', 100),
        // Chat.txt is read in memory: its own cap.
        'max_chat_text_bytes' => (int) env('HISTORY_IMPORT_MAX_CHAT_TEXT_BYTES', 64 * 1024 * 1024),
    ],

    // Largest historical file copied from a ZIP. The WhatsApp send limits do
    // not apply to history; the type is always detected from the content.
    'max_media_bytes' => (int) env('HISTORY_IMPORT_MAX_MEDIA_BYTES', 100 * 1024 * 1024),

    // Longest CSV record (bytes) read at once: an unterminated quoted field
    // can never pull the whole file into memory.
    'max_record_bytes' => (int) env('HISTORY_IMPORT_MAX_RECORD_BYTES', 1024 * 1024),

    // Times a conversation that failed is retried by «Reanudar».
    'max_attempts' => (int) env('HISTORY_IMPORT_MAX_ATTEMPTS', 3),

    // Rows written to the staging tables per database round.
    'parse_batch_size' => (int) env('HISTORY_IMPORT_PARSE_BATCH_SIZE', 1000),

    // Conversations executed per chunk of the queued job; the job runs for
    // at most "job_seconds" and then queues itself again to continue.
    'execute_chunk_size' => (int) env('HISTORY_IMPORT_EXECUTE_CHUNK_SIZE', 50),

    'job_seconds' => (int) env('HISTORY_IMPORT_JOB_SECONDS', 240),

    // Days a batch may stay unconfirmed (or unfinished) before its files and
    // staging rows are deleted (history-import:prune, daily).
    'retention_days' => (int) env('HISTORY_IMPORT_RETENTION_DAYS', 30),

    // Inventory page size of the classification screen.
    'per_page' => 50,

    // Identifier of the Whaticket installation used when the admin gives none.
    'default_source_instance' => 'whaticket',

];
