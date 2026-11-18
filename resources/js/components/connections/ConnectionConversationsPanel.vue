<script setup lang="ts">
import { Link, router, useHttp } from '@inertiajs/vue3';
import {
    ArrowRightLeft,
    Archive,
    ExternalLink,
    History,
    MessagesSquare,
    Search,
} from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import StatusPill from '@/components/admin/StatusPill.vue';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDateTime } from '@/lib/format';
import {
    index as conversationsIndex,
    move as moveConversations,
} from '@/routes/connections/conversations';

/**
 * «Conversaciones» of a connection: everything it holds (live, imported
 * from Whaticket or QR history, and history archived as read-only), with
 * a link to open each one, and the bulk move to another connection of the
 * same channel: tick a few, the whole page, or every result of the filter
 * («Seleccionar las N»), never one by one.
 */
type Kind = 'all' | 'live' | 'imported' | 'archived';

type Row = {
    id: number;
    contact: { name: string; phone: string | null };
    status: { value: string; label: string };
    origin: { value: string; label: string };
    history_only: boolean;
    messages_count: number;
    last_message_at: string | null;
    url: string | null;
};

type Listing = {
    data: Row[];
    meta: { total: number; current_page: number; last_page: number };
    counts: Record<Kind | 'active', number>;
};

export type MoveTarget = {
    id: number;
    name: string;
    phone: string | null;
    active: boolean;
    provider: string;
};

const props = defineProps<{
    connectionId: number;
    canMove: boolean;
    targets: MoveTarget[];
}>();

const http = useHttp<Record<string, never>, Listing>({});

const kind = ref<Kind>('all');
const search = ref('');
const page = ref(1);
const listing = ref<Listing | null>(null);
const loading = ref(false);
const failed = ref(false);

/** Ticked ids of the current filter, or every result with `allMatching`. */
const selected = ref<Set<number>>(new Set());
const allMatching = ref(false);

const moveOpen = ref(false);
const target = ref<number | null>(null);
const showArchived = ref(false);
const moving = ref(false);

let latest = 0;

async function load(): Promise<void> {
    const request = ++latest;
    loading.value = true;
    failed.value = false;
    http.cancel();

    try {
        const result = await http.get(
            conversationsIndex.url(props.connectionId, {
                query: {
                    kind: kind.value,
                    search: search.value.trim() || undefined,
                    page: page.value,
                },
            }),
        );

        if (request === latest) {
            listing.value = result;
        }
    } catch {
        if (request === latest) {
            failed.value = true;
        }
    } finally {
        if (request === latest) {
            loading.value = false;
        }
    }
}

function resetSelection(): void {
    selected.value = new Set();
    allMatching.value = false;
}

let searchTimer: ReturnType<typeof setTimeout> | undefined;

watch(kind, () => {
    page.value = 1;
    resetSelection();
    void load();
});

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        page.value = 1;
        resetSelection();
        void load();
    }, 300);
});

watch(page, () => void load());

onMounted(() => void load());

const rows = computed(() => listing.value?.data ?? []);
const total = computed(() => listing.value?.meta.total ?? 0);
const counts = computed(() => listing.value?.counts);

const kindOptions = computed(() => [
    { value: 'all' as Kind, label: `Todas (${counts.value?.all ?? 0})` },
    { value: 'live' as Kind, label: `En vivo (${counts.value?.live ?? 0})` },
    {
        value: 'imported' as Kind,
        label: `Importadas (${counts.value?.imported ?? 0})`,
    },
    {
        value: 'archived' as Kind,
        label: `Historial archivado (${counts.value?.archived ?? 0})`,
    },
]);

const pageChecked = computed(
    () =>
        allMatching.value ||
        (rows.value.length > 0 &&
            rows.value.every((row) => selected.value.has(row.id))),
);

const selectedCount = computed(() =>
    allMatching.value ? total.value : selected.value.size,
);

function toggleRow(id: number, checked: boolean): void {
    if (allMatching.value) {
        // Leaving «every result»: keep the rows of this page except this one.
        allMatching.value = false;
        selected.value = new Set(
            rows.value.map((row) => row.id).filter((rowId) => rowId !== id),
        );

        return;
    }

    const next = new Set(selected.value);

    if (checked) {
        next.add(id);
    } else {
        next.delete(id);
    }

    selected.value = next;
}

function togglePage(checked: boolean): void {
    if (!checked) {
        resetSelection();

        return;
    }

    const next = new Set(selected.value);
    rows.value.forEach((row) => next.add(row.id));
    selected.value = next;
}

const targetOptions = computed(() =>
    props.targets.map((option) => ({
        value: option.id,
        label: option.name,
        description: [
            option.provider,
            option.phone,
            option.active ? null : 'inactiva',
        ]
            .filter(Boolean)
            .join(' · '),
    })),
);

const targetName = computed(
    () => props.targets.find((option) => option.id === target.value)?.name,
);

/** Whether the selection may include archived history. */
const mayIncludeArchived = computed(
    () =>
        (counts.value?.archived ?? 0) > 0 &&
        (kind.value === 'all' || kind.value === 'archived'),
);

function openMove(): void {
    target.value = props.targets.length === 1 ? props.targets[0].id : null;
    showArchived.value = false;
    moveOpen.value = true;
}

function confirmMove(): void {
    if (target.value === null || moving.value) {
        return;
    }

    moving.value = true;

    router.post(
        moveConversations.url(props.connectionId),
        {
            target_connection_id: target.value,
            all: allMatching.value,
            conversation_ids: allMatching.value ? [] : [...selected.value],
            kind: kind.value,
            search: search.value.trim() || null,
            show_archived: showArchived.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                moveOpen.value = false;
                resetSelection();
                page.value = 1;
                void load();
            },
            onError: (errors) => {
                toast.error(
                    Object.values(errors)[0] ??
                        'No se pudieron mover las conversaciones.',
                );
            },
            onFinish: () => {
                moving.value = false;
            },
        },
    );
}

function plural(count: number, one: string, many: string): string {
    return `${count.toLocaleString('es-MX')} ${count === 1 ? one : many}`;
}
</script>

<template>
    <div class="grid gap-4" data-test="connection-conversations">
        <div class="flex flex-wrap items-center gap-2">
            <div class="relative min-w-48 flex-1">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    aria-hidden="true"
                />
                <Input
                    v-model="search"
                    type="search"
                    class="pl-9"
                    placeholder="Buscar por contacto, teléfono o correo"
                    aria-label="Buscar conversaciones de la conexión"
                />
            </div>
            <AppSelect
                v-model="kind"
                class="w-full sm:w-60"
                :options="kindOptions"
                aria-label="Tipo de conversación"
            />
        </div>

        <div
            v-if="canMove"
            class="flex flex-wrap items-center gap-2 rounded-lg border bg-muted/40 px-3 py-2 text-sm"
        >
            <span class="text-muted-foreground" aria-live="polite">
                {{
                    selectedCount === 0
                        ? 'Marca las conversaciones que quieras mover.'
                        : plural(
                              selectedCount,
                              'conversación seleccionada',
                              'conversaciones seleccionadas',
                          )
                }}
            </span>
            <Button
                v-if="
                    pageChecked &&
                    !allMatching &&
                    total > rows.length &&
                    rows.length > 0
                "
                type="button"
                variant="link"
                size="sm"
                class="h-auto px-0"
                data-test="select-all-matching"
                @click="allMatching = true"
            >
                Seleccionar las {{ total.toLocaleString('es-MX') }}
            </Button>
            <Button
                v-if="selectedCount > 0"
                type="button"
                variant="link"
                size="sm"
                class="h-auto px-0 text-muted-foreground"
                @click="resetSelection"
            >
                Quitar selección
            </Button>
            <Button
                type="button"
                size="sm"
                class="sm:ml-auto"
                :disabled="selectedCount === 0 || targets.length === 0"
                data-test="move-conversations"
                @click="openMove"
            >
                <ArrowRightLeft />
                Mover a otra conexión
            </Button>
            <p
                v-if="targets.length === 0"
                class="basis-full text-xs text-muted-foreground"
            >
                No hay otra conexión activa del mismo canal a la cual moverlas.
            </p>
        </div>

        <p
            v-if="failed"
            class="rounded-lg border border-destructive/25 bg-destructive-soft px-3 py-2 text-sm text-destructive-soft-foreground"
            role="alert"
        >
            No se pudieron cargar las conversaciones.
            <button type="button" class="underline" @click="load">
                Reintentar
            </button>
        </p>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full min-w-[40rem] text-sm">
                <thead
                    class="bg-muted/50 text-left text-xs text-muted-foreground"
                >
                    <tr>
                        <th v-if="canMove" class="w-10 px-3 py-2">
                            <Checkbox
                                :model-value="pageChecked"
                                :disabled="rows.length === 0"
                                aria-label="Seleccionar las conversaciones de esta página"
                                data-test="select-page"
                                @update:model-value="
                                    (checked) => togglePage(checked === true)
                                "
                            />
                        </th>
                        <th class="px-3 py-2 font-medium">Contacto</th>
                        <th class="px-3 py-2 font-medium">Estado</th>
                        <th class="px-3 py-2 font-medium">Mensajes</th>
                        <th class="px-3 py-2 font-medium">Último mensaje</th>
                        <th class="px-3 py-2">
                            <span class="sr-only">Abrir</span>
                        </th>
                    </tr>
                </thead>
                <tbody :aria-busy="loading">
                    <tr v-if="!loading && rows.length === 0">
                        <td
                            :colspan="canMove ? 6 : 5"
                            class="px-3 py-8 text-center text-muted-foreground"
                        >
                            <MessagesSquare
                                class="mx-auto mb-2 size-5"
                                aria-hidden="true"
                            />
                            {{
                                search
                                    ? 'Ninguna conversación coincide con la búsqueda.'
                                    : 'Esta conexión no tiene conversaciones de este tipo.'
                            }}
                        </td>
                    </tr>
                    <tr
                        v-for="row in rows"
                        :key="row.id"
                        class="border-t transition-colors hover:bg-accent/40"
                        :class="loading && 'opacity-60'"
                    >
                        <td v-if="canMove" class="px-3 py-2">
                            <Checkbox
                                :model-value="
                                    allMatching || selected.has(row.id)
                                "
                                :aria-label="`Seleccionar la conversación con ${row.contact.name}`"
                                @update:model-value="
                                    (checked) =>
                                        toggleRow(row.id, checked === true)
                                "
                            />
                        </td>
                        <td class="max-w-64 px-3 py-2">
                            <p class="truncate font-medium">
                                {{ row.contact.name }}
                            </p>
                            <p
                                v-if="row.contact.phone"
                                class="truncate text-xs text-muted-foreground"
                            >
                                {{ row.contact.phone }}
                            </p>
                        </td>
                        <td class="px-3 py-2">
                            <div class="flex flex-wrap gap-1">
                                <StatusPill
                                    :label="row.status.label"
                                    :tone="
                                        row.status.value === 'closed'
                                            ? 'muted'
                                            : 'success'
                                    "
                                />
                                <span
                                    v-if="row.history_only"
                                    class="inline-flex items-center gap-1 text-xs text-muted-foreground"
                                    title="Solo se consulta en «Historial importado»"
                                >
                                    <Archive
                                        class="size-3"
                                        aria-hidden="true"
                                    />
                                    Archivada
                                </span>
                                <span
                                    v-else-if="row.origin.value !== 'live'"
                                    class="inline-flex items-center gap-1 text-xs text-muted-foreground"
                                >
                                    <History
                                        class="size-3"
                                        aria-hidden="true"
                                    />
                                    {{ row.origin.label }}
                                </span>
                            </div>
                        </td>
                        <td class="px-3 py-2 tabular-nums">
                            {{ row.messages_count.toLocaleString('es-MX') }}
                        </td>
                        <td
                            class="px-3 py-2 whitespace-nowrap text-muted-foreground"
                        >
                            {{ formatDateTime(row.last_message_at) }}
                        </td>
                        <td class="px-3 py-2 text-right">
                            <Link
                                v-if="row.url"
                                :href="row.url"
                                class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
                            >
                                Abrir
                                <ExternalLink
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                            </Link>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="listing && listing.meta.last_page > 1"
            class="flex items-center justify-between gap-2 text-sm text-muted-foreground"
        >
            <span>
                Página {{ listing.meta.current_page }} de
                {{ listing.meta.last_page }} ·
                {{ plural(total, 'conversación', 'conversaciones') }}
            </span>
            <div class="flex gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="page <= 1 || loading"
                    @click="page--"
                >
                    Anterior
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="page >= listing.meta.last_page || loading"
                    @click="page++"
                >
                    Siguiente
                </Button>
            </div>
        </div>

        <Dialog v-model:open="moveOpen">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        Mover
                        {{
                            plural(
                                selectedCount,
                                'conversación',
                                'conversaciones',
                            )
                        }}
                    </DialogTitle>
                    <DialogDescription>
                        Pasan con todos sus mensajes, archivos, notas e
                        historial. Las conversaciones activas seguirán
                        respondiéndose por la nueva conexión.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-4 text-sm">
                    <div class="grid gap-1.5">
                        <label for="move-target" class="font-medium">
                            Conexión de destino
                        </label>
                        <AppSelect
                            id="move-target"
                            v-model="target"
                            :options="targetOptions"
                            placeholder="Elige una conexión"
                        />
                    </div>

                    <label
                        v-if="mayIncludeArchived"
                        class="flex cursor-pointer items-start gap-3 rounded-lg border px-3 py-2"
                    >
                        <Checkbox
                            :model-value="showArchived"
                            class="mt-0.5"
                            @update:model-value="
                                (checked) => (showArchived = checked === true)
                            "
                        />
                        <span>
                            <span class="font-medium">
                                Mostrar en la bandeja el historial archivado
                            </span>
                            <span class="block text-xs text-muted-foreground">
                                Las conversaciones que se archivaron al importar
                                de Whaticket pasan a verse en Conversaciones
                                (filtro «Cerradas») de la nueva conexión. Siguen
                                siendo de solo lectura.
                            </span>
                        </span>
                    </label>

                    <p class="text-xs text-muted-foreground">
                        Si un contacto tiene una conversación activa en las dos
                        conexiones, queda activa la más reciente y la otra se
                        cierra con su historial.
                    </p>
                </div>

                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="moving"
                        @click="moveOpen = false"
                    >
                        Cancelar
                    </Button>
                    <Button
                        type="button"
                        :loading="moving"
                        :disabled="target === null"
                        data-test="confirm-move"
                        @click="confirmMove"
                    >
                        <ArrowRightLeft />
                        Mover{{ targetName ? ` a ${targetName}` : '' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
