<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Globe,
    MessageSquareText,
    MoreHorizontal,
    Pencil,
    Plus,
    Power,
    PowerOff,
    Search,
    Trash2,
    X,
} from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, reactive, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataPagination from '@/components/DataPagination.vue';
import DepartmentTag from '@/components/DepartmentTag.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import QuickReplyFormDialog from '@/components/quick-replies/QuickReplyFormDialog.vue';
import QuickReplySandbox from '@/components/quick-replies/QuickReplySandbox.vue';
import WhatsAppText from '@/components/WhatsAppText.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    destroy as destroyQuickReply,
    index as quickRepliesIndex,
} from '@/routes/quick-replies';
import { update as updateStatus } from '@/routes/quick-replies/status';
import type {
    DepartmentOption,
    Paginated,
    QuickReplyFilters,
    QuickReplySummary,
    QuickReplyVariableOption,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Respuestas rápidas', href: quickRepliesIndex() },
        ],
    },
});

const props = defineProps<{
    quickReplies: Paginated<QuickReplySummary>;
    filters: QuickReplyFilters;
    departments: DepartmentOption[];
    assignableDepartments: DepartmentOption[];
    variables: QuickReplyVariableOption[];
    sampleValues: Record<string, string>;
    bodyMaxLength: number;
    can: { create: boolean; manageGlobal: boolean; use: boolean };
}>();

const ALL = 'all';

const filterState = reactive({
    search: props.filters.search ?? '',
    department:
        props.filters.department === null
            ? ALL
            : String(props.filters.department),
    status: props.filters.status ?? ALL,
    availability: props.filters.availability ?? ALL,
});

const hasFilters = computed(
    () =>
        filterState.search !== '' ||
        filterState.department !== ALL ||
        filterState.status !== ALL ||
        filterState.availability !== ALL,
);

function applyFilters(): void {
    const query: Record<string, string> = {};

    if (filterState.search.trim() !== '') {
        query.search = filterState.search.trim();
    }

    for (const key of ['department', 'status', 'availability'] as const) {
        if (filterState[key] !== ALL) {
            query[key] = filterState[key];
        }
    }

    router.get(quickRepliesIndex.url({ query }), undefined, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['quickReplies', 'filters'],
    });
}

watchDebounced(() => filterState.search, applyFilters, { debounce: 300 });

function clearFilters(): void {
    filterState.search = '';
    filterState.department = ALL;
    filterState.status = ALL;
    filterState.availability = ALL;
    applyFilters();
}

const formOpen = ref(false);
const editingReply = ref<QuickReplySummary | null>(null);

function openCreate(): void {
    editingReply.value = null;
    formOpen.value = true;
}

function openEdit(reply: QuickReplySummary): void {
    editingReply.value = reply;
    formOpen.value = true;
}

type PendingAction = {
    type: 'deactivate' | 'delete';
    reply: QuickReplySummary;
};

const pendingAction = ref<PendingAction | null>(null);
const confirmOpen = ref(false);
const processingId = ref<number | null>(null);

function showFirstError(errors: Record<string, string>): void {
    const message = Object.values(errors)[0];

    if (message) {
        toast.error(message);
    }
}

function activate(reply: QuickReplySummary): void {
    router.visit(updateStatus(reply.id), {
        data: { is_active: true },
        preserveScroll: true,
        onStart: () => (processingId.value = reply.id),
        onFinish: () => (processingId.value = null),
        onError: showFirstError,
    });
}

function askConfirmation(action: PendingAction): void {
    pendingAction.value = action;
    confirmOpen.value = true;
}

function confirmPendingAction(): void {
    const action = pendingAction.value;

    if (!action) {
        return;
    }

    const options = {
        preserveScroll: true,
        onStart: () => (processingId.value = action.reply.id),
        onSuccess: () => (confirmOpen.value = false),
        onFinish: () => (processingId.value = null),
        onError: (errors: Record<string, string>) => {
            confirmOpen.value = false;
            showFirstError(errors);
        },
    };

    if (action.type === 'deactivate') {
        router.visit(updateStatus(action.reply.id), {
            ...options,
            data: { is_active: false },
        });

        return;
    }

    router.visit(destroyQuickReply(action.reply.id), options);
}

const confirmCopy = computed(() => {
    const action = pendingAction.value;
    const shortcut = action ? `/${action.reply.shortcut}` : '';

    if (action?.type === 'delete') {
        return {
            title: `¿Eliminar la respuesta ${shortcut}?`,
            description:
                'Se eliminará de forma permanente y su atajo quedará libre. Los mensajes ya enviados no cambian. Si solo quieres dejar de usarla, desactívala.',
            confirmLabel: 'Eliminar',
        };
    }

    return {
        title: `¿Desactivar la respuesta ${shortcut}?`,
        description:
            'Dejará de aparecer al escribir «/» en el chat. Su atajo sigue reservado y puedes reactivarla cuando quieras.',
        confirmLabel: 'Desactivar',
    };
});

const hasActions = (reply: QuickReplySummary): boolean =>
    reply.can.update || reply.can.changeStatus || reply.can.delete;
</script>

<template>
    <Head title="Respuestas rápidas" />

    <div
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8"
    >
        <PageHeader
            title="Respuestas rápidas"
            description="Textos predefinidos que el equipo inserta en el chat escribiendo «/» y su atajo. Se insertan para revisarlos; nunca se envían solos."
        >
            <template v-if="can.create" #actions>
                <Button @click="openCreate">
                    <Plus />
                    Nueva respuesta
                </Button>
            </template>
        </PageHeader>

        <div class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <SurfaceCard>
                <div
                    class="flex flex-col gap-2 border-b p-4 lg:flex-row lg:items-center"
                    role="search"
                >
                    <div class="relative flex-1">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <Input
                            v-model="filterState.search"
                            type="search"
                            placeholder="Buscar por título, atajo o texto"
                            class="pl-9"
                            aria-label="Buscar respuestas rápidas"
                        />
                    </div>
                    <div class="grid grid-cols-2 gap-2 sm:flex">
                        <Select
                            v-model="filterState.department"
                            @update:model-value="applyFilters"
                        >
                            <SelectTrigger
                                class="w-full sm:w-44"
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
                        <Select
                            v-model="filterState.availability"
                            @update:model-value="applyFilters"
                        >
                            <SelectTrigger
                                class="w-full sm:w-40"
                                aria-label="Filtrar por disponibilidad"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="ALL"
                                    >Cualquier alcance</SelectItem
                                >
                                <SelectItem value="global"
                                    >Para todos</SelectItem
                                >
                                <SelectItem value="departments"
                                    >Por departamento</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <Select
                            v-model="filterState.status"
                            @update:model-value="applyFilters"
                        >
                            <SelectTrigger
                                class="w-full sm:w-32"
                                aria-label="Filtrar por estado"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="ALL">Todas</SelectItem>
                                <SelectItem value="active">Activas</SelectItem>
                                <SelectItem value="inactive"
                                    >Inactivas</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <Button
                            v-if="hasFilters"
                            variant="ghost"
                            @click="clearFilters"
                        >
                            <X />
                            Limpiar
                        </Button>
                    </div>
                </div>

                <ul v-if="quickReplies.data.length > 0" class="divide-y">
                    <li
                        v-for="reply in quickReplies.data"
                        :key="reply.id"
                        class="flex gap-3 px-5 py-4"
                        :class="!reply.is_active && 'bg-muted/30'"
                    >
                        <div class="grid min-w-0 flex-1 gap-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <span
                                    class="rounded-md bg-primary-soft px-1.5 py-0.5 font-mono text-xs font-medium text-primary-soft-foreground"
                                    >/{{ reply.shortcut }}</span
                                >
                                <p class="max-w-full truncate font-medium">
                                    {{ reply.title }}
                                </p>
                                <StatusBadge
                                    :active="reply.is_active"
                                    active-label="Activa"
                                    inactive-label="Inactiva"
                                />
                            </div>
                            <p
                                class="line-clamp-3 text-sm wrap-anywhere text-muted-foreground"
                            >
                                <WhatsAppText :text="reply.body" />
                            </p>
                            <div class="flex flex-wrap items-center gap-1">
                                <span
                                    v-if="reply.is_global"
                                    class="inline-flex items-center gap-1 rounded-md border px-1.5 py-0.5 text-xs"
                                >
                                    <Globe class="size-3" aria-hidden="true" />
                                    Todos los departamentos
                                </span>
                                <DepartmentTag
                                    v-for="department in reply.departments"
                                    v-else
                                    :key="department.id"
                                    :name="department.name"
                                    :color="department.color"
                                    :muted="!department.is_active"
                                />
                            </div>
                        </div>
                        <DropdownMenu v-if="hasActions(reply)">
                            <DropdownMenuTrigger as-child>
                                <Button
                                    variant="ghost"
                                    size="icon-sm"
                                    :disabled="processingId === reply.id"
                                    :aria-label="`Acciones para /${reply.shortcut}`"
                                >
                                    <MoreHorizontal />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" class="w-44">
                                <DropdownMenuItem
                                    v-if="reply.can.update"
                                    @select="openEdit(reply)"
                                >
                                    <Pencil />
                                    Editar
                                </DropdownMenuItem>
                                <template v-if="reply.can.changeStatus">
                                    <DropdownMenuItem
                                        v-if="!reply.is_active"
                                        @select="activate(reply)"
                                    >
                                        <Power />
                                        Activar
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-else
                                        @select="
                                            askConfirmation({
                                                type: 'deactivate',
                                                reply,
                                            })
                                        "
                                    >
                                        <PowerOff />
                                        Desactivar
                                    </DropdownMenuItem>
                                </template>
                                <template v-if="reply.can.delete">
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem
                                        variant="destructive"
                                        @select="
                                            askConfirmation({
                                                type: 'delete',
                                                reply,
                                            })
                                        "
                                    >
                                        <Trash2 />
                                        Eliminar
                                    </DropdownMenuItem>
                                </template>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </li>
                </ul>

                <EmptyState
                    v-else
                    class="m-4 border-none"
                    :icon="MessageSquareText"
                    :title="
                        hasFilters
                            ? 'Ninguna respuesta coincide con los filtros'
                            : 'Aún no hay respuestas rápidas'
                    "
                    :description="
                        hasFilters
                            ? 'Prueba con otros criterios de búsqueda.'
                            : can.create
                              ? 'Crea la primera para que el equipo responda más rápido y con el mismo tono.'
                              : 'Cuando haya respuestas disponibles para ti aparecerán aquí.'
                    "
                >
                    <Button
                        v-if="hasFilters"
                        variant="outline"
                        @click="clearFilters"
                        >Limpiar filtros</Button
                    >
                    <Button v-else-if="can.create" @click="openCreate">
                        <Plus />
                        Nueva respuesta
                    </Button>
                </EmptyState>

                <div
                    v-if="
                        quickReplies.meta.last_page > 1 ||
                        quickReplies.data.length > 0
                    "
                    class="border-t px-4 py-3"
                >
                    <DataPagination
                        :meta="quickReplies.meta"
                        noun="respuestas"
                    />
                </div>
            </SurfaceCard>

            <SurfaceCard
                v-if="can.use"
                class="overflow-visible"
                title="Probar respuesta"
                description="Escribe «/» como lo harás en el chat para buscar e insertar una respuesta."
                body-class="p-4"
            >
                <QuickReplySandbox :variables="variables" />
            </SurfaceCard>
        </div>

        <QuickReplyFormDialog
            v-if="can.create"
            v-model:open="formOpen"
            :quick-reply="editingReply"
            :departments="assignableDepartments"
            :variables="variables"
            :sample-values="sampleValues"
            :body-max-length="bodyMaxLength"
            :can-manage-global="can.manageGlobal"
        />

        <ConfirmDialog
            v-model:open="confirmOpen"
            :title="confirmCopy.title"
            :description="confirmCopy.description"
            :confirm-label="confirmCopy.confirmLabel"
            destructive
            :processing="processingId !== null"
            @confirm="confirmPendingAction"
        />
    </div>
</template>
