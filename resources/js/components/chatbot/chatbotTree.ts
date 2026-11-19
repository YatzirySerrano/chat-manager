import type { ChatbotMenuOption, ChatbotOptionAction } from '@/types';

/** An option while it is edited: a stable key and whether its optional reply is open. */
export type ChatbotOptionRow = Omit<ChatbotMenuOption, 'children'> & {
    key: number;
    showReply: boolean;
    children: ChatbotOptionRow[];
};

let nextKey = 0;

export const ACTION_CHOICES: {
    value: ChatbotOptionAction;
    label: string;
    description: string;
}[] = [
    {
        value: 'submenu',
        label: 'Abre un submenú',
        description: 'Muestra otra lista de opciones',
    },
    {
        value: 'reply_stay',
        label: 'Contesta y sigue en el menú',
        description: 'Informa y deja elegir otra opción',
    },
    {
        value: 'reply',
        label: 'Contesta y termina',
        description: 'Responde y la toma un agente',
    },
    {
        value: 'transfer',
        label: 'Pasa a un departamento',
        description: 'Cambia la conversación de departamento',
    },
];

export function toRows(options: ChatbotMenuOption[]): ChatbotOptionRow[] {
    return options.map((option) => ({
        ...option,
        key: nextKey++,
        showReply: option.reply_message.trim() !== '',
        children: toRows(option.children ?? []),
    }));
}

export function newOption(action: ChatbotOptionAction): ChatbotOptionRow {
    return {
        action,
        department_id: null,
        label: '',
        reply_message: '',
        key: nextKey++,
        showReply: false,
        children: [],
    };
}

/** The tree as the server expects it (no editor-only fields). */
export function toPayload(options: ChatbotOptionRow[]): ChatbotMenuOption[] {
    return options.map((option) => ({
        action: option.action,
        department_id:
            option.action === 'transfer' ? option.department_id : null,
        label: option.label,
        reply_message:
            option.action === 'transfer' && !option.showReply
                ? ''
                : option.reply_message,
        children: option.action === 'submenu' ? toPayload(option.children) : [],
    }));
}

/** Whether the customer would see the option now (as ChatbotMenus decides). */
export function optionWorks(
    option: ChatbotOptionRow,
    routable: (departmentId: number | null) => boolean,
): boolean {
    if (option.action === 'transfer') {
        return routable(option.department_id);
    }

    if (option.action === 'submenu') {
        return option.children.some((child) => optionWorks(child, routable));
    }

    return true;
}
