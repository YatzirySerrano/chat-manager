<script setup lang="ts">
import { useForm, useHttp } from '@inertiajs/vue3';
import { Building2, KeyRound, ShieldCheck, UserRound } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import RoleChangeImpactDialog from '@/components/admin/RoleChangeImpactDialog.vue';
import DepartmentTag from '@/components/DepartmentTag.vue';
import FormDialog from '@/components/FormDialog.vue';
import FormField from '@/components/FormField.vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import RoleBadge from '@/components/RoleBadge.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { roleImpact, store, update } from '@/routes/admin/users';
import type {
    AdminUser,
    DepartmentOption,
    RoleChangeImpact,
    RoleOption,
} from '@/types';

const props = defineProps<{
    user: AdminUser | null;
    roles: RoleOption[];
    departments: DepartmentOption[];
}>();

const open = defineModel<boolean>('open', { required: true });

const isEditing = computed(() => props.user !== null);
const canChangeRole = computed(() => !props.user || props.user.can.assignRole);
const changePassword = ref(false);

const form = useForm<{
    name: string;
    email: string;
    role_id: number | null;
    department_ids: number[];
    password: string;
    password_confirmation: string;
    role_change_fingerprint: string | null;
}>({
    name: '',
    email: '',
    role_id: null,
    department_ids: [],
    password: '',
    password_confirmation: '',
    role_change_fingerprint: null,
});

const preview = useHttp<{ role_id: number | null }, RoleChangeImpact>({
    role_id: null,
});
const impact = ref<RoleChangeImpact | null>(null);
const impactOpen = ref(false);
const impactStale = ref(false);

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    const defaultRole = props.roles.find(
        (role) => role.assignable && !role.is_root && role.allows_departments,
    );

    form.clearErrors();
    changePassword.value = false;
    impact.value = null;
    form.defaults({
        name: props.user?.name ?? '',
        email: props.user?.email ?? '',
        role_id: props.user?.role?.id ?? defaultRole?.id ?? null,
        department_ids: props.user?.departments.map((d) => d.id) ?? [],
        password: '',
        password_confirmation: '',
        role_change_fingerprint: null,
    });
    form.reset();
});

const assignableRoles = computed(() =>
    props.roles.filter(
        (role) => role.assignable || role.id === props.user?.role?.id,
    ),
);

const selectedRole = computed(() =>
    props.roles.find((role) => role.id === form.role_id),
);
const allowsDepartments = computed(
    () => selectedRole.value?.allows_departments ?? false,
);
const roleChanged = computed(
    () => isEditing.value && form.role_id !== (props.user?.role?.id ?? null),
);

/**
 * Active departments plus inactive ones the user already belongs to.
 */
const selectableDepartments = computed(() =>
    props.departments.filter(
        (department) =>
            department.is_active || form.department_ids.includes(department.id),
    ),
);

const departmentError = computed(() => {
    const errors = form.errors as Record<string, string | undefined>;
    const key = Object.keys(errors).find((name) =>
        name.startsWith('department_ids'),
    );

    return key ? errors[key] : undefined;
});

const roleChangeError = computed(
    () => (form.errors as Record<string, string | undefined>).role_change,
);

function toggleDepartment(departmentId: number, checked: boolean): void {
    form.department_ids = checked
        ? [...form.department_ids, departmentId]
        : form.department_ids.filter((id) => id !== departmentId);
}

function send(fingerprint: string | null = null): void {
    form.role_change_fingerprint = fingerprint;

    form.transform((data) => ({
        ...data,
        role_id: canChangeRole.value ? data.role_id : null,
        department_ids: allowsDepartments.value ? data.department_ids : [],
        ...(isEditing.value && !changePassword.value
            ? { password: '', password_confirmation: '' }
            : {}),
    }));

    const options = {
        preserveScroll: true,
        onSuccess: () => {
            impactOpen.value = false;
            open.value = false;
        },
        onError: (errors: Record<string, string>) => {
            if (errors.role_change) {
                // The impact changed between the preview and the confirmation.
                void reviewRoleChange(true);

                return;
            }

            impactOpen.value = false;
        },
    };

    if (props.user) {
        form.submit(update(props.user.id), options);
    } else {
        form.submit(store(), options);
    }
}

async function reviewRoleChange(stale = false): Promise<void> {
    if (!props.user || form.role_id === null) {
        return;
    }

    preview.role_id = form.role_id;

    try {
        const result = await preview.post(roleImpact.url(props.user.id));

        impact.value = result;
        impactStale.value = stale;

        if (result.blockers.length > 0) {
            form.setError('role_id', result.blockers[0]);
            impactOpen.value = false;

            return;
        }

        if (result.significant) {
            impactOpen.value = true;

            return;
        }

        send(result.fingerprint);
    } catch {
        form.setError(
            'role_id',
            'No fue posible revisar el impacto del cambio de rol. Inténtalo de nuevo.',
        );
    }
}

function submit(): void {
    if (roleChanged.value && canChangeRole.value) {
        void reviewRoleChange();

        return;
    }

    send();
}
</script>

<template>
    <FormDialog
        v-model:open="open"
        :title="isEditing ? `Editar a ${user?.name}` : 'Nuevo usuario'"
        :description="
            isEditing
                ? 'Actualiza los datos, el rol y los departamentos del empleado.'
                : 'La cuenta quedará activa y podrá iniciar sesión con la contraseña inicial.'
        "
        size="lg"
        :processing="form.processing || preview.processing"
        :dirty="form.isDirty"
        :submit-label="isEditing ? 'Guardar cambios' : 'Crear usuario'"
        @submit="submit"
    >
        <div class="space-y-6">
            <section class="space-y-4">
                <h3 class="flex items-center gap-2 text-sm font-semibold">
                    <UserRound class="size-4 text-muted-foreground" />
                    Datos de la cuenta
                </h3>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField
                        id="user-name"
                        label="Nombre completo"
                        :error="form.errors.name"
                    >
                        <Input
                            id="user-name"
                            v-model="form.name"
                            autocomplete="off"
                            :aria-invalid="!!form.errors.name"
                        />
                    </FormField>
                    <FormField
                        id="user-email"
                        label="Correo electrónico"
                        :error="form.errors.email"
                    >
                        <Input
                            id="user-email"
                            v-model="form.email"
                            type="email"
                            autocomplete="off"
                            :aria-invalid="!!form.errors.email"
                        />
                    </FormField>
                </div>
            </section>

            <section class="space-y-3 border-t pt-5">
                <h3 class="flex items-center gap-2 text-sm font-semibold">
                    <ShieldCheck class="size-4 text-muted-foreground" />
                    Rol y alcance
                </h3>
                <FormField
                    v-if="canChangeRole"
                    id="user-role"
                    label="Rol"
                    :error="form.errors.role_id"
                >
                    <Select v-model="form.role_id">
                        <SelectTrigger
                            id="user-role"
                            class="w-full"
                            :aria-invalid="!!form.errors.role_id"
                        >
                            <SelectValue placeholder="Selecciona un rol" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="role in assignableRoles"
                                :key="role.id"
                                :value="role.id"
                                :disabled="!role.assignable"
                            >
                                {{ role.label }}
                                <span class="text-muted-foreground"
                                    >· {{ role.scope_label }}</span
                                >
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </FormField>
                <div
                    v-else
                    class="flex items-center justify-between gap-3 rounded-lg border bg-muted/40 px-3 py-2.5"
                >
                    <RoleBadge :role="user?.role ?? null" />
                    <span class="text-xs text-muted-foreground">
                        {{
                            user?.is_current_user
                                ? 'No puedes modificar tu propio rol.'
                                : 'No tienes permiso para cambiar el rol de este usuario.'
                        }}
                    </span>
                </div>
                <p v-if="selectedRole" class="text-xs text-muted-foreground">
                    {{ selectedRole.description ?? 'Rol personalizado.' }}
                    Alcance:
                    <strong class="font-medium text-foreground">{{
                        selectedRole.scope_label
                    }}</strong
                    >.
                </p>
                <InputError :message="roleChangeError" />
            </section>

            <section class="space-y-3 border-t pt-5">
                <h3 class="flex items-center gap-2 text-sm font-semibold">
                    <Building2 class="size-4 text-muted-foreground" />
                    Departamentos
                </h3>
                <template v-if="allowsDepartments">
                    <p
                        v-if="selectableDepartments.length === 0"
                        class="rounded-lg border border-dashed p-3 text-sm text-muted-foreground"
                    >
                        No hay departamentos activos disponibles.
                    </p>
                    <div
                        v-else
                        class="grid max-h-52 gap-1 overflow-y-auto rounded-lg border p-1 sm:grid-cols-2"
                    >
                        <Label
                            v-for="department in selectableDepartments"
                            :key="department.id"
                            class="flex cursor-pointer items-center gap-3 rounded-md px-2 py-1.5 font-normal hover:bg-accent"
                        >
                            <Checkbox
                                :model-value="
                                    form.department_ids.includes(department.id)
                                "
                                :disabled="
                                    !department.is_active &&
                                    !form.department_ids.includes(department.id)
                                "
                                @update:model-value="
                                    (checked) =>
                                        toggleDepartment(
                                            department.id,
                                            checked === true,
                                        )
                                "
                            />
                            <DepartmentTag
                                :name="department.name"
                                :color="department.color"
                                :muted="!department.is_active"
                            />
                        </Label>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        El alcance del rol se aplica sobre los departamentos
                        seleccionados.
                    </p>
                    <InputError :message="departmentError" />
                </template>
                <p
                    v-else
                    class="rounded-lg bg-muted/50 p-3 text-sm text-muted-foreground"
                >
                    {{
                        selectedRole
                            ? 'Este rol tiene alcance sobre toda la organización; no necesita departamentos.'
                            : 'Selecciona un rol para elegir sus departamentos.'
                    }}
                </p>
            </section>

            <section class="space-y-4 border-t pt-5">
                <h3 class="flex items-center gap-2 text-sm font-semibold">
                    <KeyRound class="size-4 text-muted-foreground" />
                    Contraseña
                </h3>
                <div
                    v-if="isEditing"
                    class="flex items-center justify-between gap-4"
                >
                    <p class="text-sm text-muted-foreground">
                        Define una nueva contraseña temporal para el empleado.
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :aria-expanded="changePassword"
                        @click="changePassword = !changePassword"
                    >
                        {{ changePassword ? 'No cambiar' : 'Restablecer' }}
                    </Button>
                </div>
                <div
                    v-if="!isEditing || changePassword"
                    class="grid gap-4 sm:grid-cols-2"
                >
                    <FormField
                        id="user-password"
                        :label="
                            isEditing
                                ? 'Nueva contraseña'
                                : 'Contraseña inicial'
                        "
                        :error="form.errors.password"
                    >
                        <PasswordInput
                            id="user-password"
                            v-model="form.password"
                            autocomplete="new-password"
                        />
                    </FormField>
                    <FormField
                        id="user-password-confirmation"
                        label="Confirmar contraseña"
                        :error="form.errors.password_confirmation"
                    >
                        <PasswordInput
                            id="user-password-confirmation"
                            v-model="form.password_confirmation"
                            autocomplete="new-password"
                        />
                    </FormField>
                </div>
            </section>
        </div>
    </FormDialog>

    <RoleChangeImpactDialog
        v-model:open="impactOpen"
        :user-name="user?.name ?? ''"
        :impact="impact"
        :stale="impactStale"
        :processing="form.processing"
        @confirm="send(impact?.fingerprint ?? null)"
    />
</template>
