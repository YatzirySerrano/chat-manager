<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { store } from '@/routes/password/confirm';

defineOptions({
    layout: {
        title: 'Confirmar contraseña',
        description:
            'Es una zona protegida. Confirma tu contraseña para continuar.',
    },
});
</script>

<template>
    <Head title="Confirmar contraseña" />

    <Form
        v-bind="store.form()"
        reset-on-success
        v-slot="{ errors, processing }"
    >
        <div class="space-y-6">
            <div class="grid gap-2">
                <Label htmlFor="password">Contraseña</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    class="mt-1 block w-full"
                    required
                    autocomplete="current-password"
                    autofocus
                />

                <InputError :message="errors.password" />
            </div>

            <div class="flex items-center">
                <Button
                    size="lg"
                    class="w-full"
                    :loading="processing"
                    data-test="confirm-password-button"
                >
                    Confirmar contraseña
                </Button>
            </div>
        </div>
    </Form>
</template>
