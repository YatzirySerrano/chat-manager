<script setup lang="ts">
import { Check, Lock, Minus, Search, ShieldCheck, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import type { CatalogPermission, PermissionGroupCatalog } from '@/types';

/**
 * Permissions grouped by module with per-group selection, search and
 * counters. Unavailable (future) permissions are shown but never editable,
 * and neither are those reserved to the Superadministrador (the server never
 * offers them as grantable).
 */
const props = defineProps<{
    catalog: PermissionGroupCatalog[];
    grantable: string[];
    locked: string[];
    globalScope: boolean;
    readonly?: boolean;
    /** Permissions the role already holds, used to show future ones as granted. */
    current: string[];
}>();

const selected = defineModel<string[]>({ required: true });

const search = ref('');

const normalize = (value: string): string =>
    value
        .toLocaleLowerCase('es')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '');

function isEditable(permission: CatalogPermission): boolean {
    return (
        !props.readonly &&
        permission.available &&
        props.grantable.includes(permission.value) &&
        !props.locked.includes(permission.value) &&
        (props.globalScope || !permission.global_only)
    );
}

function isChecked(permission: CatalogPermission): boolean {
    return permission.available
        ? selected.value.includes(permission.value)
        : props.current.includes(permission.value);
}

const groups = computed(() => {
    const term = normalize(search.value.trim());

    return props.catalog
        .map((group) => {
            const permissions = group.permissions.filter(
                (permission) =>
                    term === '' ||
                    normalize(`${group.label} ${permission.label}`).includes(
                        term,
                    ),
            );
            const editable = group.permissions.filter(isEditable);
            const available = group.permissions.filter(
                (permission) => permission.available,
            );

            return {
                ...group,
                permissions,
                editable,
                available,
                selectedCount: available.filter((permission) =>
                    selected.value.includes(permission.value),
                ).length,
                allSelected:
                    editable.length > 0 &&
                    editable.every((permission) =>
                        selected.value.includes(permission.value),
                    ),
                someSelected: editable.some((permission) =>
                    selected.value.includes(permission.value),
                ),
                future: available.length === 0,
            };
        })
        .filter((group) => group.permissions.length > 0);
});

const availableTotal = computed(
    () =>
        props.catalog
            .flatMap((group) => group.permissions)
            .filter((permission) => permission.available).length,
);

function toggle(permission: CatalogPermission, checked: boolean): void {
    selected.value = checked
        ? [...new Set([...selected.value, permission.value])]
        : selected.value.filter((name) => name !== permission.value);
}

function toggleGroup(editable: CatalogPermission[], checked: boolean): void {
    const names = editable.map((permission) => permission.value);

    selected.value = checked
        ? [...new Set([...selected.value, ...names])]
        : selected.value.filter((name) => !names.includes(name));
}

function clearSelection(): void {
    selected.value = selected.value.filter(
        (name) =>
            props.locked.includes(name) || !props.grantable.includes(name),
    );
}
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <div class="relative flex-1">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="search"
                    type="search"
                    class="pl-9"
                    placeholder="Buscar permiso"
                    aria-label="Buscar permiso"
                />
            </div>
            <div class="flex items-center justify-between gap-3 sm:justify-end">
                <span
                    class="text-sm text-muted-foreground tabular-nums"
                    aria-live="polite"
                >
                    {{ selected.length }} de {{ availableTotal }} seleccionados
                </span>
                <Button
                    v-if="!readonly"
                    type="button"
                    variant="ghost"
                    size="sm"
                    :disabled="selected.length === 0"
                    @click="clearSelection"
                >
                    <X />
                    Limpiar selección
                </Button>
            </div>
        </div>

        <div class="grid gap-3 md:grid-cols-2">
            <section
                v-for="group in groups"
                :key="group.value"
                class="rounded-xl border bg-card"
                :class="group.future && 'border-dashed bg-muted/30'"
                :aria-labelledby="`group-${group.value}`"
            >
                <header
                    class="flex items-center justify-between gap-2 border-b px-4 py-2.5"
                >
                    <label
                        class="flex items-center gap-2.5 text-sm font-semibold"
                        :class="group.editable.length > 0 && 'cursor-pointer'"
                    >
                        <Checkbox
                            v-if="!readonly && group.editable.length > 0"
                            :model-value="
                                group.allSelected
                                    ? true
                                    : group.someSelected
                                      ? 'indeterminate'
                                      : false
                            "
                            :aria-label="`Seleccionar todos los permisos de ${group.label}`"
                            @update:model-value="
                                (checked) =>
                                    toggleGroup(
                                        group.editable,
                                        checked === true,
                                    )
                            "
                        />
                        <span :id="`group-${group.value}`">{{
                            group.label
                        }}</span>
                    </label>
                    <Badge
                        v-if="group.future"
                        variant="outline"
                        class="font-normal"
                        >Próximamente</Badge
                    >
                    <span
                        v-else
                        class="text-xs text-muted-foreground tabular-nums"
                    >
                        {{ group.selectedCount }}/{{ group.available.length }}
                    </span>
                </header>
                <ul class="divide-y">
                    <li
                        v-for="permission in group.permissions"
                        :key="permission.value"
                    >
                        <label
                            class="flex items-start gap-3 px-4 py-2.5 text-sm"
                            :class="
                                isEditable(permission)
                                    ? 'cursor-pointer hover:bg-accent/60'
                                    : 'cursor-default'
                            "
                        >
                            <Checkbox
                                class="mt-0.5"
                                :model-value="isChecked(permission)"
                                :disabled="!isEditable(permission)"
                                @update:model-value="
                                    (checked) =>
                                        toggle(permission, checked === true)
                                "
                            />
                            <span class="min-w-0 flex-1">
                                <span
                                    class="block"
                                    :class="
                                        !permission.available &&
                                        'text-muted-foreground'
                                    "
                                >
                                    {{ permission.label }}
                                </span>
                                <span class="mt-1 flex flex-wrap gap-1">
                                    <Badge
                                        v-if="locked.includes(permission.value)"
                                        variant="secondary"
                                        class="gap-1 text-[10px] font-normal"
                                    >
                                        <Lock class="size-3" /> Obligatorio
                                    </Badge>
                                    <Badge
                                        v-if="permission.reserved"
                                        variant="outline"
                                        class="gap-1 border-primary/40 text-[10px] font-normal text-primary"
                                    >
                                        <ShieldCheck class="size-3" /> Solo
                                        superadministrador
                                    </Badge>
                                    <Badge
                                        v-if="permission.sensitive"
                                        variant="outline"
                                        class="border-warning/40 text-[10px] font-normal text-warning"
                                    >
                                        Sensible
                                    </Badge>
                                    <Badge
                                        v-if="
                                            permission.global_only &&
                                            !globalScope
                                        "
                                        variant="outline"
                                        class="text-[10px] font-normal"
                                    >
                                        Requiere alcance de toda la organización
                                    </Badge>
                                    <Badge
                                        v-if="
                                            permission.available &&
                                            !permission.reserved &&
                                            !readonly &&
                                            !grantable.includes(
                                                permission.value,
                                            )
                                        "
                                        variant="outline"
                                        class="text-[10px] font-normal"
                                    >
                                        No puedes otorgarlo
                                    </Badge>
                                    <Badge
                                        v-if="
                                            !permission.available &&
                                            !group.future
                                        "
                                        variant="outline"
                                        class="text-[10px] font-normal"
                                    >
                                        Próximamente
                                    </Badge>
                                </span>
                            </span>
                        </label>
                    </li>
                </ul>
            </section>
        </div>

        <p
            v-if="groups.length === 0"
            class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground"
        >
            Ningún permiso coincide con «{{ search }}».
        </p>
    </div>
</template>
