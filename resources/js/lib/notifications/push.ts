/**
 * Web Push of this browser: the push subscription with the server's VAPID
 * public key, on the public/sw.js registration (scope '/') that
 * lib/pwa/register.ts also creates on every signed-in page (same URL and
 * scope, so it is one registration). Pushes carry no payload; the service
 * worker fetches the newest unread notification itself. Once the site may
 * show notifications, every signed-in page subscribes on its own
 * (ensurePushSubscription); signing out unsubscribes.
 */

import { store as storePushSubscription } from '@/routes/notifications/push-subscriptions';

export type PushDevice = {
    id: number;
    endpoint_hash: string;
    user_agent: string | null;
    created_at: string | null;
};

export type PushSettings = {
    /** Null when VAPID keys are not configured on the server. */
    public_key: string | null;
    subscriptions: PushDevice[];
};

export type PushSupport = 'supported' | 'insecure' | 'unsupported';

const SERVICE_WORKER_URL = '/sw.js';

export function pushSupport(): PushSupport {
    if (typeof window === 'undefined') {
        return 'unsupported';
    }

    if (!window.isSecureContext) {
        return 'insecure';
    }

    return 'serviceWorker' in navigator &&
        'PushManager' in window &&
        'Notification' in window
        ? 'supported'
        : 'unsupported';
}

function applicationServerKey(base64Url: string): Uint8Array<ArrayBuffer> {
    const base64 = (base64Url + '='.repeat((4 - (base64Url.length % 4)) % 4))
        .replace(/-/g, '+')
        .replace(/_/g, '/');
    const raw = atob(base64);
    const bytes = new Uint8Array(new ArrayBuffer(raw.length));

    for (let index = 0; index < raw.length; index++) {
        bytes[index] = raw.charCodeAt(index);
    }

    return bytes;
}

/**
 * SHA-256 of the endpoint in hex, to recognise this browser in the list of
 * devices the server returns.
 */
export async function endpointHash(endpoint: string): Promise<string> {
    const digest = await crypto.subtle.digest(
        'SHA-256',
        new TextEncoder().encode(endpoint),
    );

    return Array.from(new Uint8Array(digest))
        .map((byte) => byte.toString(16).padStart(2, '0'))
        .join('');
}

/**
 * The current push subscription of this browser, if any (never prompts).
 */
export async function currentPushSubscription(): Promise<PushSubscription | null> {
    if (pushSupport() !== 'supported') {
        return null;
    }

    try {
        const registration = await navigator.serviceWorker.getRegistration('/');

        return (await registration?.pushManager.getSubscription()) ?? null;
    } catch {
        return null;
    }
}

/**
 * Asks for permission (must run from a click), registers the service
 * worker and subscribes. Returns the endpoint to send to the server, or
 * null when the user did not grant permission.
 */
export async function subscribeToPush(
    publicKey: string,
): Promise<string | null> {
    if ((await Notification.requestPermission()) !== 'granted') {
        return null;
    }

    return subscribeWithKey(publicKey);
}

/**
 * Subscribes once permission is already granted (never prompts).
 */
async function subscribeWithKey(publicKey: string): Promise<string> {
    const registration = await navigator.serviceWorker.register(
        SERVICE_WORKER_URL,
        { scope: '/' },
    );
    await navigator.serviceWorker.ready;

    const key = applicationServerKey(publicKey);
    let subscription = await registration.pushManager.getSubscription();

    // A subscription made with other VAPID keys would be refused by the push service.
    const currentKey = subscription?.options.applicationServerKey;

    if (
        subscription &&
        currentKey &&
        new Uint8Array(currentKey).toString() !== key.toString()
    ) {
        await subscription.unsubscribe();
        subscription = null;
    }

    subscription ??= await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: key,
    });

    return subscription.endpoint;
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/** Endpoint already confirmed by the server in this tab (one POST per load). */
let registeredEndpoint: string | null = null;

/**
 * Keeps this browser subscribed without any click: once the person allowed
 * notifications for the site (a permission the browser remembers), every
 * signed-in page makes sure there is a push subscription with the current
 * VAPID key and tells the server about it (an idempotent upsert). Signing
 * out removes it; the next sign-in subscribes again on its own. Never
 * prompts and never throws.
 */
export async function ensurePushSubscription(
    publicKey: string | null,
): Promise<void> {
    if (
        publicKey === null ||
        pushSupport() !== 'supported' ||
        Notification.permission !== 'granted'
    ) {
        return;
    }

    try {
        const endpoint = await subscribeWithKey(publicKey);

        if (endpoint === registeredEndpoint) {
            return;
        }

        const response = await fetch(storePushSubscription.url(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({ endpoint }),
        });

        if (response.ok) {
            registeredEndpoint = endpoint;
        }
    } catch {
        // Retried on the next page load.
    }
}

/**
 * Unsubscribes this browser locally; returns the endpoint it had.
 */
export async function unsubscribeFromPush(): Promise<string | null> {
    const subscription = await currentPushSubscription();

    if (!subscription) {
        return null;
    }

    const endpoint = subscription.endpoint;

    try {
        await subscription.unsubscribe();
    } catch {
        // The server row is removed anyway; a dead subscription gets 410.
    }

    return endpoint;
}
