import type { ComputedRef, InjectionKey } from 'vue';
import type { ChatAppearanceTarget, ChatColorKey, ChatColors } from '@/types';

/**
 * Personal chat colors are applied as CSS custom properties on the chat
 * container only (light and dark values side by side; `dark:` utilities
 * pick the right one), so nothing outside the viewer's chat changes and no
 * reload is needed.
 */

/** Whether the surrounding chat has personal colors (bubbles use the vars). */
export const CUSTOM_CHAT_COLORS: InjectionKey<ComputedRef<boolean>> =
    Symbol('customChatColors');

const VARIABLES: Record<ChatColorKey, string> = {
    sent_bg: 'sent-bg',
    sent_text: 'sent-text',
    received_bg: 'received-bg',
    received_text: 'received-text',
    chat_bg: 'chat-bg',
};

/** Custom properties for the chat container (empty without colors). */
export function chatColorVariables(
    colors: ChatColors | null | undefined,
): Record<string, string> {
    if (!colors) {
        return {};
    }

    const style: Record<string, string> = {};

    for (const key of Object.keys(VARIABLES) as ChatColorKey[]) {
        style[`--cm-${VARIABLES[key]}`] = colors.light[key];
        style[`--cm-${VARIABLES[key]}-dark`] = colors.dark[key];
    }

    return style;
}

/** Bubble classes reading the vars (literal strings, seen by Tailwind). */
export const CUSTOM_BUBBLE_CLASSES = {
    sent: 'bg-(--cm-sent-bg) text-(--cm-sent-text) dark:bg-(--cm-sent-bg-dark) dark:text-(--cm-sent-text-dark)',
    received:
        'bg-(--cm-received-bg) text-(--cm-received-text) dark:bg-(--cm-received-bg-dark) dark:text-(--cm-received-text-dark)',
    chat: 'bg-(--cm-chat-bg) dark:bg-(--cm-chat-bg-dark)',
} as const;

export const COLOR_LABELS: Record<ChatColorKey, string> = {
    sent_bg: 'Fondo de tus mensajes',
    sent_text: 'Texto de tus mensajes',
    received_bg: 'Fondo de los mensajes del cliente',
    received_text: 'Texto de los mensajes del cliente',
    chat_bg: 'Fondo del chat',
};

/** In the internal chat the received messages are the colleagues'. */
export const TEAM_COLOR_LABELS: Record<ChatColorKey, string> = {
    ...COLOR_LABELS,
    received_bg: 'Fondo de los mensajes de tus compañeros',
    received_text: 'Texto de los mensajes de tus compañeros',
};

export function colorLabelsFor(
    target: ChatAppearanceTarget,
): Record<ChatColorKey, string> {
    return target.type === 'team_thread' ? TEAM_COLOR_LABELS : COLOR_LABELS;
}
