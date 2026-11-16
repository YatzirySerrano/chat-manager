import {
    AlarmClock,
    ArrowLeftRight,
    AtSign,
    Bell,
    FileDown,
    FileUp,
    Hourglass,
    Inbox,
    MessageCircle,
    MessagesSquare,
    ShieldCheck,
    Unplug,
    UserRoundCheck,
} from '@lucide/vue';
import type { Component } from 'vue';
import type { AppNotification, NotificationKindValue } from '@/types';

/**
 * Semantic tone of a notification's icon: urgent kinds ask for an action
 * (danger, warning), informative ones stay quiet (accent, muted), and a
 * file ready to download is a success.
 */
export type NotificationTone =
    | 'danger'
    | 'warning'
    | 'accent'
    | 'muted'
    | 'success';

type KindMeta = {
    icon: Component;
    /** Short name of the kind, shown next to the time. */
    label: string;
    tone: NotificationTone;
    /** Counted noun for the occurrences chip («3 mensajes»). */
    unit: [singular: string, plural: string];
};

const KINDS: Record<NotificationKindValue, KindMeta> = {
    'conversation.pending': {
        icon: Inbox,
        label: 'Pendiente',
        tone: 'warning',
        unit: ['conversación', 'conversaciones'],
    },
    'conversation.assigned': {
        icon: UserRoundCheck,
        label: 'Asignación',
        tone: 'accent',
        unit: ['asignación', 'asignaciones'],
    },
    'conversation.transferred': {
        icon: ArrowLeftRight,
        label: 'Transferencia',
        tone: 'accent',
        unit: ['transferencia', 'transferencias'],
    },
    'conversation.message': {
        icon: MessageCircle,
        label: 'Mensaje',
        tone: 'muted',
        unit: ['mensaje', 'mensajes'],
    },
    'team-chat.mention': {
        icon: AtSign,
        label: 'Mención',
        tone: 'accent',
        unit: ['mención', 'menciones'],
    },
    'team-chat.message': {
        icon: MessagesSquare,
        label: 'Chat interno',
        tone: 'muted',
        unit: ['mensaje', 'mensajes'],
    },
    'export.finished': {
        icon: FileDown,
        label: 'Exportación',
        tone: 'success',
        unit: ['aviso', 'avisos'],
    },
    'import.finished': {
        icon: FileUp,
        label: 'Importación',
        tone: 'muted',
        unit: ['aviso', 'avisos'],
    },
    'connection.problem': {
        icon: Unplug,
        label: 'Conexión',
        tone: 'danger',
        unit: ['aviso', 'avisos'],
    },
    'account.access-changed': {
        icon: ShieldCheck,
        label: 'Tu acceso',
        tone: 'muted',
        unit: ['cambio', 'cambios'],
    },
    'sla.warning': {
        icon: Hourglass,
        label: 'SLA por vencer',
        tone: 'warning',
        unit: ['aviso', 'avisos'],
    },
    'sla.breach': {
        icon: AlarmClock,
        label: 'SLA vencido',
        tone: 'danger',
        unit: ['recordatorio', 'recordatorios'],
    },
};

const FALLBACK: KindMeta = {
    icon: Bell,
    label: 'Aviso',
    tone: 'muted',
    unit: ['aviso', 'avisos'],
};

type Presentable = Pick<
    AppNotification,
    'kind' | 'level' | 'url' | 'accessible'
>;

export function notificationKind(
    item: Pick<AppNotification, 'kind'>,
): KindMeta {
    return KINDS[item.kind] ?? FALLBACK;
}

/**
 * The tone this notification shows today: an alert that no longer asks for
 * an action (an SLA already answered, a recovered connection presented as
 * informative, no access any more) calms down to muted, and an export
 * without a file to download (failed or expired) is not a success.
 */
export function notificationTone(item: Presentable): NotificationTone {
    const meta = notificationKind(item);

    if (!item.accessible) {
        return 'muted';
    }

    if (item.kind === 'export.finished') {
        return item.url ? 'success' : 'muted';
    }

    if (meta.tone === 'danger' || meta.tone === 'warning') {
        return item.level === 'urgent' || item.kind === 'sla.warning'
            ? meta.tone
            : 'muted';
    }

    return meta.tone;
}

/** «3 mensajes», or null for a single occurrence. */
export function occurrencesText(
    item: Pick<AppNotification, 'kind' | 'occurrences'>,
): string | null {
    if (item.occurrences <= 1) {
        return null;
    }

    const [, plural] = notificationKind(item).unit;

    return `${item.occurrences} ${plural}`;
}

/** The label of the main action, or null when there is nothing to open. */
export function notificationActionLabel(
    item: Pick<AppNotification, 'kind' | 'url'>,
): string | null {
    if (!item.url) {
        return null;
    }

    return item.kind === 'export.finished' ? 'Descargar' : 'Abrir';
}

/** Solid tinted square of each tone (background and icon color). */
export const TONE_CLASSES: Record<NotificationTone, string> = {
    danger: 'bg-destructive-soft text-destructive-soft-foreground',
    warning: 'bg-warning-soft text-warning-soft-foreground',
    accent: 'bg-primary-soft text-primary-soft-foreground',
    success: 'bg-success-soft text-success-soft-foreground',
    muted: 'bg-muted text-muted-foreground',
};
