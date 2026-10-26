<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ShieldCheck, UserRoundCheck } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import DataScopeOption from '@/components/admin/DataScopeOption.vue';
import PermissionMatrix from '@/components/admin/PermissionMatrix.vue';
import FormDialog from '@/components/FormDialog.vue';
import FormField from '@/components/FormField.vue';
import InputError from '@/components/InputError.vue';
import StatusNote from '@/components/StatusNote.vue';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useFormValidation } from '@/composables/useFormValidation';
import { scopeChangeNotes } from '@/lib/roles';
import { maxLength, minLength, required } from '@/lib/validation';
import type { FieldRules } from '@/lib/validation';
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

const validation = useFormValidation(form, {
    rules: (): FieldRules =>
        props.readonly
            ? {}
            : {
                  label: [
                      required('Escribe el nombre del rol.'),
                      minLength(3),
                      maxLength(80),
                  ],
                  description: [maxLength(255)],
                  scope: [required('Elige qué información puede ver el rol.')],
              },
    requireChanges: () => isEditing.value && !props.readonly,
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
    validation.load({
        label: props.role?.label ?? '',
        description: props.role?.description ?? '',
        scope: props.role?.scope ?? 'assigned',
        permissions: [...baseline.value],
    });
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

const scopeChanged = computed(
    () => props.role !== null && form.scope !== props.role.scope,
);

const scopeLabels = computed(
    () => new Map(props.scopes.map((scope) => [scope.value, scope.label])),
);

/**
 * Organization-wide permissions the role had and loses because a narrower
 * scope was chosen (the watch above removes them from the selection).
 */
const removedGlobalOnly = computed(() =>
    form.scope === 'global'
        ? []
        : baseline.value
              .filter((name) => labels.value.get(name)?.global_only)
              .map((name) => labels.value.get(name)?.label ?? name),
);

/** What changing the scope means for the holders, before saving. */
const scopeNotes = computed(() =>
    props.role && scopeChanged.value
        ? scopeChangeNotes({
              from: props.role.scope,
              to: form.scope,
              activeUsers: props.role.active_users_count ?? 0,
              removedGlobalOnly: removedGlobalOnly.value,
          })
        : [],
);

function isScopeSelectable(scope: ScopeOption): boolean {
    return !props.readonly && scope.grantable && !props.role?.is_root;
}

/** Roving tabindex: the selected option, or the first one that can be chosen. */
const tabbableScope = computed<DataScopeValue | null>(() => {
    const selected = props.scopes.find((scope) => scope.value === form.scope);

    if (selected && (isScopeSelectable(selected) || props.role?.is_root)) {
        return selected.value;
    }

    return (
        props.scopes.find(isScopeSelectable)?.value ??
        props.scopes[0]?.value ??
        null
    );
});

const scopeGroup = ref<HTMLElement | null>(null);

/**
 * Arrow keys move to the next or previous scope that can be chosen and
 * select it (radio group pattern); Home and End jump to the ends.
 */
function onScopeKeydown(event: KeyboardEvent): void {
    const selectable = props.scopes.filter(isScopeSelectable);

    if (selectable.length === 0) {
        return;
    }

    const current = selectable.findIndex((scope) => scope.value === form.scope);
    let next: number;

    switch (event.key) {
        case 'ArrowRight':
        case 'ArrowDown':
            next = current < 0 ? 0 : (current + 1) % selectable.length;
            break;
        case 'ArrowLeft':
        case 'ArrowUp':
            next =
                current < 0
                    ? selectable.length - 1
                    : (current - 1 + selectable.length) % selectable.length;
            break;
        case 'Home':
            next = 0;
            break;
        case 'End':
            next = selectable.length - 1;
            break;
        default:
            return;
    }

    event.preventDefault();
    form.scope = selectable[next].value;
    void nextTick(() =>
        scopeGroup.value
            ?.querySelector<HTMLElement>(`[data-scope="${form.scope}"]`)
            ?.focus(),
    );
}

const hasAccessChanges = computed(
    () =>
        changes.value.added.length > 0 ||
        changes.value.removed.length > 0 ||
        scopeChanged.value,
);

/** Active accounts holding the role: the ones a change reaches right away. */
const activeHolders = computed(() => props.role?.active_users_count ?? 0);

function usersCount(count: number): string {
    return `${count} ${count === 1 ? 'usuario activo' : 'usuarios activos'}`;
}

/**
 * Removed permissions that some holders keep anyway, because another role
 * or a direct grant also gives it to them.
 */
const retainedRemovals = computed(() =>
    changes.value.removed
        .map((name) => ({
            name,
            label: labels.value.get(name)?.label ?? name,
            count: props.role?.retained_elsewhere?.[name] ?? 0,
        }))
        .filter((removal) => removal.count > 0),
);

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
                ? 'Consulta qué puede hacer este rol y qué información ve.'
                : 'Elige sobre qué información trabaja el rol (alcance) y qué puede hacer con ella (permisos).'
        "
        size="xl"
        :processing="form.processing"
        :validation="validation"
        :submit-label="
            readonly ? 'Cerrar' : isEditing ? 'Guardar rol' : 'Crear rol'
        "
        @submit="submit"
    >
        <fieldset :disabled="readonly" class="space-y-6">
            <StatusNote v-if="role?.is_root" tone="info" :icon="ShieldCheck">
                Este es el rol de <strong>superadministrador</strong>: siempre
                ve toda la organización, siempre puede administrar usuarios y
                roles y es el único con permisos exclusivos. Puedes ajustar los
                demás permisos.
            </StatusNote>

            <div class="grid gap-4 sm:grid-cols-[1fr_1.4fr]">
                <FormField
                    id="role-label"
                    label="Nombre del rol"
                    :error="validation.error('label')"
                >
                    <Input
                        id="role-label"
                        v-model="form.label"
                        maxlength="80"
                        placeholder="Ej. Coordinador de Atención"
                        v-bind="validation.field('label', 'role-label')"
                    />
                </FormField>
                <FormField
                    id="role-description"
                    label="Descripción (opcional)"
                    :error="validation.error('description')"
                >
                    <Textarea
                        id="role-description"
                        v-model="form.description"
                        rows="2"
                        maxlength="255"
                        placeholder="¿Qué responsabilidades tiene?"
                        v-bind="
                            validation.field('description', 'role-description')
                        "
                    />
                </FormField>
            </div>

            <fieldset class="space-y-3">
                <legend class="mb-1 text-sm font-semibold">
                    Alcance: ¿qué información puede ver?
                </legend>
                <p class="text-sm text-pretty text-muted-foreground">
                    <strong class="font-medium text-foreground"
                        >El alcance define qué información</strong
                    >
                    puede consultar o usar este rol.
                    <strong class="font-medium text-foreground"
                        >Los permisos definen qué acciones</strong
                    >
                    puede hacer con esa información. Ejemplo: con el permiso
                    «Transferir conversaciones» y el alcance «Sus
                    departamentos», solo podrá transferir conversaciones de sus
                    departamentos.
                </p>
                <div
                    ref="scopeGroup"
                    class="grid gap-2 sm:grid-cols-3"
                    role="radiogroup"
                    aria-label="Alcance: qué información puede ver"
                    @keydown="onScopeKeydown"
                >
                    <DataScopeOption
                        v-for="scope in scopes"
                        :key="scope.value"
                        :option="scope"
                        :selected="form.scope === scope.value"
                        :disabled="!isScopeSelectable(scope)"
                        :disabled-reason="
                            !readonly && !role?.is_root && !scope.grantable
                                ? 'Tu rol no ve tanta información, así que no puedes darlo'
                                : undefined
                        "
                        :tabbable="tabbableScope === scope.value"
                        @select="form.scope = scope.value"
                    />
                </div>
                <InputError :message="validation.error('scope')" />
                <StatusNote
                    live="polite"
                    v-if="scopeNotes.length > 0"
                    tone="warning"
                    :icon="UserRoundCheck"
                    title="Qué cambia al guardar"
                >
                    <ul class="space-y-1">
                        <li v-for="note in scopeNotes" :key="note">
                            {{ note }}
                        </li>
                    </ul>
                </StatusNote>
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
                <InputError :message="validation.error('permissions')" />
            </div>

            <div
                v-if="isEditing && !readonly"
                class="space-y-2 rounded-xl bg-surface-sunken px-4 py-3 text-sm"
                aria-live="polite"
            >
                <p class="font-medium">
                    {{
                        hasAccessChanges
                            ? 'Resumen de cambios'
                            : 'A quién afecta'
                    }}
                </p>
                <p
                    v-if="
                        changes.added.length > 0 || changes.removed.length > 0
                    "
                    class="text-muted-foreground"
                >
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
                    }}.
                </p>
                <p v-if="scopeChanged && role" class="text-muted-foreground">
                    Alcance: {{ scopeLabels.get(role.scope) }} →
                    <strong class="font-medium text-foreground">{{
                        scopeLabels.get(form.scope)
                    }}</strong
                    >.
                </p>
                <StatusNote
                    v-if="activeHolders === 0"
                    tone="warning"
                    live="polite"
                >
                    Este rol no está asignado a ningún usuario activo: el cambio
                    no afectará a nadie todavía. Revisa en Usuarios qué rol
                    tienen las personas que quieres cambiar.
                </StatusNote>
                <p v-else class="text-muted-foreground">
                    {{
                        hasAccessChanges
                            ? 'Se aplicará de inmediato a'
                            : 'Lo tienen asignado'
                    }}
                    <strong class="font-medium text-foreground">{{
                        usersCount(activeHolders)
                    }}</strong
                    >{{
                        hasAccessChanges
                            ? '; sus pantallas abiertas se actualizarán solas.'
                            : '.'
                    }}
                </p>
                <StatusNote
                    live="polite"
                    v-for="removal in retainedRemovals"
                    :key="removal.name"
                    tone="warning"
                >
                    {{
                        removal.count === 1
                            ? '1 usuario conserva'
                            : `${removal.count} usuarios conservan`
                    }}
                    <strong>{{ removal.label }}</strong> por otro rol o por una
                    asignación directa. Para retirárselo, revisa su acceso en
                    Usuarios.
                </StatusNote>
                <StatusNote
                    live="polite"
                    v-if="changes.removedSensitive.length > 0"
                    tone="warning"
                >
                    Retirarás permisos de alto impacto:
                    <strong>{{
                        changes.removedSensitive
                            .map((name) => labels.get(name)?.label)
                            .join(', ')
                    }}</strong
                    >. Quienes tengan este rol perderán esas operaciones en su
                    siguiente acción.
                </StatusNote>
            </div>
        </fieldset>
    </FormDialog>
</template>
