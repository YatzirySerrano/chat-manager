import type { ConnectionChannel } from './connections';

/** Capability an integration API token may hold (App\Enums\ApiAbility). */
export type ApiAbilityValue =
    | 'conversations:read'
    | 'messages:read'
    | 'messages:send';

export type ApiTokenStatus = 'active' | 'expired' | 'revoked';

/** An integration API token as Administración > Integraciones lists it. */
export type ApiTokenSummary = {
    id: number;
    name: string;
    /** Public part of the token (cm_live_{public_id}); the secret is never listed. */
    prefix: string;
    abilities: ApiAbilityValue[];
    connections: { id: number; name: string }[];
    /** null: every department of its connections. */
    departments: { id: number; name: string }[] | null;
    /** null: any IP. */
    allowed_ips: string[] | null;
    status: ApiTokenStatus;
    expires_at: string;
    last_used_at: string | null;
    created_at: string | null;
    created_by: string | null;
    revoked_at: string | null;
    revoked_by: string | null;
    revoke_reason: string | null;
};

export type ApiAbilityOption = { value: ApiAbilityValue; label: string };

export type ApiTokenConnectionOption = {
    id: number;
    name: string;
    channel: ConnectionChannel;
    channel_label: string;
};

export type ApiTokenExpiry = {
    default: string;
    min: string;
    max: string;
    max_days: number;
};

/** One-time flash right after issuing a token: the only time its secret is shown. */
export type IssuedApiToken = {
    id: number;
    name: string;
    token: string;
};
