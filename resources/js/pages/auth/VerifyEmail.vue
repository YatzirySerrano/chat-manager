<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import LogoutConfirmDialog from '@/components/LogoutConfirmDialog.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
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

    <div
        v-if="status === 'verification-link-sent'"
        class="mb-4 text-center text-sm font-medium text-green-600"
    >
        Enviamos un nuevo enlace de verificación al correo registrado.
    </div>

    <Form
        v-bind="send.form()"
        class="space-y-6 text-center"
        v-slot="{ processing }"
    >
        <Button :disabled="processing" variant="secondary">
            <Spinner v-if="processing" />
            Reenviar correo de verificación
        </Button>

        <button
            type="button"
            class="mx-auto block cursor-pointer text-sm text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
            data-test="logout-button"
            @click="logoutDialogOpen = true"
        >
            Cerrar sesión
        </button>

        <LogoutConfirmDialog v-model:open="logoutDialogOpen" />
    </Form>
</template>
