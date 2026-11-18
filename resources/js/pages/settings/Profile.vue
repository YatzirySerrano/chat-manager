<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { MailWarning, UserRound } from '@lucide/vue';
import { computed } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import ListAvatar from '@/components/filters/ListAvatar.vue';
import SettingsSection from '@/components/settings/SettingsSection.vue';
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
        <SettingsSection
            :icon="UserRound"
            title="Perfil"
            description="Tu nombre visible y el correo con el que entras."
        >
            <!-- How teammates see you, updated while you type. -->
            <div
                class="mb-5 flex min-w-0 items-center gap-3 rounded-lg border bg-surface-sunken px-3 py-3 sm:px-4"
                aria-hidden="true"
            >
                <ListAvatar
                    :name="form.name.trim() || user.name"
                    class="size-12! text-base!"
                />
                <div class="min-w-0">
                    <p class="truncate font-display text-base font-semibold">
                        {{ form.name.trim() || user.name }}
                    </p>
                    <p class="truncate text-sm text-muted-foreground">
                        {{ form.email.trim() || user.email }}
                    </p>
                </div>
            </div>

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
                    class="col-span-full rounded-xl border border-warning/30 bg-warning-soft/60 px-3 py-2.5"
                >
                    <p
                        class="flex flex-wrap items-center gap-x-1.5 gap-y-1 text-sm text-warning-soft-foreground"
                    >
                        <MailWarning
                            class="size-4 shrink-0"
                            aria-hidden="true"
                        />
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
                    type="submit"
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
        </SettingsSection>
    </form>
</template>
