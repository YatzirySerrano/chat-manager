<script setup lang="ts">
import { Head, Link, router, useForm, usePoll } from '@inertiajs/vue3';
import {
    ArrowRightLeft,
    Hand,
    History,
    Lock,
    Megaphone,
    NotebookPen,
    Pause,
    Pencil,
    Play,
    RotateCcw,
    Undo2,
    UserPlus,
    XCircle,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConversationReasonDialog from '@/components/conversations/ConversationReasonDialog.vue';
import ConversationRouteDialog from '@/components/conversations/ConversationRouteDialog.vue';
import ConversationStatusBadge from '@/components/conversations/ConversationStatusBadge.vue';
import CustomerWindowIndicator from '@/components/conversations/CustomerWindowIndicator.vue';
import DepartmentTag from '@/components/DepartmentTag.vue';
import EmptyState from '@/components/EmptyState.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import WhatsAppText from '@/components/WhatsAppText.vue';
import { formatDateTime } from '@/lib/format';
import { formatPhone } from '@/lib/phone';
import {
    accept,
    assign,
    close,
    hold,
    release,
    reopen,
    resume,
    show,
    transfer,
} from '@/routes/conversations';
import {
    store as storeNote,
    update as updateNote,
} from '@/routes/conversations/notes';
import type {
    ContactAttribution,
    ConversationEvent,
    ConversationMessage,
    ConversationNote,
    ConversationSummary,
    CustomerWindow,
    TransferOptions,
} from '@/types';

const props = defineProps<{
    conversation: ConversationSummary;
    window: CustomerWindow;
    messages: ConversationMessage[];
    messagesTotal: number;
    notes: ConversationNote[];
    origin: ContactAttribution[];
    events: ConversationEvent[] | null;
    listUrl: string;
    contactUrl: string | null;
    transferOptions?: TransferOptions;
    transferReasonRequired: boolean;
}>();

defineOptions({
    layout: (pageProps: {
        conversation: ConversationSummary;
        listUrl: string;
    }) => ({
        breadcrumbs: [
            { title: 'Conversaciones', href: pageProps.listUrl },
            {
                title: pageProps.conversation.contact.name,
                href: show(pageProps.conversation.id),
            },
        ],
    }),
});

usePoll(15_000, {
    only: [
        'conversation',
        'window',
        'messages',
        'messagesTotal',
        'notes',
        'events',
        'origin',
    ],
});

const can = computed(() => props.conversation.can);
const processing = ref(false);
const errors = ref<Record<string, string | undefined>>({});

type Operation = 'hold' | 'release' | 'close';

const reasonDialog = ref<Operation | null>(null);
const reasonDialogOpen = computed({
    get: () => reasonDialog.value !== null,
    set: (open: boolean) => {
        if (!open) {
            reasonDialog.value = null;
        }
    },
});

const reasonCopy: Record<
    Operation,
    { title: string; description: string; confirm: string; label: string }
> = {
    hold: {
        title: 'Poner en espera',
        description:
            'La atención se pausa y conservas la conversación. Útil cuando esperas información del cliente o de otra área.',
        confirm: 'Poner en espera',
        label: 'Motivo',
    },
    release: {
        title: 'Devolver a la cola',
        description:
            'Dejarás de ser responsable y la conversación esperará en la cola de su departamento.',
        confirm: 'Devolver a la cola',
        label: 'Motivo',
    },
    close: {
        title: 'Cerrar conversación',
        description:
            'La atención termina y su historial se conserva. No se envía ningún mensaje al cliente.',
        confirm: 'Cerrar conversación',
        label: 'Motivo de cierre',
    },
};

function openReasonDialog(operation: Operation): void {
    errors.value = {};
    reasonDialog.value = operation;
}

/**
 * Origin links come from the provider; only web links are rendered.
 */
function safeUrl(url: string | null): string | null {
    return url !== null && /^https?:\/\//i.test(url) ? url : null;
}

function showFirstError(bag: Record<string, string>): void {
    errors.value = bag;
    const message = Object.values(bag)[0];

    if (message) {
        toast.error(message);
    }
}

function operate(
    url: string,
    data: Record<string, unknown> = {},
    onSuccess?: () => void,
): void {
    router.post(
        url,
        { ...data, version: props.conversation.version },
        {
            preserveScroll: true,
            onStart: () => {
                processing.value = true;
                errors.value = {};
            },
            onSuccess: () => onSuccess?.(),
            onError: showFirstError,
            onFinish: () => (processing.value = false),
        },
    );
}

const id = computed(() => props.conversation.id);

function confirmReason(reason: string): void {
    const operation = reasonDialog.value;

    if (operation === null) {
        return;
    }

    const urls: Record<Operation, string> = {
        hold: hold.url(id.value),
        release: release.url(id.value),
        close: close.url(id.value),
    };

    operate(urls[operation], { reason }, () => (reasonDialog.value = null));
}

const routeDialog = ref<'assign' | 'transfer' | null>(null);
const routeDialogOpen = computed({
    get: () => routeDialog.value !== null,
    set: (open: boolean) => {
        if (!open) {
            routeDialog.value = null;
        }
    },
});
const loadingOptions = ref(false);

function openRouteDialog(mode: 'assign' | 'transfer'): void {
    errors.value = {};
    routeDialog.value = mode;
    router.reload({
        only: ['transferOptions'],
        onStart: () => (loadingOptions.value = true),
        onFinish: () => (loadingOptions.value = false),
    });
}

function submitRoute(payload: {
    department_id: number | null;
    user_id: number | null;
    reason: string;
}): void {
    if (routeDialog.value === 'assign') {
        operate(
            assign.url(id.value),
            { user_id: payload.user_id },
            () => (routeDialog.value = null),
        );

        return;
    }

    operate(transfer.url(id.value), payload, () => (routeDialog.value = null));
}

type ToolbarAction = {
    key: string;
    label: string;
    icon: typeof Hand;
    run: () => void;
    variant?: 'default' | 'outline' | 'destructive';
};

const toolbar = computed<ToolbarAction[]>(() => {
    const actions: ToolbarAction[] = [];

    if (can.value.accept) {
        actions.push({
            key: 'accept',
            label: 'Aceptar',
            icon: Hand,
            variant: 'default',
            run: () => operate(accept.url(id.value)),
        });
    }

    if (can.value.resume) {
        actions.push({
            key: 'resume',
            label: 'Retomar',
            icon: Play,
            variant: 'default',
            run: () => operate(resume.url(id.value)),
        });
    }

    if (can.value.assign) {
        actions.push({
            key: 'assign',
            label: 'Asignar',
            icon: UserPlus,
            run: () => openRouteDialog('assign'),
        });
    }

    if (can.value.transfer) {
        actions.push({
            key: 'transfer',
            label: 'Transferir',
            icon: ArrowRightLeft,
            run: () => openRouteDialog('transfer'),
        });
    }

    if (can.value.hold) {
        actions.push({
            key: 'hold',
            label: 'Poner en espera',
            icon: Pause,
            run: () => openReasonDialog('hold'),
        });
    }

    if (can.value.release) {
        actions.push({
            key: 'release',
            label: 'Devolver a la cola',
            icon: Undo2,
            run: () => openReasonDialog('release'),
        });
    }

    if (can.value.close) {
        actions.push({
            key: 'close',
            label: 'Cerrar',
            icon: XCircle,
            variant: 'destructive',
            run: () => openReasonDialog('close'),
        });
    }

    if (can.value.reopen) {
        actions.push({
            key: 'reopen',
            label: 'Reabrir',
            icon: RotateCcw,
            run: () => operate(reopen.url(id.value)),
        });
    }

    return actions;
});

const noteForm = useForm({ body: '' });

function addNote(): void {
    noteForm.post(storeNote.url(id.value), {
        preserveScroll: true,
        onSuccess: () => noteForm.reset(),
    });
}

const editingNoteId = ref<number | null>(null);
const editForm = useForm({ body: '' });

function startEditing(note: ConversationNote): void {
    editingNoteId.value = note.id;
    editForm.defaults({ body: note.body });
    editForm.reset();
    editForm.clearErrors();
}

function saveNote(note: ConversationNote): void {
    editForm.put(updateNote.url({ conversation: id.value, note: note.id }), {
        preserveScroll: true,
        onSuccess: () => (editingNoteId.value = null),
    });
}

function eventDetail(event: ConversationEvent): string {
    const parts: string[] = [];

    if (event.from_user || event.to_user) {
        parts.push(
            [event.from_user, event.to_user]
                .map((name) => name ?? 'cola')
                .join(' → '),
        );
    }

    if (event.to_department && event.to_department !== event.from_department) {
        parts.push(
            event.from_department
                ? `${event.from_department} → ${event.to_department}`
                : event.to_department,
        );
    }

    if (event.trigger === 'inbound') {
        parts.push('el cliente volvió a escribir');
    } else if (event.trigger === 'inactivity') {
        parts.push('por inactividad');
    }

    return parts.join(' · ');
}

const isClosed = computed(() => props.conversation.status === 'closed');
</script>

<template>
    <Head :title="`Conversación con ${conversation.contact.name}`" />

    <div
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8"
    >
        <PageHeader
            :title="conversation.contact.name"
            :description="`${conversation.contact.phone_e164 ? formatPhone(conversation.contact.phone_e164) : 'Sin teléfono'} · ${conversation.connection.name}`"
        >
            <template #actions>
                <Button v-if="contactUrl" variant="outline" as-child>
                    <Link :href="contactUrl">Ver contacto</Link>
                </Button>
            </template>
        </PageHeader>

        <SurfaceCard
            body-class="flex flex-col gap-4 p-4 lg:flex-row lg:items-center"
        >
            <dl
                class="grid flex-1 grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-4"
            >
                <div class="grid gap-1">
                    <dt class="text-xs text-muted-foreground">Estado</dt>
                    <dd>
                        <ConversationStatusBadge
                            :status="conversation.status"
                            :label="conversation.status_label"
                        />
                    </dd>
                </div>
                <div class="grid gap-1">
                    <dt class="text-xs text-muted-foreground">Departamento</dt>
                    <dd>
                        <DepartmentTag
                            v-if="conversation.department"
                            :name="conversation.department.name"
                            :color="conversation.department.color"
                            :muted="!conversation.department.is_active"
                        />
                        <span v-else class="text-muted-foreground"
                            >Sin departamento</span
                        >
                    </dd>
                </div>
                <div class="grid gap-1">
                    <dt class="text-xs text-muted-foreground">Responsable</dt>
                    <dd class="truncate">
                        {{ conversation.assignee?.name ?? 'Sin agente' }}
                    </dd>
                </div>
                <div class="grid gap-1">
                    <dt class="text-xs text-muted-foreground">Inicio</dt>
                    <dd>{{ formatDateTime(conversation.created_at) }}</dd>
                </div>
            </dl>
            <div
                v-if="toolbar.length > 0"
                class="flex flex-wrap gap-2"
                role="group"
                aria-label="Operaciones de la conversación"
            >
                <Button
                    v-for="action in toolbar"
                    :key="action.key"
                    size="sm"
                    :variant="action.variant ?? 'outline'"
                    :disabled="processing"
                    @click="action.run"
                >
                    <component :is="action.icon" aria-hidden="true" />
                    {{ action.label }}
                </Button>
            </div>
        </SurfaceCard>

        <p
            v-if="
                conversation.status === 'on_hold' && conversation.on_hold_reason
            "
            class="rounded-lg border bg-primary-soft/50 px-4 py-2 text-sm text-primary-soft-foreground"
        >
            En espera desde {{ formatDateTime(conversation.on_hold_at) }}:
            {{ conversation.on_hold_reason }}
        </p>
        <InputError :message="errors.conversation" />

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
            <SurfaceCard
                title="Mensajes"
                :description="
                    messagesTotal > messages.length
                        ? `Últimos ${messages.length} de ${messagesTotal} mensajes.`
                        : 'Historial de mensajes con el cliente.'
                "
                body-class="grid gap-4 p-4"
            >
                <CustomerWindowIndicator v-if="!isClosed" :window="window" />

                <EmptyState
                    v-if="messages.length === 0"
                    class="border-none"
                    :icon="NotebookPen"
                    title="Sin mensajes"
                    description="Esta conversación todavía no tiene mensajes."
                />
                <ol v-else class="grid gap-2" aria-label="Mensajes">
                    <li
                        v-for="message in messages"
                        :key="message.id"
                        class="flex"
                        :class="
                            message.direction === 'outbound'
                                ? 'justify-end'
                                : 'justify-start'
                        "
                    >
                        <div
                            class="max-w-[85%] rounded-xl px-3 py-2 text-sm shadow-xs"
                            :class="
                                message.direction === 'outbound'
                                    ? 'bg-primary-soft text-primary-soft-foreground'
                                    : 'border bg-card'
                            "
                        >
                            <WhatsAppText
                                v-if="message.body"
                                :text="message.body"
                                class="break-words whitespace-pre-line"
                            />
                            <p v-else class="text-muted-foreground italic">
                                Mensaje de tipo «{{ message.type }}» sin texto
                            </p>
                            <p
                                class="mt-1 text-right text-[11px] text-muted-foreground"
                            >
                                <template v-if="message.sender"
                                    >{{ message.sender.name }} ·
                                </template>
                                {{ formatDateTime(message.sent_at) }}
                                <template v-if="message.error_message">
                                    ·
                                    <span class="text-destructive">{{
                                        message.error_message
                                    }}</span>
                                </template>
                            </p>
                        </div>
                    </li>
                </ol>
                <p
                    class="flex items-start gap-2 rounded-lg border border-dashed px-3 py-2 text-xs text-muted-foreground"
                >
                    <Lock class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                    El envío de respuestas desde la bandeja se habilitará al
                    conectar WhatsApp Cloud API. Las reglas de envío y la
                    ventana de 24 horas ya se validan en el servidor.
                </p>
            </SurfaceCard>

            <div class="grid content-start gap-6">
                <SurfaceCard
                    title="Notas internas"
                    description="Solo las ve el equipo; nunca se envían al cliente."
                    body-class="grid gap-3 p-4"
                >
                    <form
                        v-if="can.addNote"
                        class="grid gap-2"
                        @submit.prevent="addNote"
                    >
                        <label for="new-note" class="sr-only"
                            >Nueva nota interna</label
                        >
                        <Textarea
                            id="new-note"
                            v-model="noteForm.body"
                            rows="3"
                            maxlength="2000"
                            placeholder="Escribe una nota para el equipo"
                        />
                        <InputError :message="noteForm.errors.body" />
                        <Button
                            type="submit"
                            size="sm"
                            class="justify-self-end"
                            :disabled="
                                noteForm.processing ||
                                noteForm.body.trim() === ''
                            "
                        >
                            <Spinner v-if="noteForm.processing" />
                            Agregar nota
                        </Button>
                    </form>
                    <p
                        v-if="notes.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        Sin notas internas.
                    </p>
                    <ul v-else class="grid gap-2">
                        <li
                            v-for="note in notes"
                            :key="note.id"
                            class="rounded-lg border border-warning/30 bg-warning/5 p-3 text-sm"
                        >
                            <form
                                v-if="editingNoteId === note.id"
                                class="grid gap-2"
                                @submit.prevent="saveNote(note)"
                            >
                                <label :for="`note-${note.id}`" class="sr-only"
                                    >Editar nota</label
                                >
                                <Textarea
                                    :id="`note-${note.id}`"
                                    v-model="editForm.body"
                                    rows="3"
                                    maxlength="2000"
                                />
                                <InputError :message="editForm.errors.body" />
                                <div class="flex justify-end gap-2">
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        @click="editingNoteId = null"
                                        >Cancelar</Button
                                    >
                                    <Button
                                        type="submit"
                                        size="sm"
                                        :disabled="editForm.processing"
                                        >Guardar</Button
                                    >
                                </div>
                            </form>
                            <template v-else>
                                <p class="break-words whitespace-pre-line">
                                    {{ note.body }}
                                </p>
                                <div
                                    class="mt-2 flex items-center justify-between gap-2 text-xs text-muted-foreground"
                                >
                                    <span>
                                        {{
                                            note.author?.name ??
                                            'Usuario eliminado'
                                        }}
                                        · {{ formatDateTime(note.created_at) }}
                                        <template v-if="note.edited_at">
                                            · editada</template
                                        >
                                    </span>
                                    <Button
                                        v-if="note.can.update"
                                        variant="ghost"
                                        size="icon-sm"
                                        class="size-8"
                                        aria-label="Editar nota"
                                        @click="startEditing(note)"
                                    >
                                        <Pencil aria-hidden="true" />
                                    </Button>
                                </div>
                            </template>
                        </li>
                    </ul>
                </SurfaceCard>

                <SurfaceCard
                    title="Procedencia"
                    description="Referencia publicitaria que envió Meta con esta conversación."
                    body-class="grid gap-2 p-4 text-sm"
                >
                    <template v-if="origin.length > 0">
                        <div
                            v-for="attribution in origin"
                            :key="attribution.id"
                            class="grid gap-1"
                        >
                            <p class="flex items-center gap-1.5 font-medium">
                                <Megaphone class="size-4" aria-hidden="true" />
                                {{ attribution.origin_label }}
                            </p>
                            <p class="text-xs text-success">
                                {{ attribution.reliability_label }}
                            </p>
                            <p v-if="attribution.headline">
                                {{ attribution.headline }}
                            </p>
                            <a
                                v-if="safeUrl(attribution.source_url)"
                                :href="
                                    safeUrl(attribution.source_url) ?? undefined
                                "
                                target="_blank"
                                rel="noopener noreferrer"
                                class="truncate text-xs text-primary underline-offset-2 hover:underline"
                                >{{ attribution.source_url }}</a
                            >
                            <p
                                v-if="attribution.source_id"
                                class="text-xs text-muted-foreground"
                            >
                                ID de origen: {{ attribution.source_id }}
                            </p>
                        </div>
                    </template>
                    <p v-else class="text-muted-foreground">
                        Origen no identificado: Meta no envió una referencia con
                        esta conversación.
                    </p>
                </SurfaceCard>

                <SurfaceCard
                    v-if="events !== null"
                    title="Historial operativo"
                    description="Asignaciones, transferencias, pausas y cierres."
                    body-class="p-4"
                >
                    <p
                        v-if="events.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        Sin eventos registrados.
                    </p>
                    <ol
                        v-else
                        class="grid gap-3"
                        aria-label="Historial operativo"
                    >
                        <li
                            v-for="event in events"
                            :key="event.id"
                            class="grid grid-cols-[auto_1fr] gap-x-3 text-sm"
                        >
                            <History
                                class="mt-0.5 size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <div class="min-w-0">
                                <p class="font-medium">{{ event.label }}</p>
                                <p
                                    v-if="eventDetail(event)"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ eventDetail(event) }}
                                </p>
                                <p
                                    v-if="event.note"
                                    class="text-xs break-words"
                                >
                                    «{{ event.note }}»
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ event.actor ?? 'Sistema' }} ·
                                    {{ formatDateTime(event.created_at) }}
                                </p>
                            </div>
                        </li>
                    </ol>
                </SurfaceCard>
            </div>
        </div>

        <ConversationReasonDialog
            v-model:open="reasonDialogOpen"
            :title="reasonDialog ? reasonCopy[reasonDialog].title : ''"
            :description="
                reasonDialog ? reasonCopy[reasonDialog].description : ''
            "
            :confirm-label="
                reasonDialog ? reasonCopy[reasonDialog].confirm : ''
            "
            :reason-label="
                reasonDialog ? reasonCopy[reasonDialog].label : undefined
            "
            :destructive="reasonDialog === 'close'"
            :processing="processing"
            :error="errors.reason ?? errors.conversation"
            @confirm="confirmReason"
        />

        <ConversationRouteDialog
            v-if="routeDialog"
            v-model:open="routeDialogOpen"
            :mode="routeDialog"
            :conversation="conversation"
            :options="transferOptions"
            :loading="loadingOptions"
            :processing="processing"
            :reason-required="transferReasonRequired"
            :errors="errors"
            @submit="submitRoute"
        />
    </div>
</template>
