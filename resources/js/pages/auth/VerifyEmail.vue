<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import LogoutConfirmDialog from '@/components/LogoutConfirmDialog.vue';
import StatusNote from '@/components/StatusNote.vue';
import { Button } from '@/components/ui/button';
import { send } from '@/routes/verification';

defineOptions({
    layout: {
        title: 'Verificación de correo',
        description:
            'Verifica tu correo electrónico con el enlace que te acabamos de enviar.',
    },
});

defineProps<{
    status?: string;
}>();

const logoutDialogOpen = ref(false);
</script>

<template>
    <Head title="Verificación de correo" />

    <StatusNote
        v-if="status === 'verification-link-sent'"
        tone="success"
        live="polite"
        class="mb-6"
    >
        Enviamos un nuevo enlace de verificación al correo registrado.
    </StatusNote>

    <Form v-bind="send.form()" class="space-y-6" v-slot="{ processing }">
        <Button :loading="processing" variant="secondary">
            Reenviar correo de verificación
        </Button>

        <button
            type="button"
            class="block cursor-pointer rounded-sm text-sm font-medium text-foreground underline decoration-border-strong underline-offset-4 hover:decoration-current"
            data-test="logout-button"
            @click="logoutDialogOpen = true"
        >
            Cerrar sesión
        </button>

        <LogoutConfirmDialog v-model:open="logoutDialogOpen" />
    </Form>
</template>
