<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import FormSection from '@/components/FormSection.vue';
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

    <form novalidate @submit.prevent="submit">
        <FormSection
            title="Perfil"
            description="Tu nombre es el que ven tus compañeros en las conversaciones y en el chat interno. El correo es con el que inicias sesión."
        >
            <div class="field-grid [--col-min:20rem]">
                <div class="grid gap-2">
                    <Label for="name">Nombre</Label>
                    <Input
                        id="name"
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
                        :message="validation.error('name')"
                    />
                </div>

                <div class="grid gap-2">
                    <Label for="email">Correo electrónico</Label>
                    <Input
                        id="email"
                        v-model="form.email"
                        type="email"
                        name="email"
                        required
                        maxlength="255"
                        autocomplete="username"
                        placeholder="Correo electrónico"
                        v-bind="validation.field('email', 'email')"
                    />
                    <InputError
                        id="email-error"
                        :message="validation.error('email')"
                    />
                </div>

                <div
                    v-if="page.props.mustVerifyEmail && !user.email_verified_at"
                    class="col-span-full"
                >
                    <p class="text-sm text-muted-foreground">
                        Tu correo electrónico no está verificado.
                        <Link
                            :href="send()"
                            as="button"
                            class="font-medium text-foreground underline decoration-border-strong underline-offset-4 transition-colors hover:decoration-current"
                        >
                            Reenviar el correo de verificación.
                        </Link>
                    </p>

                    <p
                        v-if="page.props.status === 'verification-link-sent'"
                        class="mt-2 rounded-lg bg-success-soft px-3 py-2 text-sm font-medium text-success-soft-foreground"
                    >
                        Enviamos un nuevo enlace de verificación a tu correo.
                    </p>
                </div>
            </div>

            <template #footer>
                <Button
                    :disabled="!validation.hasChanges"
                    :loading="form.processing"
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
            </template>
        </FormSection>
    </form>
</template>
