<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Contacts importer
    |--------------------------------------------------------------------------
    |
    | Limits of the contacts importer (reserved to the Superadministrador).
    | Files are stored on a private disk and removed once processed or when
    | the import expires. Processing runs on the exports queue
    | (config/exports.php "queue" and "job_timeout").
    |
    */

    'disk' => env('IMPORTS_DISK', 'local'),

    'directory' => env('IMPORTS_DIRECTORY', 'imports'),

    // Maximum upload size in kilobytes.
    'max_kilobytes' => (int) env('IMPORTS_MAX_KILOBYTES', 5120),

    // Data rows (the header row is not counted) and columns of the file.
    'max_rows' => (int) env('IMPORTS_MAX_ROWS', 5000),

    'max_columns' => (int) env('IMPORTS_MAX_COLUMNS', 30),

    // Zip-bomb protection for .xlsx files: total uncompressed size of the
    // package, entries in it and the largest compression ratio accepted.
    'max_uncompressed_bytes' => (int) env('IMPORTS_MAX_UNCOMPRESSED_BYTES', 50 * 1024 * 1024),

    'max_zip_entries' => (int) env('IMPORTS_MAX_ZIP_ENTRIES', 200),

    'max_compression_ratio' => (int) env('IMPORTS_MAX_COMPRESSION_RATIO', 200),

    // Rows processed per database round of the queued job.
    'batch_size' => (int) env('IMPORTS_BATCH_SIZE', 200),

    // Rows shown in the preview step.
    'preview_rows' => 50,

];
