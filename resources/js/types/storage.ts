export type StorageState = 'ok' | 'warning' | 'down';

export type StorageDiskKind = 'nas' | 's3' | 'local' | 'other';

export type StorageCheck = {
    key: string;
    label: string;
    status: 'ok' | 'fail' | 'skipped';
    detail: string;
};

export type StorageHour = {
    hour: string;
    files: number;
    bytes: number;
};

export type StorageRecentFile = {
    key: string;
    kind: 'image' | 'video' | 'audio' | 'document' | 'sticker';
    name: string;
    size_bytes: number | null;
    saved_at: string | null;
    source: string;
};

export type StorageOtherDisk = {
    disk: string;
    label: string;
    files: number;
    bytes: number;
};

export type StorageNasState =
    | 'operational'
    | 'degraded'
    | 'intermittent'
    | 'disconnected'
    | 'unmounted'
    | 'recovering'
    | 'unknown';

export type StorageIncident = {
    state: StorageNasState;
    previous_state: StorageNasState | null;
    problem: string | null;
    latency_ms: number | null;
    at: string;
};

/** Local first: files kept on the server first and copied to the NAS. */
export type StorageLocalFirst = {
    server: {
        total_bytes: number | null;
        free_bytes: number | null;
        reserved_bytes: number;
        pending_max_bytes: number;
        cache_max_bytes: number;
        retention_days: number;
        last_write_at: string | null;
    };
    nas: {
        state: StorageNasState;
        problem: string | null;
        explanation: string | null;
        latency_ms: number | null;
        checked_at: string | null;
        paused_seconds: number | null;
    };
    sync: {
        pending_files: number;
        pending_bytes: number;
        oldest_pending_at: string | null;
        synced_files: number;
        cached_files: number;
        cached_bytes: number;
        conflict_files: number;
        synced_last_hour: number;
        bytes_per_second: number | null;
        last_sync_at: string | null;
        queued_sync: number | null;
        queued_fetch: number | null;
    };
    downloads: {
        pending: number;
        waiting_storage: number;
        unavailable_storage: number;
        unavailable_other: number;
    };
    incidents: StorageIncident[];
};

export type StorageStatus = {
    state: StorageState;
    headline: string;
    message: string;
    problem: string | null;
    disk: {
        name: string;
        kind: StorageDiskKind;
        label: string;
        location: string | null;
        required: boolean;
        external: boolean;
    };
    checked_at: string | null;
    check_age_seconds: number | null;
    checks: StorageCheck[];
    usage: {
        disk: string;
        used_bytes: number;
        quota_bytes: number;
        percent: number | null;
        level: 'ok' | 'warning' | 'exceeded' | 'unlimited';
        warning_percent: number;
    };
    activity: {
        total_files: number;
        files_today: number;
        bytes_today: number;
        files_7d: number;
        pending: number;
        failed_24h: number;
        last_saved_at: string | null;
        hourly: StorageHour[];
        recent: StorageRecentFile[];
        other_disks: StorageOtherDisk[];
    };
    local_first: StorageLocalFirst | null;
    now: string;
};
