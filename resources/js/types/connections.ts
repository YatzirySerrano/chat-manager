import type { ColorSwatch } from './admin';

export type ConnectionHealthValue =
    | 'inactive'
    | 'not_configured'
    | 'configured'
    | 'credentials_invalid'
    | 'credentials_verified'
    | 'receiving'
    | 'operational'
    | 'error';

export type ConnectionTone =
    | 'success'
    | 'primary'
    | 'warning'
    | 'danger'
    | 'neutral';

export type ConnectionHealthDescriptor = {
    value: ConnectionHealthValue;
    label: string;
    description: string;
    tone: ConnectionTone;
};

export type ConnectionCredentialState =
    | 'missing'
    | 'unverified'
    | 'verified'
    | 'invalid';

export type ConnectionMessageKind = 'welcome' | 'away' | 'farewell';

export type ConnectionSecretField =
    | 'access_token'
    | 'app_secret'
    | 'webhook_verify_token';

export type ConnectionDepartment = {
    id: number;
    name: string;
    color: ColorSwatch;
    is_active: boolean;
    is_default: boolean;
};

export type Connection = {
    id: number;
    name: string;
    channel: { value: string; label: string };
    provider: { value: string; label: string };
    display_phone_number: string | null;
    phone_number_id: string | null;
    business_account_id: string | null;
    meta_app_id: string | null;
    verified_name: string | null;
    is_active: boolean;
    secrets: Record<ConnectionSecretField, boolean>;
    has_required_credentials: boolean;
    default_department_id: number | null;
    departments: ConnectionDepartment[];
    messages: Record<
        ConnectionMessageKind,
        { enabled: boolean; text: string | null }
    >;
    reopen_window_minutes: number | null;
    health: ConnectionHealthDescriptor;
    configuration_health: ConnectionHealthDescriptor;
    checks: {
        configuration_saved: boolean;
        credentials: {
            state: ConnectionCredentialState;
            verified_at: string | null;
            error: string | null;
        };
        webhook_verified_at: string | null;
        last_inbound_at: string | null;
        last_outbound_at: string | null;
        last_error: { at: string; message: string | null } | null;
    };
    meta: {
        phone_status: string | null;
        quality_rating: string | null;
        code_verification_status: string | null;
        platform_type: string | null;
    };
    last_activity_at: string | null;
    created_at: string | null;
    updated_at: string | null;
};

export type ConnectionFilters = {
    search: string;
    channel: string | null;
    health: ConnectionHealthValue | null;
    department: number | null;
    status: 'active' | 'inactive' | null;
};

export type ConnectionStats = {
    total: number;
    active: number;
    verified: number;
    attention: number;
};

export type ConnectionOption = { value: string; label: string };
