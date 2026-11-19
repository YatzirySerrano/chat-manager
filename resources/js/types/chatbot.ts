import type { AppColor } from './admin';
import type { ConnectionHealthDescriptor } from './connections';

/**
 * What an option does when chosen: opens a submenu (its children), answers
 * and keeps the menu open, answers and ends, or sends the conversation to a
 * department.
 */
export type ChatbotOptionAction =
    | 'submenu'
    | 'reply_stay'
    | 'reply'
    | 'transfer';

/** One numbered option of the chatbot menu, at any level. */
export type ChatbotMenuOption = {
    action: ChatbotOptionAction;
    /** Only for «transfer». */
    department_id: number | null;
    /** Text shown in the menu; for a transfer, empty uses the department's name. */
    label: string;
    /** The answer; for a submenu, the text above its options. */
    reply_message: string;
    /** Options of a submenu. */
    children: ChatbotMenuOption[];
};

export type ChatbotMenu = {
    is_enabled: boolean;
    greeting: string;
    retry_message: string;
    /** Shown under the options of a submenu (empty: the default). */
    navigation_hint: string;
    max_attempts: number;
    /** Minutes without an answer before the ordinary routing takes over (0: never). */
    timeout_minutes: number;
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
    /** Real state of the connection (QR session or derived health). */
    status: ConnectionHealthDescriptor;
    /** Lifecycle messages switched on in the connection form. */
    automatic_messages: { welcome: boolean; away: boolean; farewell: boolean };
    /** Departments the connection may route to; empty means any. */
    department_ids: number[];
    menu: ChatbotMenu;
    /** Menus offered in the last days, by how they ended. */
    activity: {
        routed: number;
        answered: number;
        fallback: number;
        awaiting: number;
    };
    can: { update: boolean; view: boolean };
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
    depth: number;
    navigation: number;
    timeout: number;
    attempts: number;
    name: number;
    keywords: number;
    keyword: number;
    throttle: number;
};
