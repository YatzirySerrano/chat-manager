<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Eye, Lock, Pencil, RotateCcw, Trash2, X } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataPagination from '@/components/DataPagination.vue';
import EmptyState from '@/components/EmptyState.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDateTime } from '@/lib/format';
import { formatPhone } from '@/lib/phone';
import { index as personalItemsIndex } from '@/routes/admin/personal-items';
import {
    destroy as destroyAlias,
    update as updateAlias,
} from '@/routes/admin/personal-items/aliases';
import {
    destroy as destroyAppearance,
    update as updateAppearance,
} from '@/routes/admin/personal-items/appearances';
import { index as quickRepliesIndex } from '@/routes/quick-replies';
import type {
    ChatAppearancePresetOption,
    ChatColors,
    Paginated,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Alias, colores y respuestas personales del equipo',
                href: personalItemsIndex(),
            },
        ],
    },
});

type ItemType = 'alias' | 'appearance';
type Owner = { id: number; name: string };
type AliasRow = {
    id: number;
    alias: string;
    owner: Owner;
    contact: { id: number; name: string; phone_e164: string | null };
    updated_at: string | null;
    can: { update: boolean; delete: boolean };
};
type AppearanceRow = {
    id: number;
    owner: Owner;
    scope_label: string;
    preset: string | null;
    preset_label: string | null;
    colors: ChatColors | null;
    updated_at: string | null;
    can: { update: boolean; delete: boolean };
};

const props = defineProps<{
    type: ItemType;
    filters: { user: number | null; department: number | null };
    items: Paginated<AliasRow | AppearanceRow>;
    users: Owner[];
    departments: { id: number; name: string }[];
    presets: ChatAppearancePresetOption[];
    can: { manage: boolean };
}>();

const ALL = 'all';
const TYPES: { value: ItemType; label: string }[] = [
    { value: 'alias', label: 'Alias' },
    { value: 'appearance', label: 'Apariencia del chat' },
];

const filterState = reactive({
    user: props.filters.user === null ? ALL : String(props.filters.user),
    department:
        props.filters.department === null
            ? ALL
            : String(props.filters.department),
});

const hasFilters = computed(
    () => filterState.user !== ALL || filterState.department !== ALL,
);

function query(type: ItemType = props.type): Record<string, string> {
    const result: Record<string, string> = { type };

    if (filterState.user !== ALL) {
        result.user = filterState.user;
    }

    if (filterState.department !== ALL) {
        result.department = filterState.department;
    }

    return result;
}

function applyFilters(): void {
    router.get(personalItemsIndex.url({ query: query() }), undefined, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function clearFilters(): void {
    filterState.user = ALL;
    filterState.department = ALL;
    applyFilters();
}

const aliases = computed(() => props.items.data as AliasRow[]);
const appearances = computed(() => props.items.data as AppearanceRow[]);

/*
 * Personal quick replies are supervised in their own module (segment
 * "others"), which already filters by user and department.
 */
const quickRepliesUrl = computed(() =>
    quickRepliesIndex.url({
        query: {
            segment: 'others',
            ...(filterState.user !== ALL ? { owner: filterState.user } : {}),
            ...(filterState.department !== ALL
                ? { department: filterState.department }
                : {}),
        },
    }),
);

/* Editing another user's alias (audited, the text is never recorded). */
const aliasForm = useForm<{ alias: string }>({ alias: '' });
const editingAlias = ref<AliasRow | null>(null);
const aliasOpen = ref(false);

function editAlias(row: AliasRow): void {
    editingAlias.value = row;
    aliasForm.clearErrors();
    aliasForm.alias = row.alias;
    aliasOpen.value = true;
}

function saveAlias(): void {
    if (!editingAlias.value) {
        return;
    }

    aliasForm.submit(updateAlias(editingAlias.value.id), {
        preserveScroll: true,
        onSuccess: () => (aliasOpen.value = false),
    });
}

/* Changing another user's palette (static swatches, never applied here). */
const appearanceForm = useForm<{ preset: string; colors: ChatColors | null }>({
    preset: '',
    colors: null,
});
const editingAppearance = ref<AppearanceRow | null>(null);
const appearanceOpen = ref(false);

function editAppearance(row: AppearanceRow): void {
    editingAppearance.value = row;
    appearanceForm.clearErrors();
    appearanceForm.preset = row.preset ?? props.presets[0]?.value ?? '';
    appearanceOpen.value = true;
}

function saveAppearance(): void {
    const preset = props.presets.find(
        (option) => option.value === appearanceForm.preset,
    );

    if (!editingAppearance.value || !preset) {
        return;
    }

    appearanceForm
        .transform(() => ({ preset: preset.value, colors: preset.colors }))
        .submit(updateAppearance(editingAppearance.value.id), {
            preserveScroll: true,
            onSuccess: () => (appearanceOpen.value = false),
        });
}

/* Deleting (alias) or restoring the defaults (appearance) of someone else. */
const toDelete = ref<
    | { kind: 'alias'; row: AliasRow }
    | { kind: 'appearance'; row: AppearanceRow }
    | null
>(null);
const deleteOpen = ref(false);
const deleting = ref(false);

function confirmDelete(
    target:
        | { kind: 'alias'; row: AliasRow }
        | { kind: 'appearance'; row: AppearanceRow },
): void {
    toDelete.value = target;
    deleteOpen.value = true;
}

function performDelete(): void {
    const target = toDelete.value;

    if (!target) {
        return;
    }

    router.visit(
        target.kind === 'alias'
            ? destroyAlias(target.row.id)
            : destroyAppearance(target.row.id),
        {
            preserveScroll: true,
            onStart: () => (deleting.value = true),
            onFinish: () => (deleting.value = false),
            onSuccess: () => (deleteOpen.value = false),
        },
    );
}

function swatches(colors: ChatColors | null, mode: 'light' | 'dark') {
    if (!colors) {
        return null;
    }

    return colors[mode];
}
</script>

<template>
    <Head title="Alias, colores y respuestas personales del equipo" />

    <div class="page-shell">
        <PageHeader
            title="Alias, colores y respuestas personales del equipo"
            description="Consulta los alias, los colores del chat y las respuestas rápidas personales de los usuarios que supervisas. Nada de esto se aplica a tu propia vista y cada cambio queda en la auditoría."
        />

        <p
            v-if="!can.manage"
            class="flex items-center gap-2 rounded-xl border bg-card px-4 py-3 text-sm text-muted-foreground shadow-card"
        >
            <Lock class="size-4 shrink-0" aria-hidden="true" />
            Puedes consultar estos elementos, pero no modificarlos.
        </p>

        <nav
            class="flex flex-wrap gap-1 border-b"
            aria-label="Tipo de elemento personal"
        >
            <Link
                v-for="option in TYPES"
                :key="option.value"
                :href="personalItemsIndex.url({ query: query(option.value) })"
                preserve-scroll
                class="-mb-px border-b-2 px-3 py-2 text-sm font-medium outline-none focus-visible:ring-2 focus-visible:ring-ring"
                :class="
                    type === option.value
                        ? 'border-primary text-foreground'
                        : 'border-transparent text-muted-foreground hover:text-foreground'
                "
                :aria-current="type === option.value ? 'page' : undefined"
            >
                {{ option.label }}
            </Link>
        </nav>

        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:flex lg:items-end">
            <Select
                v-model="filterState.user"
                @update:model-value="applyFilters"
            >
                <SelectTrigger
                    class="w-full lg:w-60"
                    aria-label="Filtrar por usuario"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="ALL">Todos los usuarios</SelectItem>
                    <SelectItem
                        v-for="user in users"
                        :key="user.id"
                        :value="String(user.id)"
                    >
                        {{ user.name }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <Select
                v-model="filterState.department"
                @update:model-value="applyFilters"
            >
                <SelectTrigger
                    class="w-full lg:w-60"
                    aria-label="Filtrar por departamento"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="ALL"
                        >Todos los departamentos</SelectItem
                    >
                    <SelectItem
                        v-for="department in departments"
                        :key="department.id"
                        :value="String(department.id)"
                    >
                        {{ department.name }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <Button v-if="hasFilters" variant="ghost" @click="clearFilters">
                <X aria-hidden="true" />
                Limpiar
            </Button>
        </div>

        <EmptyState
            v-if="items.data.length === 0"
            :icon="Eye"
            title="No hay nada que mostrar"
            description="Ninguno de los usuarios que supervisas tiene elementos de este tipo con los filtros aplicados."
        />

        <div
            v-else
            class="overflow-x-auto rounded-xl border bg-card shadow-card"
        >
            <Table v-if="type === 'alias'">
                <TableHeader>
                    <TableRow class="hover:bg-transparent">
                        <TableHead>Usuario</TableHead>
                        <TableHead>Alias</TableHead>
                        <TableHead>Nombre oficial del contacto</TableHead>
                        <TableHead class="hidden md:table-cell"
                            >Actualizado</TableHead
                        >
                        <TableHead v-if="can.manage" class="text-right">
                            <span class="sr-only">Acciones</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="row in aliases" :key="row.id">
                        <TableCell>{{ row.owner.name }}</TableCell>
                        <TableCell class="max-w-56 truncate font-medium">
                            {{ row.alias }}
                        </TableCell>
                        <TableCell class="max-w-64">
                            <span class="block truncate">{{
                                row.contact.name
                            }}</span>
                            <span
                                v-if="row.contact.phone_e164"
                                class="block text-xs text-muted-foreground tabular-nums"
                                >{{ formatPhone(row.contact.phone_e164) }}</span
                            >
                        </TableCell>
                        <TableCell
                            class="hidden text-muted-foreground tabular-nums md:table-cell"
                        >
                            {{ formatDateTime(row.updated_at) }}
                        </TableCell>
                        <TableCell v-if="can.manage" class="text-right">
                            <div class="flex justify-end gap-1">
                                <Button
                                    v-if="row.can.update"
                                    variant="ghost"
                                    size="icon-sm"
                                    :aria-label="`Editar el alias de ${row.owner.name}`"
                                    title="Editar"
                                    @click="editAlias(row)"
                                >
                                    <Pencil aria-hidden="true" />
                                </Button>
                                <Button
                                    v-if="row.can.delete"
                                    variant="ghost"
                                    size="icon-sm"
                                    :aria-label="`Eliminar el alias de ${row.owner.name}`"
                                    title="Eliminar"
                                    @click="
                                        confirmDelete({ kind: 'alias', row })
                                    "
                                >
                                    <Trash2 aria-hidden="true" />
                                </Button>
                            </div>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>

            <Table v-else>
                <TableHeader>
                    <TableRow class="hover:bg-transparent">
                        <TableHead>Usuario</TableHead>
                        <TableHead>Se aplica a</TableHead>
                        <TableHead>Paleta</TableHead>
                        <TableHead>Colores</TableHead>
                        <TableHead class="hidden md:table-cell"
                            >Actualizado</TableHead
                        >
                        <TableHead v-if="can.manage" class="text-right">
                            <span class="sr-only">Acciones</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="row in appearances" :key="row.id">
                        <TableCell>{{ row.owner.name }}</TableCell>
                        <TableCell class="max-w-64 truncate">{{
                            row.scope_label
                        }}</TableCell>
                        <TableCell>{{
                            row.preset_label ?? 'Personalizada'
                        }}</TableCell>
                        <TableCell>
                            <div class="grid gap-1" data-appearance-swatches>
                                <div
                                    v-for="mode in ['light', 'dark'] as const"
                                    :key="mode"
                                    class="flex items-center gap-1"
                                >
                                    <span
                                        class="w-12 text-xs text-muted-foreground"
                                    >
                                        {{
                                            mode === 'light'
                                                ? 'Claro'
                                                : 'Oscuro'
                                        }}
                                    </span>
                                    <template v-if="swatches(row.colors, mode)">
                                        <span
                                            class="inline-flex h-6 items-center rounded-md border px-1.5 text-xs"
                                            :style="{
                                                backgroundColor: swatches(
                                                    row.colors,
                                                    mode,
                                                )!.sent_bg,
                                                color: swatches(
                                                    row.colors,
                                                    mode,
                                                )!.sent_text,
                                            }"
                                            >Enviado</span
                                        >
                                        <span
                                            class="inline-flex h-6 items-center rounded-md border px-1.5 text-xs"
                                            :style="{
                                                backgroundColor: swatches(
                                                    row.colors,
                                                    mode,
                                                )!.received_bg,
                                                color: swatches(
                                                    row.colors,
                                                    mode,
                                                )!.received_text,
                                            }"
                                            >Recibido</span
                                        >
                                        <span
                                            class="size-6 rounded-md border"
                                            :style="{
                                                backgroundColor: swatches(
                                                    row.colors,
                                                    mode,
                                                )!.chat_bg,
                                            }"
                                            title="Fondo del chat"
                                        />
                                    </template>
                                    <span
                                        v-else
                                        class="text-xs text-muted-foreground"
                                        >Sin colores válidos</span
                                    >
                                </div>
                            </div>
                        </TableCell>
                        <TableCell
                            class="hidden text-muted-foreground tabular-nums md:table-cell"
                        >
                            {{ formatDateTime(row.updated_at) }}
                        </TableCell>
                        <TableCell v-if="can.manage" class="text-right">
                            <div class="flex justify-end gap-1">
                                <Button
                                    v-if="row.can.update"
                                    variant="ghost"
                                    size="icon-sm"
                                    :aria-label="`Cambiar la paleta de ${row.owner.name}`"
                                    title="Cambiar paleta"
                                    @click="editAppearance(row)"
                                >
                                    <Pencil aria-hidden="true" />
                                </Button>
                                <Button
                                    v-if="row.can.delete"
                                    variant="ghost"
                                    size="icon-sm"
                                    :aria-label="`Restablecer los colores de ${row.owner.name}`"
                                    title="Restablecer predeterminados"
                                    @click="
                                        confirmDelete({
                                            kind: 'appearance',
                                            row,
                                        })
                                    "
                                >
                                    <RotateCcw aria-hidden="true" />
                                </Button>
                            </div>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>

        <p class="text-sm text-muted-foreground">
            Las respuestas rápidas personales se consultan en
            <Link
                :href="quickRepliesUrl"
                class="font-medium text-primary underline underline-offset-2"
                >Respuestas rápidas › De otros usuarios</Link
            >, con los mismos filtros de usuario y departamento.
        </p>

        <DataPagination :meta="items.meta" noun="elementos" />
    </div>

    <Dialog v-model:open="aliasOpen">
        <DialogContent class="sm:max-w-md">
            <form class="grid gap-4" novalidate @submit.prevent="saveAlias">
                <DialogHeader>
                    <DialogTitle>Editar alias de otro usuario</DialogTitle>
                    <DialogDescription>
                        Cambia el alias que {{ editingAlias?.owner.name }} ve
                        para {{ editingAlias?.contact.name }}. Queda registrado
                        en la auditoría (sin el texto del alias). Déjalo vacío
                        para eliminarlo.
                    </DialogDescription>
                </DialogHeader>
                <FormField
                    id="supervised-alias"
                    label="Alias"
                    :error="aliasForm.errors.alias"
                >
                    <Input
                        id="supervised-alias"
                        v-model="aliasForm.alias"
                        maxlength="80"
                        autocomplete="off"
                    />
                </FormField>
                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="aliasForm.processing"
                        @click="aliasOpen = false"
                    >
                        Cancelar
                    </Button>
                    <Button type="submit" :disabled="aliasForm.processing">
                        <Spinner v-if="aliasForm.processing" />
                        Guardar
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="appearanceOpen">
        <DialogContent class="sm:max-w-md">
            <form
                class="grid gap-4"
                novalidate
                @submit.prevent="saveAppearance"
            >
                <DialogHeader>
                    <DialogTitle>Cambiar paleta de otro usuario</DialogTitle>
                    <DialogDescription>
                        Solo cambia el chat de
                        {{ editingAppearance?.owner.name }} ({{
                            editingAppearance?.scope_label
                        }}). Queda registrado en la auditoría.
                    </DialogDescription>
                </DialogHeader>
                <FormField id="supervised-preset" label="Paleta">
                    <Select v-model="appearanceForm.preset">
                        <SelectTrigger id="supervised-preset" class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="preset in presets"
                                :key="preset.value"
                                :value="preset.value"
                            >
                                {{ preset.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </FormField>
                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="appearanceForm.processing"
                        @click="appearanceOpen = false"
                    >
                        Cancelar
                    </Button>
                    <Button type="submit" :disabled="appearanceForm.processing">
                        <Spinner v-if="appearanceForm.processing" />
                        Guardar
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <ConfirmDialog
        v-model:open="deleteOpen"
        :title="
            toDelete?.kind === 'alias'
                ? 'Eliminar alias de otro usuario'
                : 'Restablecer colores de otro usuario'
        "
        :description="
            toDelete?.kind === 'alias'
                ? `${toDelete.row.owner.name} volverá a ver el nombre oficial del contacto. Queda registrado en la auditoría.`
                : `${toDelete?.row.owner.name ?? ''} volverá a ver los colores predeterminados en ${toDelete?.row.scope_label.toLowerCase() ?? ''}. Queda registrado en la auditoría.`
        "
        :confirm-label="
            toDelete?.kind === 'alias' ? 'Eliminar alias' : 'Restablecer'
        "
        destructive
        :processing="deleting"
        @confirm="performDelete"
    />
</template>
