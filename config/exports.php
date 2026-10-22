<?php

use App\Reports\Definitions\AnalyticsReport;
use App\Reports\Definitions\AuditReport;
use App\Reports\Definitions\ConnectionsReport;
use App\Reports\Definitions\ContactsReport;
use App\Reports\Definitions\ConversationDossierReport;
use App\Reports\Definitions\ConversationsReport;
use App\Reports\Definitions\DashboardReport;
use App\Reports\Definitions\DepartmentsReport;
use App\Reports\Definitions\MessageTemplatesReport;
use App\Reports\Definitions\QuickRepliesReport;
use App\Reports\Definitions\RolesReport;
use App\Reports\Definitions\TagsReport;
use App\Reports\Definitions\UsersReport;

return [

    /*
    |--------------------------------------------------------------------------
    | Report catalog
    |--------------------------------------------------------------------------
    |
    | Report key (used in the URL) => definition class. A key that is not
    | listed here is answered with 404. Every definition extends
    | App\Reports\ReportDefinition.
    |
    */

    'reports' => [
        'dashboard' => DashboardReport::class,
        'conversations' => ConversationsReport::class,
        'conversation-dossier' => ConversationDossierReport::class,
        'contacts' => ContactsReport::class,
        'tags' => TagsReport::class,
        'quick-replies' => QuickRepliesReport::class,
        'departments' => DepartmentsReport::class,
        'users' => UsersReport::class,
        'roles' => RolesReport::class,
        'connections' => ConnectionsReport::class,
        'audit' => AuditReport::class,
        'message-templates' => MessageTemplatesReport::class,
        'analytics' => AnalyticsReport::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | Generated files are written to a private disk (never the public one)
    | and served only to their owner through an authorized download route.
    |
    */

    'disk' => env('EXPORTS_DISK', 'local'),

    'directory' => env('EXPORTS_DIRECTORY', 'exports'),

    'retention_hours' => (int) env('EXPORTS_RETENTION_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    */

    /*
    | Queue that processes large exports (and the contacts importer).
    |
    | It defaults to the shared "exports" queue name (QUEUE_NAME_EXPORTS,
    | config/queue.php), the same one the contacts importer uses. Production
    | runs a dedicated worker for it, e.g.
    |   php artisan queue:work --queue=exports --timeout=660 --tries=1
    | and `composer run dev` already listens on every queue locally.
    | EXPORTS_QUEUE still overrides it.
    |
    | The job timeout must stay below the "retry_after" of the queue
    | connection (DB_QUEUE_RETRY_AFTER, config/queue.php), otherwise a long
    | export would be handed to a second worker while the first one is still
    | generating it. The worker's --timeout must be above the job timeout.
    */

    'queue' => env('EXPORTS_QUEUE', env('QUEUE_NAME_EXPORTS', 'exports')),

    'job_timeout' => (int) env('EXPORTS_JOB_TIMEOUT', 600),

    'memory_limit' => env('EXPORTS_MEMORY_LIMIT', '1024M'),

    /*
    |--------------------------------------------------------------------------
    | Row limits per format
    |--------------------------------------------------------------------------
    |
    | Up to "sync_rows" the file is generated during the request and
    | downloaded immediately; above it the export is queued. Above
    | "max_rows" the export is refused and the user must narrow the filters.
    |
    */

    'formats' => [
        'xlsx' => [
            'sync_rows' => (int) env('EXPORTS_XLSX_SYNC_ROWS', 2000),
            'max_rows' => (int) env('EXPORTS_XLSX_MAX_ROWS', 50000),
        ],
        'pdf' => [
            'sync_rows' => (int) env('EXPORTS_PDF_SYNC_ROWS', 500),
            'max_rows' => (int) env('EXPORTS_PDF_MAX_ROWS', 5000),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | PDF rendering (Browsershot + Chrome/Chromium)
    |--------------------------------------------------------------------------
    |
    | Absolute binaries are recommended: queue workers and web servers often
    | run with a reduced PATH. "no_sandbox" is only needed when Chrome runs as
    | root inside a Linux container or VPS without user namespaces.
    |
    */

    'pdf' => [
        'node_binary' => env('EXPORTS_NODE_BINARY', 'node'),
        'npm_binary' => env('EXPORTS_NPM_BINARY', 'npm'),
        'node_modules_path' => env('EXPORTS_NODE_MODULES_PATH', base_path('node_modules')),
        'chrome_path' => env('EXPORTS_CHROME_PATH'),
        'no_sandbox' => (bool) env('EXPORTS_CHROME_NO_SANDBOX', false),
        'timeout' => (int) env('EXPORTS_PDF_TIMEOUT', 120),
    ],

];
