import type { DataScopeValue } from './admin';

export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    is_active?: boolean;
    last_login_at?: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
    role: {
        id: number;
        label: string;
        scope: DataScopeValue;
        is_root: boolean;
        is_active: boolean;
    } | null;
    permissions: string[];
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};

/**
 * Effective password policy (Password::defaults()) shared by the server so
 * forms can check it while typing. "uncompromised" is only checked by the
 * server.
 */
export type PasswordPolicy = {
    min: number;
    max: number | null;
    letters: boolean;
    mixed_case: boolean;
    numbers: boolean;
    symbols: boolean;
    uncompromised: boolean;
};
