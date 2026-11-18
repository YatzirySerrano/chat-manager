import type { AppColor } from './admin';

/** One numbered option of a connection's welcome menu. */
export type ChatbotMenuOption = {
    department_id: number;
    /** Text shown in the menu; empty uses the department's name. */
    label: string;
    /** Reply once chosen; empty uses the department's welcome (or away) message. */
    reply_message: string;
};

export type ChatbotMenu = {
    is_enabled: boolean;
    greeting: string;
    retry_message: string;
    max_attempts: number;
    options: ChatbotMenuOption[];
    updated_at: string | null;
};

export type ChatbotConnection = {
    id: number;
    name: string;
    channel: { value: string; label: string };
    is_whatsapp_web: boolean;
    is_active: boolean;
    phone: string | null;
    /** Departments the connection may route to; empty means any. */
    department_ids: number[];
    menu: ChatbotMenu;
    /** Menus offered in the last days, by how they ended. */
    activity: { routed: number; fallback: number; awaiting: number };
    can: { update: boolean };
};

export type ChatbotDepartment = {
    id: number;
    name: string;
    color: AppColor;
    is_active: boolean;
    has_welcome: boolean;
};

export type ChatbotKeywordRule = {
    id: number;
    name: string;
    keywords: string[];
    reply_message: string;
    is_active: boolean;
    throttle_minutes: number;
    /** Empty: every connection. */
    connection_ids: number[];
    /** Empty: every department. */
    department_ids: number[];
    /** Replies sent in the last days. */
    replies: number;
    updated_at: string | null;
    updated_by: string | null;
};

export type ChatbotVariable = {
    token: string;
    label: string;
    example: string;
};

export type ChatbotLimits = {
    message: number;
    retry: number;
    label: number;
    options: number;
    attempts: number;
    name: number;
    keywords: number;
    keyword: number;
    throttle: number;
};
