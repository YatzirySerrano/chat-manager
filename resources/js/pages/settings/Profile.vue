<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFormValidation } from '@/composables/useFormValidation';
import { email, maxLength, normalizers, required } from '@/lib/validation';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Perfil',
                href: edit(),
            },
        ],
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user);

const form = useForm({ name: user.value.name, email: user.value.email });

const validation = useFormValidation(form, {
    rules: {
        name: [required('Escribe tu nombre.'), maxLength(255)],
        email: [
            required('Escribe tu correo electrónico.'),
            email(),
            maxLength(255),
        ],
    },
    normalize: { email: normalizers.email },
    requireChanges: true,
});

function submit(): void {
    if (!validation.validate()) {
        return;
    }

    form.submit(ProfileController.update(), { preserveScroll: true });
}
</script>

<template>
    <Head title="Perfil" />

    <div class="flex flex-col space-y-6">
        <h2 class="mb-1 text-lg font-semibold">Perfil</h2>
        <p class="text-sm text-pretty text-muted-foreground">
            Actualiza tu nombre y correo electrónico.
        </p>

        <form class="space-y-6" novalidate @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="name">Nombre</Label>
                <Input
                    id="name"
                    class="mt-1 block w-full"
                    v-model="form.name"
                    name="name"
                    required
                    maxlength="255"
                    autocomplete="name"
                    placeholder="Nombre completo"
                    v-bind="validation.field('name', 'name')"
                />
                <InputError
                    id="name-error"
                    class="mt-2"
                    :message="validation.error('name')"
                />
            </div>

            <div class="grid gap-2">
                <Label for="email">Correo electrónico</Label>
                <Input
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    v-model="form.email"
                    name="email"
                    required
                    maxlength="255"
                    autocomplete="username"
                    placeholder="Correo electrónico"
                    v-bind="validation.field('email', 'email')"
                />
                <InputError
                    id="email-error"
                    class="mt-2"
                    :message="validation.error('email')"
                />
            </div>

            <div v-if="page.props.mustVerifyEmail && !user.email_verified_at">
                <p class="-mt-4 text-sm text-muted-foreground">
                    Tu correo electrónico no está verificado.
                    <Link
                        :href="send()"
                        as="button"
                        class="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                    >
                        Reenviar el correo de verificación.
                    </Link>
                </p>

                <div
                    v-if="page.props.status === 'verification-link-sent'"
                    class="mt-2 text-sm font-medium text-green-600"
                >
                    Enviamos un nuevo enlace de verificación a tu correo.
                </div>
            </div>

            <div class="flex items-center gap-4">
                <Button
                    :disabled="form.processing || !validation.hasChanges"
                    data-test="update-profile-button"
                    >Guardar</Button
                >
                <p
                    v-if="!validation.hasChanges"
                    class="text-sm text-muted-foreground"
                    aria-live="polite"
                >
                    Sin cambios por guardar
                </p>
            </div>
        </form>
    </div>
</template>
