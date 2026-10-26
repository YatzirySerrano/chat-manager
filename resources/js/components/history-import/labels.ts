import { Archive, Ban, Download } from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import type { BadgeVariants } from '@/components/ui/badge';
import { formatDate } from '@/lib/format';
import type {
    HistoryBatchStatus,
    HistoryDecision,
    HistoryFileStatus,
    HistoryFilters,
    HistoryRowState,
} from '@/types/history-import';

/**
 * Texts of the history importer in plain Spanish: destinations and their
 * consequences, states and how a rule's criteria read.
 */

/** Shown wherever the files are read: the format is not verified yet. */
export const FORMAT_DISCLAIMER =
    'Formato basado en la documentación pública de Whaticket; aún no verificado con una exportación real.';

export type DecisionCopy = {
    label: string;
    /** Short verb for buttons and badges. */
    short: string;
    icon: LucideIcon;
    badge: NonNullable<BadgeVariants['variant']>;
    /** What happens, in one sentence. */
    summary: string;
    /** Each consequence, one per line. */
    consequences: string[];
};

export const DECISIONS: Record<HistoryDecision, DecisionCopy> = {
    import: {
        label: 'Importar',
        short: 'Importar',
        icon: Download,
        badge: 'success',
        summary:
            'Se crea como conversación cerrada en Chat Manager, con sus mensajes.',
        consequences: [
            'Aparece en Cerradas, en la búsqueda y en las exportaciones.',
            'No se reabre sola: si el cliente vuelve a escribir, empieza una conversación nueva.',
            'No envía nada al cliente ni notifica a ningún agente.',
            'No cuenta en métricas ni abre la ventana de atención de 24 horas.',
        ],
    },
    archive: {
        label: 'Archivar como histórico',
        short: 'Archivar',
        icon: Archive,
        badge: 'info',
        summary: 'Se guarda solo para consulta en «Historial de Whaticket».',
        consequences: [
            'Solo lectura: no aparece en la bandeja ni en las métricas.',
            'Se consulta y se busca desde «Historial de Whaticket».',
            'No envía nada ni notifica a nadie.',
        ],
    },
    exclude: {
        label: 'No importar',
        short: 'No importar',
        icon: Ban,
        badge: 'muted',
        summary: 'No se crea nada en Chat Manager.',
        consequences: [
            'No se crea ninguna conversación, mensaje ni contacto.',
            'No se borra nada en Whaticket.',
            'Solo queda una referencia mínima en el reporte final.',
        ],
    },
};

export const DECISION_ORDER: HistoryDecision[] = [
    'import',
    'archive',
    'exclude',
];

export function decisionLabel(decision: string | null | undefined): string {
    return decision && decision in DECISIONS
        ? DECISIONS[decision as HistoryDecision].label
        : 'Sin decidir';
}

export const DECISION_SOURCES: Record<string, string> = {
    manual: 'Decisión manual',
    bulk: 'Acción masiva',
    rule: 'Por regla',
};

export const BATCH_STATUS_BADGE: Record<
    HistoryBatchStatus,
    NonNullable<BadgeVariants['variant']>
> = {
    draft: 'muted',
    queued: 'info',
    processing: 'info',
    completed: 'success',
    failed: 'danger',
    expired: 'muted',
};

export const FILE_STATUS_BADGE: Record<
    HistoryFileStatus,
    NonNullable<BadgeVariants['variant']>
> = {
    uploaded: 'info',
    parsing: 'info',
    parsed: 'success',
    failed: 'danger',
    purged: 'muted',
};

export const ROW_STATES: Record<
    HistoryRowState,
    { label: string; badge: NonNullable<BadgeVariants['variant']> }
> = {
    pending: { label: 'Por procesar', badge: 'muted' },
    done: { label: 'Procesada', badge: 'success' },
    duplicate: { label: 'Ya importada antes', badge: 'warning' },
    skipped: { label: 'No importada', badge: 'muted' },
    failed: { label: 'Con error', badge: 'danger' },
};

export const KIND_LABELS: Record<string, string> = {
    ticket: 'Ticket',
    contact: 'Contacto',
    chat_txt: 'Chat.txt',
};

/** Labels of the filter flags (inventory, bulk and rules). */
export const FLAG_LABELS: Record<string, string> = {
    duplicates: 'Posibles duplicados',
    empty: 'Sin mensajes',
    with_attachments: 'Con archivos',
    missing_attachments: 'Con archivos faltantes',
    group: 'Grupos',
    has_issues: 'Con avisos o errores',
};

/** A value of a "sin dato" facet filter. */
export const UNDECIDED_FACET = 'undecided';

function facet(value: string): string {
    return value === UNDECIDED_FACET ? 'sin dato' : `«${value}»`;
}

/**
 * The criteria of a rule (or the current filter) in words, e.g.
 * «Última actividad desde el 1 ene 2025 · Agente «Ana»».
 */
export function describeFilters(filters: HistoryFilters): string[] {
    const parts: string[] = [];

    if (filters.search) {
        parts.push(`Busca «${filters.search}»`);
    }

    if (filters.date_from) {
        parts.push(`Última actividad desde el ${formatDay(filters.date_from)}`);
    }

    if (filters.date_to) {
        parts.push(`Última actividad hasta el ${formatDay(filters.date_to)}`);
    }

    if (filters.inactive_since) {
        parts.push(
            `Sin actividad a partir del ${formatDay(filters.inactive_since)} (o sin mensajes)`,
        );
    }

    if (filters.agent) {
        parts.push(`Agente ${facet(filters.agent)}`);
    }

    if (filters.department) {
        parts.push(`Departamento ${facet(filters.department)}`);
    }

    if (filters.connection) {
        parts.push(`Conexión ${facet(filters.connection)}`);
    }

    if (filters.status) {
        parts.push(`Estado en Whaticket «${filters.status}»`);
    }

    if (filters.tag) {
        parts.push(`Etiqueta «${filters.tag}»`);
    }

    if (filters.decision) {
        parts.push(
            filters.decision === 'undecided'
                ? 'Sin decidir'
                : `Destino: ${decisionLabel(filters.decision)}`,
        );
    }

    if (filters.kind) {
        parts.push(`Tipo: ${KIND_LABELS[filters.kind] ?? filters.kind}`);
    }

    for (const [key, label] of Object.entries(FLAG_LABELS)) {
        if (filters[key as keyof HistoryFilters] === true) {
            parts.push(label);
        }
    }

    return parts;
}

/** A Y-m-d day as «1 ene 2025» (no time zone shift). */
export function formatDay(day: string): string {
    return formatDate(`${day}T12:00:00Z`);
}

/** «1 conversación» / «3 conversaciones». */
export function plural(
    count: number,
    singular: string,
    pluralForm = `${singular}s`,
): string {
    return `${count.toLocaleString('es-MX')} ${count === 1 ? singular : pluralForm}`;
}

export function formatNumber(value: number): string {
    return value.toLocaleString('es-MX');
}

/** Session key of the archive list URL (with its filters), for «Historial». */
export const ARCHIVE_LIST_KEY = 'history.archive.list';

/**
 * Plain words for the inventory issue codes, used when the server sends
 * none (it normally does).
 */
export const ISSUE_MESSAGES: Record<string, string> = {
    empty: 'No tiene mensajes.',
    participants_unidentified:
        'No se sabe qué participantes son la empresa: márcalos abajo.',
    direction_unknown:
        'No se sabe qué mensajes envió la empresa: elige la columna «Enviado por la empresa» en «Corregir columnas».',
    group: 'Es un grupo: solo se puede archivar o no importar.',
    no_contact: 'No tiene un contacto identificable.',
    phone_invalid: 'El número del contacto no es válido.',
    already_imported: 'Ya se importó antes.',
    attachments_missing: 'Algunos archivos no venían en los ZIP subidos.',
    attachments_ambiguous:
        'Algunos archivos aparecen con el mismo nombre en varios ZIP; no se adivina cuál es y quedan como no disponibles.',
};

export function issueMessage(issue: {
    code: string;
    message?: string;
}): string {
    return (
        (issue.message?.trim() || null) ??
        ISSUE_MESSAGES[issue.code] ??
        'Aviso de lectura.'
    );
}
