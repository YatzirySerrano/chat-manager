/**
 * Preview cards of the links written in messages, as the server reads
 * them (LinkPreviewController). Each message's endpoint is asked at most
 * once per page load (shared by the chat, its preview dialog and every
 * re-render); a failed request is forgotten so it can be tried again
 * later. Nothing here ever contacts the linked site: the server does.
 */

export type LinkPreviewData = {
    url: string;
    status: 'ready' | 'failed';
    domain: string;
    title: string | null;
    description: string | null;
    site_name: string | null;
    /** A Google Maps (or Apple Maps) link. */
    maps: boolean;
    /** The point a Maps link names, when known. */
    location: { latitude: number; longitude: number } | null;
    /** Kept copies on this server (signed), never the site's own. */
    image_url: string | null;
    image_width: number | null;
    image_height: number | null;
    icon_url: string | null;
};

const known = new Map<string, Promise<LinkPreviewData | null>>();

/**
 * The preview of the message behind `endpoint`, or null when it has none.
 * `url` (the address the chat sees in the text) is part of the key, so an
 * edited message asks again.
 */
export function loadLinkPreview(
    endpoint: string,
    url: string,
): Promise<LinkPreviewData | null> {
    const key = `${endpoint} ${url}`;
    const cached = known.get(key);

    if (cached) {
        return cached;
    }

    const request = fetch(endpoint, {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    })
        .then(async (response) => {
            if (!response.ok) {
                throw new Error(String(response.status));
            }

            const body = (await response.json()) as {
                preview: LinkPreviewData | null;
            };

            return body.preview ?? null;
        })
        .catch(() => {
            known.delete(key);

            return null;
        });

    known.set(key, request);

    return request;
}
