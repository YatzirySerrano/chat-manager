import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { listenForInstallPrompt } from '@/lib/pwa/install';

/**
 * Registers public/sw.js (scope '/') on the pages of a signed-in person, the
 * same URL and scope the Web Push subscription uses (lib/notifications/push.ts),
 * so both share one registration. A new worker version waits until the
 * person accepts the "new version" notice; the page then reloads once under
 * the new worker.
 */

export const SERVICE_WORKER_URL = '/sw.js';

/** Minimum time between two update checks of the worker. */
const UPDATE_CHECK_INTERVAL_MS = 60 * 60 * 1000;

let registering = false;
let reloading = false;
let lastUpdateCheck = 0;
let notifiedWorker: ServiceWorker | null = null;

function supported(): boolean {
    return (
        typeof window !== 'undefined' &&
        window.isSecureContext &&
        'serviceWorker' in navigator
    );
}

function announceUpdate(worker: ServiceWorker): void {
    if (notifiedWorker === worker) {
        return;
    }

    notifiedWorker = worker;

    toast('Hay una nueva versión disponible', {
        description: 'Actualiza para usar la versión más reciente.',
        duration: Number.POSITIVE_INFINITY,
        action: {
            label: 'Actualizar',
            onClick: () => {
                reloading = true;
                worker.postMessage({ type: 'SKIP_WAITING' });
            },
        },
    });
}

function watchForUpdates(registration: ServiceWorkerRegistration): void {
    if (registration.waiting && navigator.serviceWorker.controller) {
        announceUpdate(registration.waiting);
    }

    registration.addEventListener('updatefound', () => {
        const installing = registration.installing;

        installing?.addEventListener('statechange', () => {
            // Without a controller this is the first install, not an update.
            if (
                installing.state === 'installed' &&
                navigator.serviceWorker.controller
            ) {
                announceUpdate(installing);
            }
        });
    });

    document.addEventListener('visibilitychange', () => {
        if (
            document.visibilityState === 'visible' &&
            Date.now() - lastUpdateCheck > UPDATE_CHECK_INTERVAL_MS
        ) {
            lastUpdateCheck = Date.now();
            void registration.update().catch(() => undefined);
        }
    });
}

async function register(): Promise<void> {
    if (registering) {
        return;
    }

    registering = true;

    try {
        const registration = await navigator.serviceWorker.register(
            SERVICE_WORKER_URL,
            { scope: '/' },
        );
        lastUpdateCheck = Date.now();
        watchForUpdates(registration);
    } catch {
        // Without a worker the app keeps working online; retry on the next visit.
        registering = false;
    }
}

export function initializePwa(): void {
    if (!supported()) {
        return;
    }

    listenForInstallPrompt();

    navigator.serviceWorker.addEventListener('controllerchange', () => {
        if (reloading) {
            window.location.reload();
        }
    });

    router.on('navigate', (event) => {
        if (event.detail.page.props.auth?.user) {
            void register();
        }
    });
}

/**
 * Deletes the worker's caches (and asks the worker to do it too) before
 * signing out. Resolves quickly even when no worker is active.
 */
export async function clearPwaCaches(): Promise<void> {
    if (typeof window === 'undefined') {
        return;
    }

    const tasks: Promise<unknown>[] = [];

    if ('caches' in window) {
        tasks.push(
            caches
                .keys()
                .then((names) =>
                    Promise.all(
                        names
                            .filter((name) =>
                                name.startsWith('chat-manager-static-'),
                            )
                            .map((name) => caches.delete(name)),
                    ),
                ),
        );
    }

    if (supported()) {
        navigator.serviceWorker.controller?.postMessage({
            type: 'CLEAR_CACHES',
        });
    }

    await Promise.allSettled(tasks);
}
