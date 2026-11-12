<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    | Private disk of conversation media (customer files, agent attachments,
    | web chat avatar). Files are stored under media/{yyyy}/{mm}/{uuid} and only
    | served through authorized routes (streamed by the application, never a
    | public URL). MEDIA_DISK=s3 keeps them in a private S3-compatible bucket
    | (the "s3" disk below); each attachment row remembers its own disk, so
    | files saved earlier on "local" keep working after the switch. See
    | config/attachments.php "storage" (MEDIA_DISK_REQUIRED, quota).
    |
    | MEDIA_DISK=nas (opt-in) keeps them on a NAS share mounted on this server
    | (CIFS/SMB, e.g. over Tailscale): the "nas" disk below. Its "mount"
    | section makes MediaDisk verify the mount before every write (kernel
    | mount table, identity marker, cached bounded health probe) and refuse
    | files while it is not verified. See docs/infraestructura-y-despliegue.md.
    |
    | Changing provider: add a NEW disk (e.g. "s3-provider2") and point
    | MEDIA_DISK at it instead of repointing "s3"; keep the old disk defined
    | while rows still reference it (each row stores its disk name).
    */
    'media_disk' => env('MEDIA_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            // Private files (media, team chat, imports, exports) are only served
            // by authorized controllers; no signed "storage/{path}" route.
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            // Private objects only; optional server-side encryption (e.g. AES256)
            // when the provider supports it.
            'visibility' => 'private',
            'options' => array_filter(['ServerSideEncryption' => env('AWS_SERVER_SIDE_ENCRYPTION')]),
            // Only applied when set (otherwise the SDK defaults: no HTTP
            // timeout, checksums "when_supported"). Seconds; some
            // S3-compatible providers need the checksums "when_required".
            ...array_filter([
                'http' => array_map('floatval', array_filter([
                    'connect_timeout' => env('AWS_HTTP_CONNECT_TIMEOUT'),
                    'timeout' => env('AWS_HTTP_TIMEOUT'),
                ], fn (mixed $value): bool => $value !== null && $value !== '')),
                'request_checksum_calculation' => env('AWS_REQUEST_CHECKSUM_CALCULATION'),
                'response_checksum_validation' => env('AWS_RESPONSE_CHECKSUM_VALIDATION'),
            ]),
            'throw' => false,
            'report' => false,
        ],

        /*
        | NAS share mounted on this server (MEDIA_DISK=nas). MEDIA_MOUNT_PATH is
        | the mountpoint itself (absolute, the share's root); MEDIA_MOUNT_MARKER
        | is a file at that root whose content must equal MEDIA_MOUNT_ID, and
        | MEDIA_MOUNT_FSTYPES the filesystem types accepted in the mount table
        | (comma separated; empty = only the stat() device check).
        */
        'nas' => [
            'driver' => 'local',
            'root' => (string) env('MEDIA_MOUNT_PATH', ''),
            'serve' => false,
            'throw' => false,
            'report' => false,
            'mount' => [
                'marker' => env('MEDIA_MOUNT_MARKER', '.chat-manager-storage'),
                'id' => env('MEDIA_MOUNT_ID'),
                'filesystem_types' => env('MEDIA_MOUNT_FSTYPES', 'cifs,smb3'),
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
