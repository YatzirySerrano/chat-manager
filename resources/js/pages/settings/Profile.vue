<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Camera, MailWarning, Trash2, UserRound } from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import ProfileAvatarController from '@/actions/App/Http/Controllers/Settings/ProfileAvatarController';
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

/** The picture: chosen file previewed at once, then uploaded on its own. */
const fileInput = ref<HTMLInputElement | null>(null);
const preview = ref<string | null>(null);
const avatarError = ref<string | null>(null);
const uploading = ref(false);
const avatar = computed(
    () => preview.value ?? (user.value.avatar as string | null) ?? null,
);

function clearPreview(): void {
    if (preview.value) {
        URL.revokeObjectURL(preview.value);
    }

    preview.value = null;
}

onBeforeUnmount(clearPreview);

function pickAvatar(): void {
    fileInput.value?.click();
}

function uploadAvatar(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';

    if (!file) {
        return;
    }

    avatarError.value = null;

    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
        avatarError.value = 'Usa una foto JPG, PNG o WebP.';

        return;
    }

    if (file.size > 8 * 1024 * 1024) {
        avatarError.value = 'La foto no puede pasar de 8 MB.';

        return;
    }

    clearPreview();
    preview.value = URL.createObjectURL(file);

    router.post(
        ProfileAvatarController.update.url(),
        { avatar: file },
        {
            forceFormData: true,
            preserveScroll: true,
            onStart: () => (uploading.value = true),
            onFinish: () => {
                uploading.value = false;
                clearPreview();
            },
            onError: (errors) => {
                avatarError.value =
                    errors.avatar ?? 'No se pudo guardar la foto.';
            },
        },
    );
}

function removeAvatar(): void {
    avatarError.value = null;
    router.delete(ProfileAvatarController.destroy.url(), {
        preserveScroll: true,
        onStart: () => (uploading.value = true),
        onFinish: () => (uploading.value = false),
    });
}

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
            description="Tu foto y tu nombre visible: así te ve todo el equipo en las conversaciones (quién contestó, responsable), el chat interno y las listas."
        >
            <!-- How teammates see you, updated while you type. -->
            <div
                class="mb-5 flex min-w-0 items-center gap-3 rounded-lg border bg-surface-sunken px-3 py-3 sm:px-4"
                aria-hidden="true"
            >
                <ListAvatar
                    :name="form.name.trim() || user.name"
                    :src="avatar"
                    class="size-16! text-lg!"
                />
                <div class="min-w-0 flex-1">
                    <p class="truncate font-display text-base font-semibold">
                        {{ form.name.trim() || user.name }}
                    </p>
                    <p class="truncate text-sm text-muted-foreground">
                        {{ form.email.trim() || user.email }}
                    </p>
                </div>
            </div>

            <div class="mb-5 flex flex-wrap items-center gap-2">
                <input
                    ref="fileInput"
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    class="sr-only"
                    aria-label="Elegir foto de perfil"
                    @change="uploadAvatar"
                />
                <Button
                    type="button"
                    variant="outline"
                    :loading="uploading"
                    @click="pickAvatar"
                >
                    <Camera class="size-4" aria-hidden="true" />
                    {{ user.avatar ? 'Cambiar foto' : 'Subir foto' }}
                </Button>
                <Button
                    v-if="user.avatar"
                    type="button"
                    variant="ghost"
                    :disabled="uploading"
                    @click="removeAvatar"
                >
                    <Trash2 class="size-4" aria-hidden="true" />
                    Quitar foto
                </Button>
                <p class="w-full text-xs text-muted-foreground">
                    JPG, PNG o WebP de hasta 8 MB. Se recorta en cuadro.
                </p>
                <InputError
                    :message="avatarError ?? undefined"
                    class="w-full"
                />
            </div>

            <div class="field-grid [--col-min:20rem]">
                <div class="grid gap-2">
                    <Label for="name">Nombre visible</Label>
                    <Input
                        id="name"
                        v-model="form.name"
                        name="name"
                        required
                        maxlength="255"
                        autocomplete="name"
                        placeholder="Ej. Ana López · Ventas"
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
