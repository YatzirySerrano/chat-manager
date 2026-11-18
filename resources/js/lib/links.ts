/**
 * Web addresses written in messages: which one gets a preview card, its
 * readable domain, whether it is a Google Maps link and how a list row
 * says it in a few words. Pure helpers shared by the customer chat, the
 * internal chat and their previews. Only http/https addresses count (the
 * same pattern WhatsAppText turns into links and the server reads with
 * LinkPreviews::firstUrl).
 */

export const URL_PATTERN = /https?:\/\/[^\s<>"'`]+[^\s<>"'`.,;:!?)\]*_~]/giu;

/** The first http/https address of a text, or null. */
export function firstUrl(text: string | null | undefined): string | null {
    if (!text) {
        return null;
    }

    const match = text.match(new RegExp(URL_PATTERN.source, 'iu'));

    return match && match[0].length <= 2048 ? match[0] : null;
}

/** "www.tienda.com.mx/ofertas" → "tienda.com.mx"; null when it is not a valid address. */
export function linkDomain(url: string): string | null {
    try {
        const { hostname, protocol } = new URL(url);

        if (protocol !== 'http:' && protocol !== 'https:') {
            return null;
        }

        return hostname.replace(/^www\./iu, '').toLowerCase() || null;
    } catch {
        return null;
    }
}

/** Google Maps (or Apple Maps) links, short ones included. */
export function isMapsUrl(url: string): boolean {
    try {
        const { hostname, pathname } = new URL(url);
        const host = hostname.toLowerCase();

        return (
            host === 'maps.app.goo.gl' ||
            host === 'maps.apple.com' ||
            (host === 'goo.gl' && pathname.startsWith('/maps')) ||
            /^maps\.google\.[a-z.]+$/u.test(host) ||
            (/^(www\.)?google\.[a-z.]+$/u.test(host) &&
                pathname.startsWith('/maps'))
        );
    } catch {
        return false;
    }
}

/**
 * A text with its addresses reduced to their domain, for one-line
 * previews: "Mira https://www.tienda.com/x?y=1" → "Mira 🔗 tienda.com", and
 * a Maps link reads "📍 Ubicación de Google Maps".
 */
export function compactLinks(text: string): string {
    return text.replace(new RegExp(URL_PATTERN.source, 'giu'), (url) => {
        if (isMapsUrl(url)) {
            return '📍 Ubicación de Google Maps';
        }

        const domain = linkDomain(url);

        return domain ? `🔗 ${domain}` : url;
    });
}
