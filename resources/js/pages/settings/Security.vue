<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import PasswordRequirements from '@/components/PasswordRequirements.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useFormValidation } from '@/composables/useFormValidation';
import { matches, password, required } from '@/lib/validation';
import { edit } from '@/routes/security';
import type { Props as ManageTwoFactorProps } from '@/components/ManageTwoFactor.vue';
import ManageTwoFactor from '@/components/ManageTwoFactor.vue';

type Props = {
    passwordRules: string;
} & ManageTwoFactorProps;

const props = defineProps<Props>();

const page = usePage();

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const validation = useFormValidation(form, {
    rules: () => ({
        current_password: [required('Escribe tu contraseña actual.')],
        password: [
            required('Escribe la nueva contraseña.'),
            password(page.props.passwordPolicy),
        ],
        password_confirmation: [
            required('Confirma la nueva contraseña.'),
            matches('password', 'Las contraseñas no coinciden.'),
        ],
    }),
});

const emptyPasswords = {
    current_password: '',
    password: '',
    password_confirmation: '',
};

function submit(): void {
    if (!validation.validate()) {
        return;
    }

    form.submit(SecurityController.update(), {
        preserveScroll: true,
        onSuccess: () => validation.load(emptyPasswords),
        onError: () => form.reset(),
    });
}

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Seguridad',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Seguridad" />

    <h1 class="sr-only">Seguridad</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Actualizar contraseña"
            description="Usa una contraseña larga y aleatoria para mantener tu cuenta segura"
        />

        <form class="space-y-6" novalidate @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="current_password">Contraseña actual</Label>
                <PasswordInput
                    id="current_password"
                    v-model="form.current_password"
                    name="current_password"
                    class="mt-1 block w-full"
                    autocomplete="current-password"
                    placeholder="Contraseña actual"
                    v-bind="
                        validation.field('current_password', 'current_password')
                    "
                />
                <InputError
                    id="current_password-error"
                    :message="validation.error('current_password')"
                />
            </div>

            <div class="grid gap-2">
                <Label for="password">Nueva contraseña</Label>
                <PasswordInput
                    id="password"
                    v-model="form.password"
                    name="password"
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                    placeholder="Nueva contraseña"
                    :passwordrules="props.passwordRules"
                    v-bind="
                        validation.field(
                            'password',
                            'password',
                            'password-requirements',
                        )
                    "
                />
                <InputError
                    id="password-error"
                    :message="validation.error('password')"
                />
                <PasswordRequirements
                    id="password-requirements"
                    :password="form.password"
                />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Confirmar contraseña</Label>
                <PasswordInput
                    id="password_confirmation"
                    v-model="form.password_confirmation"
                    name="password_confirmation"
                    class="mt-1 block w-full"
                    autocomplete="new-password"
                    placeholder="Confirmar contraseña"
                    :passwordrules="props.passwordRules"
                    v-bind="
                        validation.field(
                            'password_confirmation',
                            'password_confirmation',
                        )
                    "
                />
                <InputError
                    id="password_confirmation-error"
                    :message="validation.error('password_confirmation')"
                />
            </div>

            <div class="flex items-center gap-4">
                <Button
                    :disabled="form.processing"
                    data-test="update-password-button"
                >
                    Guardar
                </Button>
            </div>
        </form>
    </div>

    <ManageTwoFactor
        :canManageTwoFactor="canManageTwoFactor"
        :requiresConfirmation="requiresConfirmation"
        :twoFactorEnabled="twoFactorEnabled"
    />
</template>
