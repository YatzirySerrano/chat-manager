import { router, usePage } from '@inertiajs/vue3';
import { computed, onScopeDispose, watch } from 'vue';
import { toast } from 'vue-sonner';
import type { Auth } from '@/types';

/** Changes close in time produce a single toast. */
const TOAST_DEBOUNCE_MS = 600;

const TOAST_ID = 'permissions-refreshed';

/**
 * What the interface derives from the signed-in user's access: the role,
 * its scope and state, and the effective permissions (sorted).
 */
function accessFingerprint(auth: Auth | undefined): string | null {
    if (!auth?.user) {
        return null;
    }

    return JSON.stringify([
        auth.user.id,
        auth.role?.id ?? null,
        auth.role?.scope ?? null,
        auth.role?.is_active ?? null,
        [...(auth.permissions ?? [])].sort(),
    ]);
}

/**
 * Keeps the signed-in user's permissions coherent while pages stay open.
 *
 * When an administrator changes the user's role, the permissions or scope
 * of that role, a direct grant or the account state, the server sends a
 * minimal `access.changed` signal to the user's personal channel
 * (users.{id}; no detail of the change). The real-time layer
 * (lib/realtime, handleAccessChanged) then reloads the current page once,
 * debounced, so the shared `auth` props (permissions, role) and the page's
 * own `can` flags come back authorized again; channels are re-authorized
 * after it, and the prefetched pages kept in memory (which carry the
 * previous permissions and actions) are dropped. This composable completes
 * that: once the reloaded permissions actually differ, it drops prefetched
 * pages again and shows one discreet toast ("Tus permisos se actualizaron"),
 * also when the change is noticed by the refresh done on returning to the
 * tab.
 *
 * It never reloads in a loop: the reload is the single debounced one of the
 * real-time layer, and the toast only follows a real change. The backend
 * authorizes every request again anyway, so a revoked action is rejected
 * even before the page refreshes.
 *
 * Mount it once, in the persistent authenticated layout (AppLayout).
 */
export function usePermissionRefresh(): void {
    const page = usePage();
    const fingerprint = computed(() =>
        accessFingerprint(page.props.auth as Auth | undefined),
    );

    let toastTimer: ReturnType<typeof setTimeout> | null = null;

    watch(fingerprint, (current, previous) => {
        if (current === null || previous === null || current === previous) {
            return;
        }

        // Another account signed in on this tab: not a permission change.
        if (JSON.parse(current)[0] !== JSON.parse(previous)[0]) {
            return;
        }

        router.flushAll();

        if (toastTimer) {
            clearTimeout(toastTimer);
        }

        toastTimer = setTimeout(() => {
            toastTimer = null;
            toast.info('Tus permisos se actualizaron', {
                id: TOAST_ID,
                description:
                    'Las secciones y acciones disponibles ya reflejan tu acceso actual.',
            });
        }, TOAST_DEBOUNCE_MS);
    });

    onScopeDispose(() => {
        if (toastTimer) {
            clearTimeout(toastTimer);
        }
    });
}
