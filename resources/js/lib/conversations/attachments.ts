import { formatFileSize } from '@/lib/format';
import type { AttachmentKindRule, ChannelAttachmentRules } from '@/types';

/**
 * Attachment picker of the composer, driven by the channel policy the
 * server sends (ChannelAttachmentPolicy): the menu categories, the `accept`
 * hint of each one and the checks a chosen file goes through before it is
 * uploaded (the server checks the real bytes again). A file is never sent
 * as another kind of message to get around a limit: an image that is too
 * big is not turned into a document. The only other way is the secure
 * download link, and only for the kinds the channel delivers that way.
 */

export type AttachmentCategory = 'image' | 'video' | 'document' | 'audio';

type CategoryText = {
    /** Menu entry. */
    menu: string;
    /** "una imagen", to say what a file is. */
    article: string;
    /** "como imagen", to say how it would be sent. */
    as: string;
    /** "Esta imagen", to start a sentence about the chosen file. */
    subject: string;
    /** Short label on the attachment card. */
    label: string;
};

export const CATEGORY_TEXT: Record<AttachmentCategory, CategoryText> = {
    image: {
        menu: 'Fotos e imágenes',
        article: 'una imagen',
        as: 'como imagen',
        subject: 'Esta imagen',
        label: 'Imagen',
    },
    video: {
        menu: 'Videos',
        article: 'un video',
        as: 'como video',
        subject: 'Este video',
        label: 'Video',
    },
    document: {
        menu: 'Documentos',
        article: 'un documento',
        as: 'como documento',
        subject: 'Este documento',
        label: 'Documento',
    },
    audio: {
        menu: 'Audio',
        article: 'un audio',
        as: 'como audio',
        subject: 'Este audio',
        label: 'Audio',
    },
};

/** Menu order (as in WhatsApp). */
const ORDER: AttachmentCategory[] = ['image', 'video', 'document', 'audio'];

/** The menu category of a kind (the server may send it; otherwise its message type). */
export function categoryOf(rule: AttachmentKindRule): AttachmentCategory {
    return rule.category ?? rule.type;
}

export type CategoryOption = {
    category: AttachmentCategory;
    label: string;
    /** "JPG, PNG · hasta 5 MB", from the channel rules. */
    hint: string;
    kinds: AttachmentKindRule[];
    /** The chooser's `accept` hint (the server's, when it sends one). */
    accept: string;
};

function extensionList(kinds: AttachmentKindRule[]): string {
    return [
        ...new Set(
            kinds.flatMap((rule) =>
                rule.extensions.map((extension) => extension.toUpperCase()),
            ),
        ),
    ].join(', ');
}

/**
 * The categories the channel can send. Audio only appears when the channel
 * delivers audio natively (a download link is not a voice message).
 */
export function categoryOptions(
    rules: ChannelAttachmentRules | null,
): CategoryOption[] {
    const kinds = rules?.kinds ?? [];

    return ORDER.flatMap((category) => {
        const own = kinds.filter((rule) => categoryOf(rule) === category);
        const native = own.filter((rule) => rule.delivery === 'native');
        const linked = own.filter((rule) => rule.delivery === 'link');

        if (
            native.length === 0 &&
            (category === 'audio' || linked.length === 0)
        ) {
            return [];
        }

        const parts: string[] = [];

        if (native.length > 0) {
            const max = Math.max(...native.map((rule) => rule.max_bytes));
            parts.push(
                `${extensionList(native)} · hasta ${formatFileSize(max)}`,
            );
        }

        if (linked.length > 0) {
            parts.push(`${extensionList(linked)} como enlace privado`);
        }

        return [
            {
                category,
                label: CATEGORY_TEXT[category].menu,
                hint: parts.join(' · '),
                kinds: category === 'audio' ? native : own,
                accept:
                    rules?.categories?.find((entry) => entry.key === category)
                        ?.accept ??
                    acceptFor(category === 'audio' ? native : own),
            },
        ];
    });
}

/**
 * The `accept` hint of the file chooser: the types and extensions of one
 * category, or of every kind when none was chosen. It is only a hint: the
 * system dialog may still offer other files, which are checked afterwards.
 */
export function acceptFor(kinds: AttachmentKindRule[]): string {
    return [
        ...new Set([
            ...kinds.flatMap((rule) => rule.mimes),
            ...kinds.flatMap((rule) =>
                rule.extensions.map((extension) => `.${extension}`),
            ),
        ]),
    ].join(',');
}

/** Names browsers give to types the server accepts under another name. */
const BROWSER_ALIASES: Record<string, string> = {
    'audio/x-m4a': 'audio/mp4',
    'audio/m4a': 'audio/mp4',
    'audio/mp3': 'audio/mpeg',
    'audio/x-aac': 'audio/aac',
    'audio/x-wav': 'audio/wav',
    'audio/wave': 'audio/wav',
    'audio/opus': 'audio/ogg',
    'video/3gp': 'video/3gpp',
    'video/avi': 'video/x-msvideo',
    'image/jpg': 'image/jpeg',
    'application/x-zip-compressed': 'application/zip',
    'application/x-zip': 'application/zip',
};

export function extensionOf(file: File): string {
    const dot = file.name.lastIndexOf('.');

    return dot > 0 ? file.name.slice(dot + 1).toLowerCase() : '';
}

/**
 * The kind of the file for this channel: by the browser's type, or by its
 * extension when the browser could not tell (the server reads the bytes).
 */
export function ruleFor(
    file: File,
    kinds: AttachmentKindRule[],
): AttachmentKindRule | undefined {
    const type = BROWSER_ALIASES[file.type] ?? file.type;
    const extension = extensionOf(file);

    if (type !== '' && type !== 'application/octet-stream') {
        const byType = kinds.filter((candidate) =>
            candidate.mimes.includes(type),
        );

        return (
            byType.find((candidate) =>
                candidate.extensions.includes(extension),
            ) ?? byType[0]
        );
    }

    return kinds.find((candidate) => candidate.extensions.includes(extension));
}

/** "imágenes (JPG, PNG), documentos (PDF, DOCX) o audios (MP3)". */
export function acceptedSummary(kinds: AttachmentKindRule[]): string {
    const parts = kinds.map(
        (rule) =>
            `${rule.label} (${rule.extensions.map((extension) => extension.toUpperCase()).join(', ')})${rule.delivery === 'link' ? ' como enlace de descarga' : ''}`,
    );
    const last = parts.pop();

    return parts.length > 0 ? `${parts.join(', ')} o ${last}` : (last ?? '');
}

export type AttachmentEvaluation = {
    /** The kind of the file on this channel, when it has one. */
    rule: AttachmentKindRule | undefined;
    /** What the file is (its kind's category), or the chosen one when unknown. */
    category: AttachmentCategory;
    /** Why the file cannot be sent; null when it can. */
    error: string | null;
    /** How it would reach the customer when it can be sent. */
    delivery: 'native' | 'link' | null;
};

/** Words for a kind on the card and in messages ("Archivo ZIP" for archives). */
export function kindLabel(
    rule: AttachmentKindRule | undefined,
    category: AttachmentCategory,
): string {
    return rule?.key === 'archive'
        ? 'Archivo ZIP'
        : CATEGORY_TEXT[category].label;
}

function subjectOf(
    rule: AttachmentKindRule,
    category: AttachmentCategory,
): string {
    return rule.key === 'archive'
        ? 'Este archivo ZIP'
        : CATEGORY_TEXT[category].subject;
}

/**
 * Checks a chosen file against the channel rules and, when the agent
 * picked a category in the menu, against that category: a photo chosen
 * under «Videos» is refused with a pointer to the right entry, never sent
 * as something else.
 */
export function evaluateAttachment(
    file: File,
    rules: ChannelAttachmentRules | null,
    chosen: AttachmentCategory | null,
    channelLabel: string,
): AttachmentEvaluation {
    const kinds = rules?.kinds ?? [];
    const rule = ruleFor(file, kinds);
    const extension = extensionOf(file);
    const fallback = chosen ?? 'document';

    if (kinds.length === 0) {
        return {
            rule: undefined,
            category: fallback,
            error: `Por ${channelLabel} no se pueden enviar archivos desde aquí.`,
            delivery: null,
        };
    }

    if (!rule) {
        const options = chosen
            ? categoryOptions(rules).find(
                  (option) => option.category === chosen,
              )
            : undefined;

        return {
            rule: undefined,
            category: fallback,
            error: chosen
                ? `Este tipo de archivo no se puede enviar ${CATEGORY_TEXT[chosen].as} por ${channelLabel}.${options ? ` Puedes adjuntar ${acceptedSummary(options.kinds)}.` : ''}`
                : `Este tipo de archivo no se puede enviar por ${channelLabel}. Puedes adjuntar ${acceptedSummary(kinds)}.`,
            delivery: null,
        };
    }

    const category = categoryOf(rule);

    if (chosen && category !== chosen) {
        const available = categoryOptions(rules).some(
            (option) => option.category === category,
        );

        return {
            rule,
            category,
            error: available
                ? `Este archivo es ${CATEGORY_TEXT[category].article}, no ${CATEGORY_TEXT[chosen].article}. Para enviarlo, elige «${CATEGORY_TEXT[category].menu}» en el menú de adjuntar.`
                : `Este tipo de archivo no se puede enviar ${CATEGORY_TEXT[chosen].as} por ${channelLabel}.`,
            delivery: null,
        };
    }

    if (extension !== '' && !rule.extensions.includes(extension)) {
        return {
            rule,
            category,
            error: `La extensión «.${extension}» no corresponde al tipo de archivo. Revisa que sea el archivo correcto.`,
            delivery: null,
        };
    }

    if (file.size > rule.max_bytes) {
        return {
            rule,
            category,
            error:
                rule.delivery === 'link'
                    ? `${subjectOf(rule, category)} supera ${formatFileSize(rule.max_bytes)}, el máximo para enviarlo como enlace privado (pesa ${formatFileSize(file.size)}).`
                    : `${subjectOf(rule, category)} supera ${formatFileSize(rule.max_bytes)}, el máximo que admite ${channelLabel} (pesa ${formatFileSize(file.size)}).`,
            delivery: null,
        };
    }

    return { rule, category, error: null, delivery: rule.delivery };
}

/** Same file chosen again: same name, size and modification time. */
export function fileFingerprint(file: File): string {
    return `${file.name}|${file.size}|${file.lastModified}`;
}

/* Files sent during this session, per conversation (accidental resends). */
const sentFiles = new Map<number, Set<string>>();

export function rememberSentFile(conversationId: number, file: File): void {
    const sent = sentFiles.get(conversationId) ?? new Set<string>();
    sent.add(fileFingerprint(file));
    sentFiles.set(conversationId, sent);
}

export function wasFileSent(conversationId: number, file: File): boolean {
    return sentFiles.get(conversationId)?.has(fileFingerprint(file)) ?? false;
}
