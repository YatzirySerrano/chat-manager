<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
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

function confirmLogout(): void {
    if (processing.value) {
        return;
    }

    processing.value = true;
    router.flushAll();
    // The server also asks for it, but the history of this session must not
    // survive even if the sign out request fails halfway.
    router.clearHistory();
    router.visit(logout(), {
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
