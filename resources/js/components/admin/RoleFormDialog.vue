<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { AlertTriangle, Check, Globe2, ShieldCheck } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import PermissionMatrix from '@/components/admin/PermissionMatrix.vue';
import FormDialog from '@/components/FormDialog.vue';
import FormField from '@/components/FormField.vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { store, update } from '@/routes/admin/roles';
import type {
    DataScopeValue,
    ManagedRole,
    PermissionGroupCatalog,
    ScopeOption,
} from '@/types';

const props = defineProps<{
    role: ManagedRole | null;
    catalog: PermissionGroupCatalog[];
    scopes: ScopeOption[];
    grantablePermissions: string[];
    rootCapabilities: string[];
    readonly?: boolean;
}>();

const open = defineModel<boolean>('open', { required: true });

const isEditing = computed(() => props.role !== null);

const availablePermissions = computed(() =>
    props.catalog
        .flatMap((group) => group.permissions)
        .filter((permission) => permission.available),
);

const form = useForm<{
    label: string;
    description: string;
    scope: DataScopeValue;
    permissions: string[];
}>({
    label: '',
    description: '',
    scope: 'assigned',
    permissions: [],
});

/** Permissions the role had when the dialog opened, to summarize the changes. */
const baseline = ref<string[]>([]);

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    const availableNames = availablePermissions.value.map(
        (permission) => permission.value,
    );

    baseline.value = (props.role?.permissions ?? []).filter((name) =>
        availableNames.includes(name),
    );
    form.clearErrors();
    form.defaults({
        label: props.role?.label ?? '',
        description: props.role?.description ?? '',
        scope: props.role?.scope ?? 'assigned',
        permissions: [...baseline.value],
    });
    form.reset();
});

const locked = computed(() =>
    props.role?.is_root ? props.rootCapabilities : [],
);

/**
 * Organization-wide permissions only work with the global scope, so they are
 * removed as soon as a narrower scope is selected.
 */
watch(
    () => form.scope,
    (scope) => {
        if (scope === 'global') {
            return;
        }

        const globalOnly = availablePermissions.value
            .filter((permission) => permission.global_only)
            .map((permission) => permission.value);

        form.permissions = form.permissions.filter(
            (name) => !globalOnly.includes(name),
        );
    },
);

const labels = computed(
    () =>
        new Map(
            availablePermissions.value.map((permission) => [
                permission.value,
                permission,
            ]),
        ),
);

const changes = computed(() => {
    const before = new Set(baseline.value);
    const after = new Set(form.permissions);

    const added = [...after].filter((name) => !before.has(name));
    const removed = [...before].filter((name) => !after.has(name));

    return {
        added,
        removed,
        removedSensitive: removed.filter(
            (name) => labels.value.get(name)?.sensitive,
        ),
    };
});

const permissionError = computed(() => {
    const errors = form.errors as Record<string, string | undefined>;
    const key = Object.keys(errors).find((name) =>
        name.startsWith('permissions'),
    );

    return key ? errors[key] : undefined;
});

const title = computed(() => {
    if (props.readonly) {
        return `Permisos de ${props.role?.label ?? ''}`;
    }

    return isEditing.value ? `Editar rol ${props.role?.label}` : 'Nuevo rol';
});

function submit(): void {
    if (props.readonly) {
        open.value = false;

        return;
    }

    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    };

    if (props.role) {
        form.submit(update(props.role.id), options);
    } else {
        form.submit(store(), options);
    }
}
</script>

<template>
    <FormDialog
        v-model:open="open"
        :title="title"
        :description="
            readonly
                ? 'Consulta los permisos efectivos y el alcance de este rol.'
                : 'Un permiso define qué puede hacer una persona; el alcance define sobre qué datos.'
        "
        size="xl"
        :processing="form.processing"
        :dirty="!readonly && form.isDirty"
        :submit-label="
            readonly ? 'Cerrar' : isEditing ? 'Guardar rol' : 'Crear rol'
        "
        @submit="submit"
    >
        <fieldset :disabled="readonly" class="space-y-6">
            <div
                v-if="role?.is_root"
                class="flex gap-3 rounded-xl border border-primary/25 bg-primary-soft px-4 py-3 text-sm text-primary-soft-foreground"
            >
                <ShieldCheck class="mt-0.5 size-4 shrink-0" />
                <p>
                    Este rol es la <strong>autoridad administrativa</strong> de
                    la plataforma: siempre tiene alcance sobre toda la
                    organización y conserva los permisos para administrar
                    usuarios y roles. Puedes ajustar el resto de sus permisos.
                </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-[1fr_1.4fr]">
                <FormField
                    id="role-label"
                    label="Nombre del rol"
                    :error="form.errors.label"
                >
                    <Input
                        id="role-label"
                        v-model="form.label"
                        maxlength="80"
                        placeholder="Ej. Coordinador de Atención"
                    />
                </FormField>
                <FormField
                    id="role-description"
                    label="Descripción (opcional)"
                    :error="form.errors.description"
                >
                    <Textarea
                        id="role-description"
                        v-model="form.description"
                        rows="2"
                        maxlength="255"
                        placeholder="¿Qué responsabilidades tiene?"
                    />
                </FormField>
            </div>

            <fieldset class="space-y-2">
                <legend
                    class="mb-2 flex items-center gap-2 text-sm font-semibold"
                >
                    <Globe2 class="size-4 text-muted-foreground" />
                    Alcance de los datos
                </legend>
                <div
                    class="grid gap-2 sm:grid-cols-3"
                    role="radiogroup"
                    aria-label="Alcance"
                >
                    <button
                        v-for="scope in scopes"
                        :key="scope.value"
                        type="button"
                        role="radio"
                        :aria-checked="form.scope === scope.value"
                        :disabled="
                            readonly || !scope.grantable || role?.is_root
                        "
                        class="relative flex flex-col gap-1 rounded-xl border bg-card p-3 text-left text-sm transition outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-60"
                        :class="
                            form.scope === scope.value
                                ? 'border-primary bg-primary-soft/60 ring-1 ring-primary'
                                : 'hover:border-primary/40'
                        "
                        @click="form.scope = scope.value"
                    >
                        <span
                            class="flex items-center justify-between font-medium"
                        >
                            {{ scope.label }}
                            <Check
                                v-if="form.scope === scope.value"
                                class="size-4 text-primary"
                            />
                        </span>
                        <span class="text-xs text-muted-foreground">{{
                            scope.description
                        }}</span>
                    </button>
                </div>
                <InputError :message="form.errors.scope" />
            </fieldset>

            <div class="space-y-2">
                <h3 class="text-sm font-semibold">Permisos</h3>
                <PermissionMatrix
                    v-model="form.permissions"
                    :catalog="catalog"
                    :grantable="grantablePermissions"
                    :locked="locked"
                    :global-scope="form.scope === 'global'"
                    :readonly="readonly"
                    :current="role?.permissions ?? []"
                />
                <InputError :message="permissionError" />
            </div>

            <div
                v-if="
                    isEditing &&
                    !readonly &&
                    (changes.added.length > 0 || changes.removed.length > 0)
                "
                class="space-y-2 rounded-xl border bg-muted/40 px-4 py-3 text-sm"
                aria-live="polite"
            >
                <p class="font-medium">Resumen de cambios</p>
                <p class="text-muted-foreground">
                    {{ changes.added.length }}
                    {{
                        changes.added.length === 1
                            ? 'permiso agregado'
                            : 'permisos agregados'
                    }}
                    · {{ changes.removed.length }}
                    {{
                        changes.removed.length === 1
                            ? 'permiso retirado'
                            : 'permisos retirados'
                    }}. Se aplicará de inmediato a {{ role?.users_count ?? 0 }}
                    {{ role?.users_count === 1 ? 'usuario' : 'usuarios' }}.
                </p>
                <p
                    v-if="changes.removedSensitive.length > 0"
                    class="flex gap-2 rounded-lg border border-warning/40 bg-warning/10 px-3 py-2 text-foreground"
                >
                    <AlertTriangle
                        class="mt-0.5 size-4 shrink-0 text-warning"
                    />
                    <span>
                        Retirarás permisos sensibles:
                        <strong>{{
                            changes.removedSensitive
                                .map((name) => labels.get(name)?.label)
                                .join(', ')
                        }}</strong
                        >. Quienes tengan este rol perderán esas operaciones en
                        su siguiente acción.
                    </span>
                </p>
            </div>
        </fieldset>
    </FormDialog>
</template>
