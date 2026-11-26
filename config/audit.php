<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Audit log retention
    |--------------------------------------------------------------------------
    |
    | The audit_logs table is append-only and grows forever otherwise. The
    | scheduled command «audit:prune» deletes entries older than this many
    | days once a day. 0 disables the retention (keep every entry forever).
    |
    | The permanent, manual bulk deletion from the «Auditoría» screen (for the
    | Superadministrador) is independent of this and honors its own cutoff.
    |
    */

    'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 180),

    // Rows deleted per statement, so pruning a huge table never holds a long
    // lock: the command repeats until nothing older than the cutoff is left.
    'prune_chunk' => (int) env('AUDIT_PRUNE_CHUNK', 5000),

];
