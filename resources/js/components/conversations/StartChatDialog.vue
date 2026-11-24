<script setup lang="ts">
import { router, useHttp } from "@inertiajs/vue3";
import {
    MessageSquarePlus,
    Phone,
    QrCode,
    Search,
    TriangleAlert,
    UserRound,
} from "@lucide/vue";
import { refDebounced } from "@vueuse/core";
import { computed, ref, watch } from "vue";
import {
    contacts as startContacts,
    options as startOptions,
    store as startStore,
} from "@/actions/App/Http/Controllers/Conversations/ConversationStartController";
import FormField from "@/components/FormField.vue";
import InputError from "@/components/InputError.vue";
import { AppSelect } from "@/components/ui/app-select";
import { Button } from "@/components/ui/button";
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Spinner } from "@/components/ui/spinner";
import { colorPresentations } from "@/lib/colors";
import { PHONE_SHEET_DIALOG } from "@/lib/phoneSheet";
import type { AppColor } from "@/types";

/**
 * «Iniciar un chat»: the agent writes first through a WhatsApp connection
 * (a phone linked by QR or WhatsApp Cloud API) to a contact of the
 * directory, someone who wrote recently or a number typed by hand. The
 * server opens (or reuses) the conversation, assigns it to the agent and
 * opens it in the inbox, where the message is written as usual.
 *
 * `contact` preselects a person (the contacts list's «Iniciar chat»).
 */
type StartConnection = {
    id: number;
    name: string;
    phone: string | null;
    modality: "qr" | "cloud_api";
    blocked_reason: string | null;
};

type StartDepartment = { id: number; name: string; color: AppColor };

type StartOptions = {
    connections: StartConnection[];
    connection_id: number | null;
    departments: StartDepartment[];
    default_department_id: number | null;
};

type StartContact = {
    id: number;
    name: string;
    phone: string | null;
    avatar_url: string | null;
    last_interaction_at: string | null;
};

type ContactResults = {
    contacts: StartContact[];
    typed_phone: string | null;
};

const props = defineProps<{
    contact?: { id: number; name: string; phone: string | null } | null;
}>();

const open = defineModel<boolean>("open", { required: true });

const optionsRequest = useHttp<Record<string, never>, StartOptions>({});
const contactsRequest = useHttp<Record<string, never>, ContactResults>({});

const options = ref<StartOptions | null>(null);
const loadingOptions = ref(false);
const connectionId = ref<number | null>(null);
const departmentId = ref<number | null>(null);

const search = ref("");
const debouncedSearch = refDebounced(search, 250);
const results = ref<ContactResults>({ contacts: [], typed_phone: null });
const loadingContacts = ref(false);
const selected = ref<StartContact | null>(null);
/** Writing to the typed number instead of a contact of the list. */
const typedNumber = ref<string | null>(null);
const newName = ref("");

const processing = ref(false);
const errors = ref<Record<string, string | undefined>>({});

const connection = computed(
    () =>
        options.value?.connections.find(
            (item) => item.id === connectionId.value,
        ) ?? null,
);

const connectionOptions = computed(() =>
    (options.value?.connections ?? []).map((item) => ({
        value: item.id,
        label: item.name,
        description: [
            item.phone,
            item.modality === "qr" ? "WhatsApp (QR)" : "WhatsApp API de Meta",
        ]
            .filter(Boolean)
            .join(" · "),
        disabled: item.blocked_reason !== null,
    })),
);

const departmentOptions = computed(() =>
    (options.value?.departments ?? []).map((item) => ({
        value: item.id,
        label: item.name,
    })),
);

const department = computed(
    () =>
        options.value?.departments.find(
            (item) => item.id === departmentId.value,
        ) ?? null,
);

let latestOptions = 0;

async function loadOptions(forConnection: number | null): Promise<void> {
    const request = ++latestOptions;
    loadingOptions.value = true;
    optionsRequest.cancel();

    try {
        const result = await optionsRequest.get(
            startOptions.url({
                query: forConnection ? { connection_id: forConnection } : {},
            }),
        );

        if (request !== latestOptions) {
            return;
        }

        options.value = result;
        connectionId.value = result.connection_id;
        departmentId.value = result.default_department_id;
    } catch {
        if (request === latestOptions) {
            errors.value = {
                start: "No se pudieron cargar las conexiones. Cierra el diálogo e inténtalo de nuevo.",
            };
        }
    } finally {
        if (request === latestOptions) {
            loadingOptions.value = false;
        }
    }
}

let latestContacts = 0;

async function loadContacts(term: string): Promise<void> {
    const request = ++latestContacts;
    loadingContacts.value = true;
    contactsRequest.cancel();

    try {
        const result = await contactsRequest.get(
            startContacts.url({ query: term ? { q: term } : {} }),
        );

        if (request === latestContacts) {
            results.value = result;
        }
    } catch {
        if (request === latestContacts) {
            results.value = { contacts: [], typed_phone: null };
        }
    } finally {
        if (request === latestContacts) {
            loadingContacts.value = false;
        }
    }
}

watch(
    open,
    (isOpen) => {
        if (!isOpen) {
            return;
        }

        errors.value = {};
        search.value = "";
        newName.value = "";
        typedNumber.value = null;
        selected.value = props.contact
            ? {
                  id: props.contact.id,
                  name: props.contact.name,
                  phone: props.contact.phone,
                  avatar_url: null,
                  last_interaction_at: null,
              }
            : null;
        void loadOptions(null);

        if (!props.contact) {
            void loadContacts("");
        }
    },
    { immediate: true },
);

/* Another connection has its own departments. */
function chooseConnection(value: number | null | undefined): void {
    if (value === null || value === undefined || value === connectionId.value) {
        return;
    }

    connectionId.value = value;
    departmentId.value = null;
    void loadOptions(value);
}

watch(debouncedSearch, (term) => {
    if (open.value && !selected.value && typedNumber.value === null) {
        void loadContacts(term.trim());
    }
});

function pick(contact: StartContact): void {
    selected.value = contact;
    typedNumber.value = null;
    errors.value = {};
}

function useTypedNumber(): void {
    typedNumber.value = results.value.typed_phone ?? search.value.trim();
    selected.value = null;
    errors.value = {};
}

function changeRecipient(): void {
    selected.value = null;
    typedNumber.value = null;
    void loadContacts(search.value.trim());
}

const canSubmit = computed(
    () =>
        !processing.value &&
        !loadingOptions.value &&
        connection.value !== null &&
        connection.value.blocked_reason === null &&
        departmentId.value !== null &&
        (selected.value !== null || typedNumber.value !== null),
);

function submit(): void {
    if (!canSubmit.value) {
        return;
    }

    router.post(
        startStore.url(),
        {
            connection_id: connectionId.value,
            department_id: departmentId.value,
            ...(selected.value
                ? { contact_id: selected.value.id }
                : {
                      phone: typedNumber.value,
                      name: newName.value.trim() || null,
                  }),
        },
        {
            preserveScroll: true,
            onStart: () => {
                processing.value = true;
                errors.value = {};
            },
            onSuccess: () => {
                open.value = false;
            },
            onError: (failed) => {
                errors.value = failed;
            },
            onFinish: () => {
                processing.value = false;
            },
        },
    );
}

function initials(name: string): string {
    return (
        name
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((word) => word[0]?.toUpperCase() ?? "")
            .join("") || "#"
    );
}

const recipientError = computed(
    () => errors.value.contact_id ?? errors.value.phone,
);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            :class="[
                'max-h-[90dvh] overflow-y-auto sm:max-w-md',
                PHONE_SHEET_DIALOG,
            ]"
            data-start-chat-dialog
        >
            <form class="grid gap-4" novalidate @submit.prevent="submit">
                <DialogHeader>
                    <div class="flex items-center gap-3">
                        <span
                            class="grid size-10 shrink-0 place-items-center rounded-lg bg-primary text-primary-foreground"
                            aria-hidden="true"
                        >
                            <MessageSquarePlus class="size-5" />
                        </span>
                        <div class="min-w-0 text-left">
                            <DialogTitle>Iniciar un chat</DialogTitle>
                            <DialogDescription>
                                Elige una conexión para empezar a conversar.
                            </DialogDescription>
                        </div>
                    </div>
                </DialogHeader>

                <p
                    v-if="loadingOptions && !options"
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <Spinner /> Cargando conexiones…
                </p>

                <p
                    v-else-if="options && options.connections.length === 0"
                    class="flex gap-2 rounded-lg border border-warning/50 bg-warning-soft p-3 text-sm text-warning-soft-foreground"
                    role="alert"
                >
                    <TriangleAlert
                        class="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    No hay conexiones de WhatsApp activas en tus departamentos.
                    Pide a un administrador que conecte una por QR o con la API
                    de Meta.
                </p>

                <template v-else-if="options">
                    <FormField
                        id="start-connection"
                        label="Conexión"
                        :error="errors.connection_id"
                    >
                        <AppSelect
                            id="start-connection"
                            :model-value="connectionId"
                            :options="connectionOptions"
                            @update:model-value="chooseConnection"
                        />
                    </FormField>

                    <p
                        v-if="connection?.blocked_reason"
                        class="-mt-2 text-sm text-destructive"
                        role="alert"
                    >
                        {{ connection.blocked_reason }}
                    </p>
                    <p
                        v-else-if="connection?.modality === 'cloud_api'"
                        class="-mt-2 flex gap-1.5 text-xs text-muted-foreground"
                    >
                        <TriangleAlert
                            class="mt-px size-3.5 shrink-0"
                            aria-hidden="true"
                        />
                        Con la API de Meta, si el cliente no te ha escrito en
                        las últimas 24 horas el primer mensaje debe ser una
                        plantilla aprobada.
                    </p>
                    <p
                        v-else-if="connection?.modality === 'qr'"
                        class="-mt-2 flex gap-1.5 text-xs text-muted-foreground"
                    >
                        <QrCode
                            class="mt-px size-3.5 shrink-0"
                            aria-hidden="true"
                        />
                        Teléfono vinculado por QR: puedes escribir libremente.
                    </p>

                    <FormField
                        id="start-department"
                        label="Departamento"
                        :error="errors.department_id"
                        :hint="
                            options.departments.length === 0
                                ? 'No trabajas en ningún departamento de esta conexión.'
                                : undefined
                        "
                    >
                        <div class="flex items-center gap-2">
                            <span
                                v-if="department"
                                class="size-2.5 shrink-0 rounded-full"
                                v-bind="
                                    colorPresentations(department.color).dot
                                "
                                aria-hidden="true"
                            />
                            <AppSelect
                                id="start-department"
                                v-model="departmentId"
                                :options="departmentOptions"
                                placeholder="Elige un departamento"
                                :disabled="loadingOptions"
                            />
                        </div>
                    </FormField>

                    <div class="grid gap-2">
                        <span class="text-sm font-medium">Para</span>

                        <div
                            v-if="selected || typedNumber"
                            class="flex items-center gap-3 rounded-lg border bg-primary-soft/50 p-3"
                            data-start-chat-recipient
                        >
                            <img
                                v-if="selected?.avatar_url"
                                :src="selected.avatar_url"
                                alt=""
                                class="size-10 shrink-0 rounded-full object-cover"
                            />
                            <span
                                v-else
                                class="grid size-10 shrink-0 place-items-center rounded-full bg-muted text-sm font-medium text-muted-foreground"
                                aria-hidden="true"
                            >
                                <Phone v-if="typedNumber" class="size-4" />
                                <template v-else>{{
                                    initials(selected?.name ?? "")
                                }}</template>
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-medium uppercase">
                                    {{ selected?.name ?? "Número nuevo" }}
                                </p>
                                <p
                                    class="truncate text-sm text-muted-foreground"
                                >
                                    {{ selected?.phone ?? typedNumber }}
                                </p>
                            </div>
                            <Button
                                v-if="!props.contact"
                                type="button"
                                variant="ghost"
                                size="sm"
                                @click="changeRecipient"
                            >
                                Cambiar
                            </Button>
                        </div>

                        <FormField
                            v-if="typedNumber"
                            id="start-name"
                            label="Nombre del contacto (opcional)"
                            hint="Si el número no está registrado, se guarda con este nombre."
                        >
                            <Input
                                id="start-name"
                                v-model="newName"
                                maxlength="255"
                                autocomplete="off"
                            />
                        </FormField>

                        <template v-if="!selected && !typedNumber">
                            <div class="relative">
                                <Search
                                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <Input
                                    v-model="search"
                                    type="search"
                                    class="pl-9"
                                    placeholder="Busca un contacto o escribe un número"
                                    aria-label="Buscar contacto o escribir número"
                                    autocomplete="off"
                                    @keydown.enter.prevent="
                                        results.typed_phone && useTypedNumber()
                                    "
                                />
                            </div>

                            <button
                                v-if="results.typed_phone"
                                type="button"
                                class="flex items-center gap-3 rounded-lg border border-dashed p-3 text-left hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                @click="useTypedNumber"
                            >
                                <span
                                    class="grid size-9 shrink-0 place-items-center rounded-full bg-primary text-primary-foreground"
                                    aria-hidden="true"
                                >
                                    <Phone class="size-4" />
                                </span>
                                <span class="min-w-0">
                                    <span class="block text-sm font-medium"
                                        >Escribir a
                                        {{ results.typed_phone }}</span
                                    >
                                    <span
                                        class="block text-xs text-muted-foreground"
                                        >Aunque no esté en tus contactos</span
                                    >
                                </span>
                            </button>

                            <p
                                class="text-xs font-medium text-muted-foreground"
                            >
                                {{
                                    search.trim()
                                        ? "Contactos encontrados"
                                        : "Recientes"
                                }}
                            </p>

                            <p
                                v-if="
                                    loadingContacts &&
                                    results.contacts.length === 0
                                "
                                class="flex items-center gap-2 text-sm text-muted-foreground"
                            >
                                <Spinner /> Buscando…
                            </p>
                            <p
                                v-else-if="results.contacts.length === 0"
                                class="text-sm text-muted-foreground"
                            >
                                {{
                                    search.trim()
                                        ? "Ningún contacto coincide. Escribe el número completo con lada para escribirle."
                                        : "Aún no hay contactos con WhatsApp."
                                }}
                            </p>
                            <ul
                                v-else
                                class="-mx-1 grid max-h-64 gap-0.5 overflow-y-auto"
                                :aria-busy="loadingContacts"
                            >
                                <li
                                    v-for="item in results.contacts"
                                    :key="item.id"
                                >
                                    <button
                                        type="button"
                                        class="flex w-full items-center gap-3 rounded-md px-2 py-2 text-left hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                        @click="pick(item)"
                                    >
                                        <img
                                            v-if="item.avatar_url"
                                            :src="item.avatar_url"
                                            alt=""
                                            loading="lazy"
                                            class="size-9 shrink-0 rounded-full object-cover"
                                        />
                                        <span
                                            v-else
                                            class="grid size-9 shrink-0 place-items-center rounded-full bg-muted text-xs font-medium text-muted-foreground"
                                            aria-hidden="true"
                                        >
                                            <UserRound
                                                v-if="!item.name"
                                                class="size-4"
                                            />
                                            <template v-else>{{
                                                initials(item.name)
                                            }}</template>
                                        </span>
                                        <span class="min-w-0">
                                            <span
                                                class="block truncate text-sm font-medium"
                                                >{{ item.name }}</span
                                            >
                                            <span
                                                class="block truncate text-xs text-muted-foreground"
                                                >{{ item.phone }}</span
                                            >
                                        </span>
                                    </button>
                                </li>
                            </ul>
                        </template>

                        <InputError :message="recipientError" />
                    </div>
                </template>

                <p
                    v-if="errors.start"
                    class="flex gap-2 rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive"
                    role="alert"
                >
                    <TriangleAlert
                        class="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    {{ errors.start }}
                </p>

                <DialogFooter class="gap-2">
                    <Button type="button" variant="ghost" @click="open = false">
                        Cancelar
                    </Button>
                    <Button type="submit" :disabled="!canSubmit">
                        <Spinner v-if="processing" />
                        <MessageSquarePlus v-else aria-hidden="true" />
                        Iniciar chat
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
