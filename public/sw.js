/*
 * Chat Manager service worker: Web Push, offline page and installability.
 *
 * Privacy rules (the device may be shared by several people):
 * - The only cache holds PUBLIC static files: the offline page, icons, the
 *   manifest and Vite's hashed bundles under /build/assets/ (immutable,
 *   identical for every user). Nothing that depends on the session is ever
 *   stored: no HTML of the app, no Inertia JSON (X-Inertia), no /api/*,
 *   /broadcasting/*, attachments, media, exports or any other request.
 * - Page navigations always go to the network ("network first, no copy"):
 *   an expired session gets the server's redirect to the login page, never a
 *   stale page. Only when the network fails does the worker answer with the
 *   static offline page.
 * - Logging out asks the worker to delete its cache (CLEAR_CACHES) and the
 *   server answers with Clear-Site-Data: "cache".
 *
 * Updates: a new version installs and waits; the page shows "Hay una nueva
 * versión disponible" and, when the person accepts, posts SKIP_WAITING and
 * reloads once the new worker takes control. Activation deletes the caches
 * of older versions. Bump VERSION whenever this file or PRECACHE changes.
 * Hashed bundles of earlier deploys are pruned: the cache keeps at most
 * MAX_ASSET_ENTRIES files under /build/assets/ (oldest removed first, on
 * activation and after each new bundle is stored).
 *
 * Web Push: pushes arrive WITHOUT payload, so the browser's push service
 * never sees any content. On each push the worker asks this same origin,
 * with the user's own session cookie, for the unread counter (GET
 * /notifications/summary) and shows a generic text with no customer or
 * message data. One tag replaces the previous alert (no pile-up) and
 * renotify is off. Every push shows a notification (userVisibleOnly: Safari
 * on iOS revokes subscriptions whose pushes show nothing, and Chrome would
 * show its own generic notice); while a window of the app is visible and
 * focused it is silent and closed after FOCUSED_CLOSE_MS, because the open
 * page already alerts in-app. The page skips its own system notification
 * while this browser has a push subscription (useNotificationCenter), so
 * only one alert appears. A click focuses a window already showing the
 * notification's URL, or takes an open window of the app there, or opens
 * that URL.
 *
 * Known limit: a subscription whose unsubscribe did not reach the server
 * (logout timeout, expired session, logout on another device) keeps
 * receiving pushes until the browser or the server drops it; the text is
 * generic and the counter is read with whoever's session is open (or asks
 * to sign in), so no customer data is exposed.
 */

const VERSION = '2026-10-24.2';
const CACHE_PREFIX = 'chat-manager-static-';
const CACHE_NAME = `${CACHE_PREFIX}${VERSION}`;
const OFFLINE_URL = '/offline.html';
const PRECACHE = [
    OFFLINE_URL,
    '/offline.js',
    '/manifest.webmanifest',
    '/favicon.svg',
    '/favicon.ico',
    '/apple-touch-icon.png',
    '/icons/icon.svg',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    '/icons/icon-maskable-192.png',
    '/icons/icon-maskable-512.png',
];
const IMMUTABLE_PREFIX = '/build/assets/';
const MAX_ASSET_ENTRIES = 80;

const SUMMARY_URL = '/notifications/summary';
const OPEN_URL = '/dashboard';
const TAG = 'chat-manager-push';
const TITLE = 'Notificaciones nuevas';
const FOCUSED_CLOSE_MS = 4000;

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(CACHE_NAME)
            .then((cache) =>
                cache.addAll(
                    PRECACHE.map(
                        (url) => new Request(url, { cache: 'reload' }),
                    ),
                ),
            ),
    );
});

async function deleteCaches(keep) {
    const names = await caches.keys();

    await Promise.all(
        names
            .filter((name) => name.startsWith(CACHE_PREFIX) && name !== keep)
            .map((name) => caches.delete(name)),
    );
}

/**
 * Keep only the newest MAX_ASSET_ENTRIES hashed bundles (the cache lists
 * entries in insertion order), so earlier deploys do not pile up.
 */
async function pruneAssets() {
    const cache = await caches.open(CACHE_NAME);
    const assets = (await cache.keys()).filter((request) =>
        new URL(request.url).pathname.startsWith(IMMUTABLE_PREFIX),
    );
    const stale = assets.slice(
        0,
        Math.max(0, assets.length - MAX_ASSET_ENTRIES),
    );

    await Promise.all(stale.map((request) => cache.delete(request)));
}

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            await deleteCaches(CACHE_NAME);
            await pruneAssets();
            await self.clients.claim();
        })(),
    );
});

self.addEventListener('message', (event) => {
    const type = event.data?.type;

    if (type === 'SKIP_WAITING') {
        self.skipWaiting();

        return;
    }

    if (type === 'CLEAR_CACHES') {
        event.waitUntil(
            (async () => {
                await deleteCaches(null);
                event.ports?.[0]?.postMessage({ type: 'CACHES_CLEARED' });
            })(),
        );
    }
});

/**
 * Only public, session-independent files may be answered from the cache.
 */
function isCacheable(url, request) {
    if (url.origin !== self.location.origin || request.method !== 'GET') {
        return false;
    }

    if (request.headers.has('X-Inertia') || url.search !== '') {
        return false;
    }

    return (
        url.pathname.startsWith(IMMUTABLE_PREFIX) ||
        PRECACHE.includes(url.pathname)
    );
}

async function cacheFirst(request) {
    const cache = await caches.open(CACHE_NAME);
    const cached = await cache.match(request);

    if (cached) {
        return cached;
    }

    const response = await fetch(request);

    if (response.ok && response.type === 'basic') {
        await cache.put(request, response.clone());
        await pruneAssets();
    }

    return response;
}

async function networkOnlyNavigation(request) {
    try {
        return await fetch(request);
    } catch (error) {
        const offline = await caches.match(OFFLINE_URL);

        if (offline) {
            return offline;
        }

        throw error;
    }
}

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(networkOnlyNavigation(request));

        return;
    }

    const url = new URL(request.url);

    if (isCacheable(url, request)) {
        event.respondWith(cacheFirst(request));
    }

    // Anything else (Inertia visits, /api, /broadcasting, attachments...)
    // is left to the browser: straight to the network, never stored here.
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
    const focused = await hasFocusedWindow();

    // Always visible (iOS/Safari requirement); silent and short-lived when
    // the app is already in front, since the page alerts in-app.
    await self.registration.showNotification(TITLE, {
        body: await summaryText(),
        tag: TAG,
        renotify: false,
        silent: focused,
        icon: '/icons/icon-192.png',
        data: { url: OPEN_URL },
    });

    if (focused) {
        await new Promise((resolve) => setTimeout(resolve, FOCUSED_CLOSE_MS));
        const shown = await self.registration.getNotifications({ tag: TAG });
        shown.forEach((notification) => notification.close());
    }
}

self.addEventListener('push', (event) => {
    event.waitUntil(handlePush());
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    event.waitUntil(
        (async () => {
            const target = new URL(
                event.notification.data?.url ?? OPEN_URL,
                self.location.origin,
            );

            if (target.origin !== self.location.origin) {
                return;
            }

            const windows = await self.clients.matchAll({
                type: 'window',
                includeUncontrolled: true,
            });
            const existing = windows.find((client) => {
                const url = new URL(client.url);

                return (
                    url.origin === target.origin &&
                    url.pathname === target.pathname
                );
            });

            if (existing) {
                await existing.focus();

                return;
            }

            const appWindow = windows.find(
                (client) => new URL(client.url).origin === target.origin,
            );

            if (appWindow && 'navigate' in appWindow) {
                try {
                    const focused = await appWindow.focus();
                    await (focused ?? appWindow).navigate(target.href);

                    return;
                } catch {
                    // Not controlled by this worker: open a window instead.
                }
            }

            await self.clients.openWindow(target.href);
        })(),
    );
});
