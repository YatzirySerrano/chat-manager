import type { ConnectionHealthDescriptor } from '@/types/connections';
import type { PaginationMeta } from '@/types/admin';

export type HealthStatusValue = 'ok' | 'warning' | 'fail' | 'skipped';

export type SystemHealthCheck = {
    key: string;
    label: string;
    status: HealthStatusValue;
    status_label: string;
    summary: string;
    details: Record<string, string | number | boolean | null>;
};

export type SystemQueueStats = {
    responsibility: string;
    label: string;
    name: string;
    pending: number | null;
    delayed: number | null;
    reserved: number | null;
    oldest_pending_age: number | null;
    status: HealthStatusValue;
    summary: string;
};

export type SystemFailedJob = {
    uuid: string;
    job: string;
    job_class: string;
    queue: string;
    connection: string;
    failed_at: string | null;
    exception: string;
    retryable: boolean;
};

export type SystemRecentError = {
    at: string | null;
    level: string;
    message: string;
};

export type SystemConnectionHealth = {
    id: number;
    name: string;
    phone: string | null;
    is_active: boolean;
    health: ConnectionHealthDescriptor;
    last_webhook_at: string | null;
    last_inbound_at: string | null;
    last_outbound_at: string | null;
    last_error_at: string | null;
    last_error: string | null;
    can_verify: boolean;
};

export type SystemPageProps = {
    overall: HealthStatusValue;
    checks: SystemHealthCheck[];
    queueConnection: string;
    queuesAsync: boolean;
    queues: SystemQueueStats[];
    failedJobs: { data: SystemFailedJob[]; meta: PaginationMeta } | null;
    failedQueues: string[];
    filters: { queue: string | null };
    scheduler: {
        last_beat_at: string | null;
        age_seconds: number | null;
        stale_after_seconds: number;
    };
    recentErrors: { file: string | null; entries: SystemRecentError[] };
    connections: SystemConnectionHealth[] | null;
    environment: {
        app_env: string;
        debug: boolean;
        php: string;
        laravel: string;
        cache: string;
        broadcasting: string;
        whatsapp_transport: string;
    };
    canManage: boolean;
    checkedAt: string;
};
