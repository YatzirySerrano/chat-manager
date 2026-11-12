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
    now: string;
};
