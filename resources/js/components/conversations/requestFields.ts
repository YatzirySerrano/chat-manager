import { inject, provide } from 'vue';
import type { ComputedRef, InjectionKey } from 'vue';
import { toast } from 'vue-sonner';
import type { ConversationMessage, ConversationRequestField } from '@/types';

/**
 * «Datos de la solicitud»: the request data an analyst takes from a message
 * of the field manager («Tomar datos de la solicitud») and keeps on the
 * conversation. The server reads the message (RequestFieldParser) and
 * decides the key of every label; this file only holds what the chat needs
 * to offer the action and show the result.
 */

/** Known labels offered by «Marcar como…» (RequestFieldCatalog). */
export const REQUEST_FIELD_LABELS = [
    'Cliente',
    'Sucursal',
    'Monto anterior',
    'Monto solicitado',
    'Ciclo',
    'Producto',
    'Giro de negocio',
    'Teléfono',
    'Día de pago',
    'Tipo',
    'Monto autorizado',
    'Desembolso',
    'Cantidad a pagar',
    'Plazo',
] as const;

/**
 * «Resolución»: the amounts of the authorization, typed inline in the
 * details panel and also taken from the authorization the team sends
 * (RequestResolutionCapture). Quick replies fill their lines from them.
 */
export const RESOLUTION_FIELDS = [
    { key: 'monto_autorizado', label: 'Monto autorizado' },
    { key: 'desembolso', label: 'Desembolso' },
    { key: 'cantidad_a_pagar', label: 'Cantidad a pagar' },
] as const;

export const RESOLUTION_KEYS: ReadonlySet<string> = new Set(
    RESOLUTION_FIELDS.map((field) => field.key),
);

export const REQUEST_FIELDS_MAX = 20;
export const REQUEST_FIELD_LABEL_MAX = 40;
export const REQUEST_FIELD_VALUE_MAX = 255;

/** A row of the capture or edit dialog. */
export type RequestFieldRow = {
    id: number;
    selected: boolean;
    label: string;
    value: string;
    message_id: number | null;
};

/**
 * Quick check, while the chat is painted, of whether a message reads as a
 * request: at least two lines «Etiqueta: valor» with a value (the server
 * reads it in full when the action is used). It only decides how visible
 * «Tomar datos de la solicitud» is, never whether it is offered.
 */
export function looksLikeRequest(text: string | null | undefined): boolean {
    if (!text) {
        return false;
    }

    let labeled = 0;

    for (const raw of text.split(/\r?\n/).slice(0, 60)) {
        const line = raw.replace(/[*_~`]/g, '').trim();
        const match = /^([^:\d][^:]{0,39}):\s*(.*)$/u.exec(line);

        if (
            match &&
            /\p{L}/u.test(match[1]) &&
            // A time («a las 10:30»), not a label.
            !/\d$/.test(match[1].trim()) &&
            match[2].replace(/^\$\s*/, '').trim() !== ''
        ) {
            labeled++;
        }

        if (labeled >= 2) {
            return true;
        }
    }

    return false;
}

/** What a message bubble needs to offer the action. */
export type RequestCapture = {
    /** Whether the viewer may keep request data here (can.categorize). */
    enabled: ComputedRef<boolean>;
    /** Messages some kept field was taken from. */
    capturedMessageIds: ComputedRef<Set<number>>;
    capture: (message: ConversationMessage) => void;
};

const REQUEST_CAPTURE: InjectionKey<RequestCapture> = Symbol('requestCapture');

/** Offered by the open chat (ConversationChat) to its message bubbles. */
export function provideRequestCapture(capture: RequestCapture): void {
    provide(REQUEST_CAPTURE, capture);
}

/** Null outside an open chat (previews, history): no action there. */
export function useRequestCapture(): RequestCapture | null {
    return inject(REQUEST_CAPTURE, null);
}

export function capturedMessageIds(
    fields: ConversationRequestField[] | undefined,
): Set<number> {
    return new Set(
        (fields ?? [])
            .map((field) => field.message_id)
            .filter((id): id is number => id !== null),
    );
}

export async function copyRequestField(
    field: Pick<ConversationRequestField, 'label' | 'value'>,
): Promise<void> {
    try {
        await navigator.clipboard.writeText(field.value);
        toast.success(`${field.label} copiado`);
    } catch {
        toast.error(
            'No se pudo copiar. Selecciona el texto y cópialo con Ctrl+C.',
        );
    }
}
