/** «Inteligencia artificial» (AiController::index). */

export type AiVendorValue = 'anthropic' | 'openai';

export interface AiVendor {
    value: AiVendorValue;
    label: string;
    default_model: string;
    models: { value: string; label: string }[];
    console_url: string;
}

export interface AiCatalogEntry {
    question: string;
    answer: string;
}

export interface AiSettings {
    provider: AiVendorValue;
    model: string;
    has_api_key: boolean;
    api_key_hint: string | null;
    business_info: string;
    catalog: AiCatalogEntry[];
    instructions: string;
    assistant_name: string;
    handoff_message: string;
    max_replies: number;
    is_ready: boolean;
    last_error: { message: string; at: string | null } | null;
    last_reply_at: string | null;
}

export interface AiConnection {
    id: number;
    name: string;
    channel: string;
    is_active: boolean;
    /** False for Meta connections («Próximamente»). */
    supported: boolean;
    is_enabled: boolean;
    handoff_department_id: number | null;
    only_outside_hours: boolean;
    replies: number;
}

export interface AiLimits {
    business_info: number;
    catalog: number;
    question: number;
    answer: number;
    instructions: number;
    handoff: number;
    max_replies: number;
}
