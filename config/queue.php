<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis",
    |          "deferred", "background", "failover", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            // Above the longest job timeout (exports and imports: EXPORTS_JOB_TIMEOUT),
            // so a running export is never handed to a second worker.
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 900),
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 90),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            // Same rule as the database connection: above the longest worker
            // --timeout (exports), or a running job is handed to a second worker.
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 900),
            // Seconds a worker waits in a blocking pop instead of polling
            // every --sleep. Off by default (REDIS_QUEUE_BLOCK_FOR empty):
            // the pop blocks on the FIRST queue of a multi-queue worker
            // (messages,webhooks) before it looks at the next one, so only
            // enable it when every worker on this connection reads a single
            // queue. 0 would block forever and is treated as off.
            'block_for' => ((int) env('REDIS_QUEUE_BLOCK_FOR', 0)) > 0 ? (int) env('REDIS_QUEUE_BLOCK_FOR') : null,
            // Redis is outside the database transaction: a job dispatched
            // inside one must not reach a worker before the rows it reads
            // are committed. The database driver inserts the job row in the
            // same transaction, so it needs no deferral.
            'after_commit' => (bool) env('REDIS_QUEUE_AFTER_COMMIT', true),
        ],

        'deferred' => [
            'driver' => 'deferred',
        ],

        'background' => [
            'driver' => 'background',
        ],

        'failover' => [
            'driver' => 'failover',
            'connections' => [
                'database',
                'deferred',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Names (App\Support\Queues)
    |--------------------------------------------------------------------------
    |
    | One queue per responsibility, so a burst of one kind of work never
    | delays the critical path. Every queue has dedicated workers (see
    | deploy/supervisor, deploy/systemd and docs/tiempo-real.md):
    | webhooks + messages, realtime, notifications, media, default, exports.
    | REALTIME_QUEUE and EXPORTS_QUEUE override "realtime" and "exports".
    |
    */

    'names' => [
        'webhooks' => env('QUEUE_NAME_WEBHOOKS', 'webhooks'),
        'messages' => env('QUEUE_NAME_MESSAGES', 'messages'),
        'realtime' => env('QUEUE_NAME_REALTIME', 'realtime'),
        'media' => env('QUEUE_NAME_MEDIA', 'media'),
        'notifications' => env('QUEUE_NAME_NOTIFICATIONS', 'notifications'),
        'exports' => env('QUEUE_NAME_EXPORTS', 'exports'),
        'default' => env('QUEUE_NAME_DEFAULT', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'mariadb'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'mariadb'),
        'table' => 'failed_jobs',
    ],

];
