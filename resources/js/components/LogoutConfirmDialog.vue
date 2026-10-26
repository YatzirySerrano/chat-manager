<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { AlertCircle } from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
    currentPushSubscription,
    unsubscribeFromPush,
} from '@/lib/notifications/push';
import { clearPwaCaches } from '@/lib/pwa/register';
import { login, logout } from '@/routes';

const open = defineModel<boolean>('open', { required: true });

/** Longest wait for each browser clean-up step before signing out anyway. */
const CLEANUP_TIMEOUT_MS = 1500;

/** Longest wait for the server's answer before offering to retry. */
const REQUEST_TIMEOUT_MS = 15_000;

const processing = ref(false);
const errorMessage = ref<string | null>(null);

let requestTimer: ReturnType<typeof setTimeout> | null = null;

/** A 419 already answered with "the session is still open" once. */
let staleTokenSeen = false;

/** While the request is in flight the dialog cannot be dismissed. */
const dialogOpen = computed({
    get: () => open.value,
    set: (value: boolean) => {
        if (!value && processing.value) {
            return;
        }

        if (!value) {
            errorMessage.value = null;
        }

        open.value = value;
    },
});

const confirmLabel = computed(() => {
    if (processing.value) {
        return 'Cerrando sesión…';
    }

    return errorMessage.value ? 'Intentar de nuevo' : 'Cerrar sesión';
});

/**
 * Resolves with the fallback when the browser takes too long (a stuck push
 * service must not block signing out).
 */
function withTimeout<T>(task: Promise<T>, fallback: T): Promise<T> {
    return Promise.race([
        task.catch(() => fallback),
        new Promise<T>((resolve) =>
            setTimeout(() => resolve(fallback), CLEANUP_TIMEOUT_MS),
        ),
    ]);
}

function stopRequestTimer(): void {
    if (requestTimer !== null) {
        clearTimeout(requestTimer);
        requestTimer = null;
    }
}

function fail(message: string): void {
    stopRequestTimer();
    processing.value = false;
    errorMessage.value = message;
}

/**
 * Full page load of the sign in page: used when the session is already gone
 * on the server (or the answer could not be shown as a page), so nothing of
 * the closed session stays in memory.
 */
function leaveToLogin(): void {
    stopRequestTimer();
    window.location.replace(login().url);
}

/**
 * Stops this browser's pushes for the account once the session is over
 * (confirmed by the server, or already gone). Bounded like the other steps.
 */
function forgetPushHere(): Promise<unknown> {
    return withTimeout(unsubscribeFromPush(), null);
}

/** Endpoint of this browser's push subscription, without touching it. */
function pushEndpoint(): Promise<string | null> {
    return withTimeout(
        currentPushSubscription().then(
            (subscription) => subscription?.endpoint ?? null,
        ),
        null,
    );
}

/**
 * Whether the session is still open on the server after a 419: the sign in
 * page sends a signed-in visitor elsewhere (a redirect) and shows itself to
 * a signed-out one. The answer also refreshes the XSRF cookie, so a retry
 * carries a valid token.
 */
async function sessionStillOpen(): Promise<boolean> {
    try {
        const response = await fetch(login().url, {
            credentials: 'same-origin',
            redirect: 'manual',
            headers: { Accept: 'text/html' },
        });

        return response.type === 'opaqueredirect';
    } catch {
        return false;
    }
}

/**
 * 419: the page's security token no longer matches the session. Usually
 * the session already ended (nothing left to close), but it may also have
 * been opened again in another tab. Then the dialog explains it and offers
 * a retry with the fresh token; a second mismatch reloads the page.
 */
async function handleTokenMismatch(): Promise<void> {
    if (!(await sessionStillOpen())) {
        await forgetPushHere();
        leaveToLogin();

        return;
    }

    if (staleTokenSeen) {
        stopRequestTimer();
        window.location.reload();

        return;
    }

    staleTokenSeen = true;
    fail(
        'Tu sesión se volvió a abrir en otra pestaña, por eso no se cerró. Pulsa «Intentar de nuevo» para cerrarla en este dispositivo.',
    );
}

/**
 * Signing out tells the server this browser's push endpoint (it deletes that
 * subscription) and empties the service worker cache, so the next person who
 * uses the device inherits nothing. The browser's push subscription is only
 * removed once the server confirmed the sign out: a failed attempt keeps the
 * session and its notifications intact, and a retry sends the endpoint
 * again. Each step is bounded; the request itself is bounded too and, when
 * it fails, the dialog stays open with a message and a retry instead of
 * loading forever.
 */
async function confirmLogout(): Promise<void> {
    if (processing.value) {
        return;
    }

    processing.value = true;
    errorMessage.value = null;

    const [endpoint] = await Promise.all([
        pushEndpoint(),
        withTimeout(clearPwaCaches(), undefined),
    ]);

    router.flushAll();
    // The server also asks for it, but the history of this session must not
    // survive even if the sign out request fails halfway.
    router.clearHistory();

    let settled = false;

    requestTimer = setTimeout(() => {
        settled = true;
        router.cancelAll();
        fail(
            'El servidor tardó demasiado en responder. Revisa tu conexión e inténtalo de nuevo.',
        );
    }, REQUEST_TIMEOUT_MS);

    router.visit(logout(), {
        data: endpoint ? { push_endpoint: endpoint } : {},
        onSuccess: (page) => {
            settled = true;
            stopRequestTimer();

            if (page.props.auth?.user) {
                // The server answered with a page of a signed-in session
                // (e.g. a redirect it did not expect): load it for real.
                leaveToLogin();

                return;
            }

            void forgetPushHere();
            staleTokenSeen = false;
            processing.value = false;
            open.value = false;
        },
        onHttpException: (response) => {
            settled = true;

            // 401: the session had already ended (expired or closed in
            // another tab), so there is nothing left to close here.
            if (response.status === 401) {
                void forgetPushHere().then(leaveToLogin);

                return false;
            }

            if (response.status === 419) {
                void handleTokenMismatch();

                return false;
            }

            fail(
                'No pudimos cerrar tu sesión por un problema del servidor. Inténtalo de nuevo en unos segundos.',
            );

            return false;
        },
        onNetworkError: () => {
            settled = true;
            fail(
                'No hay conexión con el servidor. Revisa tu conexión a internet e inténtalo de nuevo.',
            );

            return false;
        },
        onFinish: (visit) => {
            // Interrupted by another visit. A full page load the server asked
            // for (409) also lands here; the request timer still guards it.
            if (!settled && (visit.cancelled || visit.interrupted)) {
                fail('No pudimos cerrar tu sesión. Inténtalo de nuevo.');
            }
        },
    });
}

onBeforeUnmount(stopRequestTimer);
</script>

<template>
    <ConfirmDialog
        v-model:open="dialogOpen"
        title="¿Cerrar sesión?"
        description="Tu sesión actual en este dispositivo terminará y tendrás que volver a iniciar sesión para continuar."
        :confirm-label="confirmLabel"
        :processing="processing"
        @confirm="confirmLogout"
    >
        <p v-if="processing" class="sr-only" role="status">Cerrando sesión…</p>
        <Alert v-if="errorMessage" variant="destructive" role="alert">
            <AlertCircle class="size-4" />
            <AlertTitle>No se cerró la sesión</AlertTitle>
            <AlertDescription>{{ errorMessage }}</AlertDescription>
        </Alert>
    </ConfirmDialog>
</template>
