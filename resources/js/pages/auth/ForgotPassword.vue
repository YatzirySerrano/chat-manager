<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { login } from '@/routes';
import { email } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Recuperar contraseña',
        description:
            'Ingresa tu correo para recibir un enlace de restablecimiento',
    },
});

defineProps<{
    status?: string;
}>();
</script>

<template>
    <Head title="Recuperar contraseña" />

    <div
        v-if="status"
        class="mb-2 rounded-lg bg-success-soft px-3 py-2 text-sm font-medium text-success-soft-foreground"
    >
        {{ status }}
    </div>

    <div class="space-y-6">
        <Form v-bind="email.form()" v-slot="{ errors, processing }">
            <div class="grid gap-2">
                <Label for="email">Correo electrónico</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    autocomplete="off"
                    v-focus
                    placeholder="correo@empresa.com"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="my-6 flex items-center justify-start">
                <Button
                    size="lg"
                    class="w-full"
                    :loading="processing"
                    data-test="email-password-reset-link-button"
                >
                    Enviar enlace de restablecimiento
                </Button>
            </div>
        </Form>

        <div class="space-x-1 text-sm text-muted-foreground">
            <span>O vuelve a</span>
            <TextLink :href="login()">iniciar sesión</TextLink>
        </div>
    </div>
</template>
