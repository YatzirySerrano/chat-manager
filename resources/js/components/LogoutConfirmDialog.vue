<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { unsubscribeFromPush } from '@/lib/notifications/push';
import { clearPwaCaches } from '@/lib/pwa/register';
import { logout } from '@/routes';

const open = defineModel<boolean>('open', { required: true });

const processing = ref(false);

/** While the request is in flight the dialog cannot be dismissed. */
const dialogOpen = computed({
    get: () => open.value,
    set: (value: boolean) => {
        if (!value && processing.value) {
            return;
        }

        open.value = value;
    },
});

/**
 * Resolves with the fallback when the browser takes too long (a stuck push
 * service must not block signing out).
 */
function withTimeout<T>(task: Promise<T>, fallback: T): Promise<T> {
    return Promise.race([
        task.catch(() => fallback),
        new Promise<T>((resolve) => setTimeout(() => resolve(fallback), 1500)),
    ]);
}

/**
 * Before signing out, this browser stops receiving pushes for the account
 * (unsubscribed here; the server deletes the subscription it is told about)
 * and the service worker cache is emptied, so the next person who uses the
 * device inherits nothing.
 */
async function confirmLogout(): Promise<void> {
    if (processing.value) {
        return;
    }

    processing.value = true;

    const [endpoint] = await Promise.all([
        withTimeout(unsubscribeFromPush(), null),
        withTimeout(clearPwaCaches(), undefined),
    ]);

    router.flushAll();
    // The server also asks for it, but the history of this session must not
    // survive even if the sign out request fails halfway.
    router.clearHistory();
    router.visit(logout(), {
        data: endpoint ? { push_endpoint: endpoint } : {},
        onFinish: () => {
            processing.value = false;
            open.value = false;
        },
    });
}
</script>

<template>
    <ConfirmDialog
        v-model:open="dialogOpen"
        title="¿Cerrar sesión?"
        description="Tu sesión actual en este dispositivo terminará y tendrás que volver a iniciar sesión para continuar."
        confirm-label="Cerrar sesión"
        :processing="processing"
        @confirm="confirmLogout"
    />
</template>
