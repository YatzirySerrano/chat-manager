/*
 * Chat Manager service worker: Web Push only (no caching, no offline mode).
 *
 * Pushes arrive WITHOUT payload, so the browser's push service never sees
 * any content. On each push the worker asks this same origin, with the
 * user's own session cookie, for the unread counter (GET
 * /notifications/summary) and shows a generic text with no customer or
 * message data. One tag replaces the previous alert (no pile-up) and
 * renotify is off. Nothing is shown while a window of the app is visible
 * and focused: the open page already alerts in-app. A hidden tab does not
 * suppress the push (userVisibleOnly: Chrome would show its own generic
 * notice instead); the page skips its own system notification while this
 * browser has a push subscription (useNotificationCenter), so only one
 * alert appears.
 */

const SUMMARY_URL = '/notifications/summary';
const OPEN_URL = '/dashboard';
const TAG = 'chat-manager-push';
const TITLE = 'Notificaciones nuevas';

self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

async function hasFocusedWindow() {
    const windows = await self.clients.matchAll({
        type: 'window',
        includeUncontrolled: true,
    });

    return windows.some(
        (client) => client.visibilityState === 'visible' && client.focused,
    );
}

async function summaryText() {
    try {
        const response = await fetch(SUMMARY_URL, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) {
            return 'Tienes notificaciones nuevas. Inicia sesión para verlas.';
        }

        const data = await response.json();
        const total = Number(data?.unread?.total ?? 0);
        const urgent = Number(data?.unread?.urgent ?? 0);

        if (total <= 0) {
            return 'Tienes notificaciones nuevas.';
        }

        const base =
            total === 1
                ? 'Tienes 1 notificación sin leer'
                : `Tienes ${total} notificaciones sin leer`;

        if (urgent > 0) {
            return `${base} (${urgent} ${urgent === 1 ? 'urgente' : 'urgentes'}).`;
        }

        return `${base}.`;
    } catch {
        return 'Tienes notificaciones nuevas.';
    }
}

async function handlePush() {
    if (await hasFocusedWindow()) {
        return;
    }

    await self.registration.showNotification(TITLE, {
        body: await summaryText(),
        tag: TAG,
        renotify: false,
        icon: '/apple-touch-icon.png',
        data: { url: OPEN_URL },
    });
}

self.addEventListener('push', (event) => {
    event.waitUntil(handlePush());
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    event.waitUntil(
        (async () => {
            const windows = await self.clients.matchAll({
                type: 'window',
                includeUncontrolled: true,
            });
            const existing = windows.find(
                (client) => new URL(client.url).origin === self.location.origin,
            );

            if (existing) {
                await existing.focus();

                return;
            }

            await self.clients.openWindow(
                event.notification.data?.url ?? OPEN_URL,
            );
        })(),
    );
});
