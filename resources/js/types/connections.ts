import type { AppColor } from './admin';
import type { RecordStateValue } from './ui';

/** Channel a connection reaches customers through (ConnectionChannel on the server). */
export type ConnectionChannel =
    | 'whatsapp'
    | 'web_chat'
    | 'messenger'
    | 'instagram';

export type WebChatPosition = 'right' | 'left';

export type WebChatIdentityMode = 'anonymous' | 'optional' | 'required';

export type WebChatAvailabilityMode = 'always' | 'business_hours';

export type WebChatPrechatField = 'name' | 'email';

/**
 * Widget settings of a web chat connection, as the administration shows
 * them. The identity secret is never sent: only whether one is set.
 */
export type WebChatWidgetSettings = {
    public_key: string;
    allowed_origins: string[];
    title: string;
    subtitle: string | null;
    welcome_text: string | null;
    offline_text: string | null;
    position: WebChatPosition;
    accent_color: string;
    launcher_enabled: boolean;
    launcher_label: string | null;
    avatar_url: string | null;
    identity_mode: WebChatIdentityMode;
    prechat: {
        fields: WebChatPrechatField[];
        required: boolean;
        privacy_notice: string | null;
    };
    availability_mode: WebChatAvailabilityMode;
    session_idle_days: number;
    /** Embed snippet with the public key and the application URL. */
    snippet: string;
    frame_url: string;
    identity_secret_set: boolean;
    key_rotated_at: string | null;
};

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
    color: AppColor;
    is_active: boolean;
    is_default: boolean;
};

export type Connection = {
    id: number;
    name: string;
    channel: { value: ConnectionChannel; label: string };
    provider: { value: string; label: string };
    display_phone_number: string | null;
    phone_number_id: string | null;
    business_account_id: string | null;
    meta_app_id: string | null;
    verified_name: string | null;
    /** Operational activation with Meta (Activar / Desactivar). */
    is_active: boolean;
    /** Logical deletion (Eliminar / Restaurar), independent of `is_active`. */
    is_deleted: boolean;
    deleted_at: string | null;
    /** Conversations that depend on the connection (kept when deleted). */
    conversations_count?: number;
    secrets: Record<ConnectionSecretField, boolean>;
    has_required_credentials: boolean;
    default_department_id: number | null;
    departments: ConnectionDepartment[];
    messages: Record<
        ConnectionMessageKind,
        { enabled: boolean; text: string | null }
    >;
    reopen_window_minutes: number | null;
    /** Widget settings of a web chat connection (absent or null for WhatsApp). */
    web_chat_widget?: WebChatWidgetSettings | null;
    /** Account authorized with Meta (only Messenger and Instagram connections). */
    meta_account?: MetaAccount;
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
    /** What the viewer may do with this connection (resolved by the server). */
    can: {
        view: boolean;
        update: boolean;
        changeStatus: boolean;
        verify: boolean;
        delete: boolean;
        restore: boolean;
    };
};

export type ConnectionFilters = {
    search: string;
    channel: string | null;
    health: ConnectionHealthValue | null;
    department: number | null;
    status: 'active' | 'inactive' | null;
    record: RecordStateValue;
};

export type ConnectionStats = {
    total: number;
    active: number;
    verified: number;
    attention: number;
};

export type ConnectionOption = { value: string; label: string };

/** Activation checklist of a connection (evidence only, never secrets). */
export type ConnectionDiagnostics = {
    transport: {
        mode: 'meta' | 'simulated' | 'unavailable';
        label: string;
        /** Recipients of the controlled pilot (0: no restriction). */
        pilot_recipients: number;
    };
    webhook: {
        url: string | null;
        https: boolean;
        verify_token_saved: boolean;
        app_secret_saved: boolean;
        verified_at: string | null;
        last_received_at: string | null;
        fields: string[];
        events_24h: { processed: number; pending: number; failed: number };
        last_failure: { at: string; error: string | null } | null;
    };
    subscription: {
        state: 'subscribed' | 'not_subscribed' | 'unknown';
        checked_at: string | null;
        meta_app_id_saved: boolean;
    };
    templates: { approved: number; last_synced_at: string | null };
    outbound: {
        unconfirmed: number;
        /** Replies of unknown delivery in the last `unknown_days` days. */
        unknown: number;
        unknown_days: number;
        /** Affected conversations the viewer can open (at most 10). */
        unknown_conversations: {
            id: number;
            contact: string | null;
            messages: number;
            last_failed_at: string | null;
        }[];
    };
    signals: { field: string; at: string | null; event: string | null }[];
};

/** State of the Meta authorization of a Messenger / Instagram connection. */
export type MetaAccountState =
    | 'authorized'
    | 'expired'
    | 'invalid'
    | 'disconnected';

export type MetaScope = { value: string; label: string };

/** Facebook page or Instagram professional account of a connection. */
export type MetaAccount = {
    id: string | null;
    name: string | null;
    picture: string | null;
    authorized: boolean;
    state: MetaAccountState;
    granted_scopes: MetaScope[];
    authorized_at: string | null;
    authorized_by?: string | null;
    token_expires_at: string | null;
    subscribed: boolean | null;
    subscription_checked_at: string | null;
    subscribed_fields: string[];
};

/** Whether the server has the Meta app configured for a channel. */
export type MetaChannelAvailability = {
    configured: boolean;
    missing: string[];
};

/** An account Meta returned after authorizing, waiting to be chosen. */
export type MetaAccountCandidate = {
    id: string;
    name: string;
    username: string | null;
    picture: string | null;
    can_message: boolean;
    scopes: MetaScope[];
    existing: { id: number; name: string; is_deleted: boolean } | null;
};
