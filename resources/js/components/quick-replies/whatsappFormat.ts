/**
 * Plain-text helpers for quick reply templates. Everything here produces
 * strings that components render with text interpolation, never as HTML.
 */

export type RenderedTemplate = {
    text: string;
    missing: string[];
};

const GAP = '';
const SPACE = '';

/**
 * Client-side mirror of App\Services\QuickReplyRenderer used by previews:
 * replaces only the given catalog variables, never evaluates anything, and
 * reports variables without a value.
 */
export function renderTemplate(
    body: string,
    values: Record<string, string | null | undefined>,
    allowed: string[],
): RenderedTemplate {
    const missing = new Set<string>();

    let text = body
        .replaceAll(GAP, '')
        .replaceAll(SPACE, '')
        .replace(/\{([^{}\r\n]*)\}/g, (token: string, name: string) => {
            if (!allowed.includes(name)) {
                return token;
            }

            const value = (values[name] ?? '').replace(/\s+/g, ' ').trim();

            if (value === '') {
                missing.add(name);

                return GAP;
            }

            return value;
        });

    if (missing.size > 0) {
        text = text
            .replace(
                new RegExp(`[ \\t]*${GAP}(?:[ \\t]*${GAP})*[ \\t]*`, 'g'),
                (run) => (/[ \t]/.test(run) ? SPACE : ''),
            )
            .replace(new RegExp(`${SPACE}(?=[,.;:!?)\\]]|$)`, 'gm'), '')
            .replace(new RegExp(`(^|[(¿¡\\[])${SPACE}`, 'gm'), '$1')
            .replaceAll(SPACE, ' ');
    }

    return { text, missing: [...missing] };
}

/**
 * Tokens between braces that are not catalog variables, plus whether stray
 * braces remain. Used to warn while typing; the backend validates again.
 */
export function inspectBraces(
    body: string,
    allowed: string[],
): { unknown: string[]; strayBraces: boolean } {
    const unknown = new Set<string>();

    for (const match of body.matchAll(/\{([^{}]*)\}/g)) {
        if (!allowed.includes(match[1])) {
            unknown.add(match[0]);
        }
    }

    const withoutTokens = body.replace(/\{[^{}]*\}/g, '');

    return {
        unknown: [...unknown],
        strayBraces: withoutTokens.includes('{') || withoutTokens.includes('}'),
    };
}

/**
 * Whether the text looks like it contains HTML tags (rejected on save).
 */
export function containsHtmlTag(body: string): boolean {
    return /<[\p{L}/!?]/u.test(body);
}
