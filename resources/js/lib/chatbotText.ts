/**
 * Variables of the automatic texts as the previews show them: the same
 * names the server replaces (App\Services\Chatbot\ChatbotText), case
 * insensitive and with optional spaces inside the braces, filled with
 * sample values. Whaticket's {{userName}} and {{name}} are aliases of
 * {{nombre}}.
 */
export type ChatbotSample = {
    nombre: string;
    departamento: string;
    conexion: string;
    saludo: string;
};

const ALIASES: Record<string, keyof ChatbotSample> = {
    nombre: 'nombre',
    username: 'nombre',
    name: 'nombre',
    departamento: 'departamento',
    conexion: 'conexion',
    conexión: 'conexion',
    saludo: 'saludo',
};

/** «Buenos días», «Buenas tardes» or «Buenas noches» for the hour given. */
export function greetingFor(hour: number): string {
    if (hour >= 5 && hour < 12) {
        return 'Buenos días';
    }

    return hour >= 12 && hour < 19 ? 'Buenas tardes' : 'Buenas noches';
}

export function defaultSample(
    overrides: Partial<ChatbotSample> = {},
): ChatbotSample {
    return {
        nombre: 'Laura',
        departamento: 'Mesa de control',
        conexion: 'Tu conexión',
        saludo: greetingFor(new Date().getHours()),
        ...overrides,
    };
}

export function renderChatbotText(text: string, sample: ChatbotSample): string {
    return text.replace(
        /\{\{\s*([\p{L}_]+)\s*\}\}/gu,
        (match, name: string) => {
            const key = ALIASES[name.toLowerCase()];

            return key === undefined ? match : sample[key];
        },
    );
}

/** Inserts a token at the caret of a text field, returning the new value. */
export function insertAtCaret(
    element: HTMLTextAreaElement | HTMLInputElement | null,
    value: string,
    token: string,
): { value: string; caret: number } {
    const start = element?.selectionStart ?? value.length;
    const end = element?.selectionEnd ?? value.length;

    return {
        value: value.slice(0, start) + token + value.slice(end),
        caret: start + token.length,
    };
}
