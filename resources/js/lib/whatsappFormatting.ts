/**
 * WhatsApp text formatting parser shared by every message preview. It only
 * produces segments that components render with text interpolation, so user
 * content is never interpreted as HTML.
 */

export type TextSegment = {
    text: string;
    bold: boolean;
    italic: boolean;
    strike: boolean;
    mono: boolean;
};

type Styles = Omit<TextSegment, 'text'>;

const PLAIN: Styles = {
    bold: false,
    italic: false,
    strike: false,
    mono: false,
};

const INLINE_MARKERS: { marker: string; style: keyof Styles }[] = [
    { marker: '*', style: 'bold' },
    { marker: '_', style: 'italic' },
    { marker: '~', style: 'strike' },
];

function escapeForRegExp(value: string): string {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * Inline styles follow WhatsApp's rules: the marker must wrap non-blank text
 * that does not start or end with a space, within one line.
 */
function parseInline(text: string, styles: Styles): TextSegment[] {
    if (text === '') {
        return [];
    }

    let earliest: {
        index: number;
        length: number;
        inner: string;
        style: keyof Styles;
    } | null = null;

    for (const { marker, style } of INLINE_MARKERS) {
        const m = escapeForRegExp(marker);
        const pattern = new RegExp(
            `${m}([^\\s${m}](?:[^${m}\\n]*?[^\\s${m}])?)${m}`,
        );
        const match = pattern.exec(text);

        if (match && (earliest === null || match.index < earliest.index)) {
            earliest = {
                index: match.index,
                length: match[0].length,
                inner: match[1],
                style,
            };
        }
    }

    if (earliest === null) {
        return [{ text, ...styles }];
    }

    return [
        ...(earliest.index > 0
            ? [{ text: text.slice(0, earliest.index), ...styles }]
            : []),
        ...parseInline(earliest.inner, { ...styles, [earliest.style]: true }),
        ...parseInline(text.slice(earliest.index + earliest.length), styles),
    ];
}

/**
 * Split a message into styled segments: ```monospace``` blocks first, then
 * *bold*, _italic_ and ~strikethrough~ (nestable). Line breaks are kept in the
 * segment text, so render it with `white-space: pre-wrap`.
 */
export function parseWhatsAppText(text: string): TextSegment[] {
    const segments: TextSegment[] = [];
    const parts = text.split(/(```[\s\S]+?```)/);

    for (const part of parts) {
        if (part.startsWith('```') && part.endsWith('```') && part.length > 6) {
            segments.push({ text: part.slice(3, -3), ...PLAIN, mono: true });
        } else {
            segments.push(...parseInline(part, PLAIN));
        }
    }

    return segments.filter((segment) => segment.text !== '');
}
