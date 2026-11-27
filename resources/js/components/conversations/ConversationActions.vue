<script setup lang="ts">
import { router, useHttp } from '@inertiajs/vue3';
import {
    ArrowLeftRight,
    BadgeCheck,
    BellOff,
    CircleCheck,
    CircleHelp,
    EllipsisVertical,
    Hand,
    MessageCircleOff,
    RotateCcw,
    ShieldAlert,
    Trash2,
    Undo2,
    UserPlus,
} from '@lucide/vue';
import { computed, defineAsyncComponent, inject, ref } from 'vue';
import { toast } from 'vue-sonner';
import {
    ACCEPT_BLOCK,
    noAcceptBlock,
} from '@/components/conversations/acceptBlock';
import ConversationReasonDialog from '@/components/conversations/ConversationReasonDialog.vue';
import PermanentDeleteDialog from '@/components/PermanentDeleteDialog.vue';
import {
    isAwaitingInfo,
    noQueueActions,
    QUEUE_ACTIONS,
    useAcceptance,
} from '@/components/conversations/queueRules';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    conversationActionErrorMessage,
    conversationActionFailure,
    conversationConflictFromErrors,
    queueConflictFromErrors,
    withoutConflictCode,
} from '@/lib/conversations/actionErrors';
import { isWhatsAppWebProvider } from '@/lib/whatsappWeb';
import {
    accept,
    assign,
    close,
    destinations,
    release,
    reopen,
    transfer,
} from '@/routes/conversations';
import {
    destroy as undoNotRequestRoute,
    store as markNotRequestRoute,
} from '@/routes/conversations/not-request';
import type { ConversationSummary, TransferOptions } from '@/types';

// Loaded the first time it is shown: it is not needed to paint the page.
const ConversationRouteDialog = defineAsyncComponent(
    () => import('@/components/conversations/ConversationRouteDialog.vue'),
);
const CompleteRequestDialog = defineAsyncComponent(
    () => import('@/components/conversations/CompleteRequestDialog.vue'),
);

/**
 * Operations of the engine on the open conversation (accept, assign,
 * transfer, release, close, reopen), shown according to the policy's
 * answer for the viewer. There is no manual availability: accepting only
 * depends on what the server allows (can.accept). Each one sends the conversation version,
 * so a decision taken on stale data is rejected by the server. A refusal
 * because the conversation changed meanwhile (409, actionErrors.ts) closes
 * the dialog and shows the explanation over the fresh state.
 *
 * Queue rules: «Aceptar» stays disabled while the request cannot be taken
 * (the reason is shown as text under the header, AcceptanceNotice), and the
 * menu offers the readiness change and the justified exception to whoever
 * may; both dialogs belong to the inbox page (QUEUE_ACTIONS).
 */
const props = defineProps<{
    conversation: ConversationSummary;
    transferReasonRequired: boolean;
}>();

const can = computed(() => props.conversation.can);
const id = computed(() => props.conversation.id);
const processing = ref(false);
const errors = ref<Record<string, string | undefined>>({});

/**
 * The conversation changed meanwhile (409): the answer already brought its
 * current state, so the dialogs (built on the old one) close and only the
 * explanation remains.
 */
function closeDialogsForConflict(message: string): void {
    errors.value = {};
    reasonDialog.value = null;
    routeDialog.value = null;
    completeDialogOpen.value = false;
    toast.error(message);
}

/**
 * A queue rule refused an assignment or transfer and the viewer may justify
 * an exception: the route dialog asks for the reason.
 */
const queueException = ref<{ rule: string } | null>(null);

function showFirstError(bag: Record<string, string>): void {
    const conflict = conversationConflictFromErrors(bag);
    const queueConflict = queueConflictFromErrors(bag);

    queueException.value =
        queueConflict?.overridable && routeDialog.value !== null
            ? { rule: queueConflict.code }
            : queueException.value !== null && bag.override_reason
              ? queueException.value
              : null;

    if (conflict) {
        closeDialogsForConflict(conflict.message);

        return;
    }

    errors.value = withoutConflictCode(bag);
    const message = Object.values(errors.value)[0];

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
            preserveState: true,
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

type Operation = 'release' | 'close';

const reasonDialog = ref<Operation | null>(null);
const reasonDialogOpen = computed({
    get: () => reasonDialog.value !== null,
    set: (open: boolean) => {
        if (!open) {
            reasonDialog.value = null;
        }
    },
});

/*
 * Closing never changes WhatsApp's 24 h window; other channels, and a
 * number connected through WhatsApp Web (QR), have none.
 */
const closeEffect = computed(() =>
    props.conversation.connection.farewell
        ? 'Se envía al cliente el mensaje de despedida de la conexión.'
        : props.conversation.connection.channel === 'whatsapp' &&
            !isWhatsAppWebProvider(props.conversation.connection.provider)
          ? 'No se envía ningún mensaje al cliente ni cambia la ventana de WhatsApp.'
          : `No se envía ningún mensaje al cliente por ${props.conversation.connection.channel_label}.`,
);

const reasonCopy = computed<
    Record<
        Operation,
        { title: string; description: string; confirm: string; label: string }
    >
>(() => ({
    release: {
        title: 'Devolver a Pendientes',
        description:
            'Dejarás de ser responsable y la conversación volverá a «Pendientes» de su departamento para que otro agente la tome.',
        confirm: 'Devolver a Pendientes',
        label: 'Motivo',
    },
    close: {
        title: closeAsCompleted.value
            ? 'Solicitud completada'
            : 'Cerrar conversación',
        description: `La atención termina y su historial se conserva. ${closeEffect.value}`,
        confirm: closeAsCompleted.value
            ? 'Completar y cerrar'
            : 'Cerrar conversación',
        label: 'Motivo de cierre',
    },
}));

/** The close dialog opened from «Solicitud completada» (box ticked). */
const closeAsCompleted = ref(false);

function openReasonDialog(operation: Operation, completed = false): void {
    errors.value = {};
    closeAsCompleted.value = completed;
    reasonDialog.value = operation;
}

function confirmReason(reason: string, completed = false): void {
    const operation = reasonDialog.value;

    if (operation === null) {
        return;
    }

    const urls: Record<Operation, string> = {
        release: release.url(id.value),
        close: close.url(id.value),
    };

    operate(
        urls[operation],
        operation === 'close' ? { reason, completed } : { reason },
        () => (reasonDialog.value = null),
    );
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
const transferOptions = ref<TransferOptions | undefined>(undefined);

/**
 * Destinations come from a JSON endpoint authorized by the assign/transfer
 * policy of this conversation; only the latest answer is kept.
 */
const destinationsRequest = useHttp<Record<string, never>, TransferOptions>({});
let latestDestinations = 0;

async function loadRouteOptions(): Promise<void> {
    const request = ++latestDestinations;
    loadingOptions.value = true;
    destinationsRequest.cancel();

    try {
        const result = await destinationsRequest.get(
            destinations.url(id.value, {
                query: { version: props.conversation.version },
            }),
        );

        if (request === latestDestinations) {
            transferOptions.value = result;
        }
    } catch (failure) {
        if (request !== latestDestinations || routeDialog.value === null) {
            return;
        }

        const refused = conversationActionFailure(failure);

        // Refused (403), out of reach (404) or changed meanwhile (409): say
        // why and show the conversation as it is now.
        if (refused && [403, 404, 409].includes(refused.status)) {
            closeDialogsForConflict(
                conversationActionErrorMessage(refused.status, refused.body),
            );
            router.reload();

            return;
        }

        toast.error(
            'No se pudieron cargar los destinos. Cierra el diálogo e inténtalo de nuevo.',
        );
    } finally {
        if (request === latestDestinations) {
            loadingOptions.value = false;
        }
    }
}

function openRouteDialog(mode: 'assign' | 'transfer'): void {
    errors.value = {};
    queueException.value = null;
    transferOptions.value = undefined;
    routeDialog.value = mode;
    void loadRouteOptions();
}

function submitRoute(payload: {
    department_id: number | null;
    user_id: number | null;
    reason: string;
    confirm_over_capacity: boolean;
    override_reason?: string;
}): void {
    if (routeDialog.value === 'assign') {
        operate(
            assign.url(id.value),
            {
                user_id: payload.user_id,
                confirm_over_capacity: payload.confirm_over_capacity,
                ...(payload.override_reason
                    ? { override_reason: payload.override_reason }
                    : {}),
            },
            () => (routeDialog.value = null),
        );

        return;
    }

    operate(transfer.url(id.value), payload, () => (routeDialog.value = null));
}

type Action = {
    key: string;
    label: string;
    icon: typeof Hand;
    run: () => void;
    destructive?: boolean;
    disabled?: boolean;
    hint?: string;
    /** Id of the visible text that explains why it is disabled. */
    describedBy?: string;
    /** «Confirmar solicitud completa»: success tone, to stand out. */
    success?: boolean;
};

/* Disabled with the reason while customers wait too long for the viewer. */
const acceptBlock = inject(ACCEPT_BLOCK, noAcceptBlock);
const queueActions = inject(QUEUE_ACTIONS, noQueueActions);
const acceptance = useAcceptance(() => props.conversation);

/*
 * «Esperando información del cliente»: nobody takes it while it waits for
 * the customer, so a disabled «Aceptar» is not offered (the notice under the
 * header explains it); the main step is confirming it complete.
 */
const awaitingInfo = computed(() => isAwaitingInfo(props.conversation));
const confirmAsPrimary = computed(
    () => awaitingInfo.value && can.value.confirm_ready,
);

/** The main next step, shown as a button. */
const primary = computed<Action[]>(() => {
    const actions: Action[] = [];

    if (confirmAsPrimary.value) {
        actions.push({
            key: 'readiness',
            label: 'Confirmar solicitud completa',
            icon: CircleCheck,
            hint: 'El cliente ya envió lo que faltaba: la solicitud regresa a «Pendientes» como nueva',
            success: true,
            describedBy: `acceptance-chat-${id.value}`,
            run: () => queueActions.readiness(props.conversation, 'ready'),
        });
    }

    if (can.value.accept && !awaitingInfo.value) {
        const blocked =
            acceptBlock.value?.blocked === true || acceptance.value.blocked;

        actions.push({
            key: 'accept',
            label: 'Aceptar',
            icon: Hand,
            hint: blocked
                ? (acceptance.value.text ??
                  acceptBlock.value?.message ??
                  undefined)
                : 'Hacerte responsable de esta conversación',
            disabled: blocked,
            describedBy:
                acceptance.value.blocked &&
                acceptance.value.code !== 'pending_attention'
                    ? `acceptance-chat-${id.value}`
                    : undefined,
            run: () => operate(accept.url(id.value)),
        });
    }

    if (can.value.reopen) {
        actions.push({
            key: 'reopen',
            label: 'Reabrir',
            icon: RotateCcw,
            hint: 'Reabrir la conversación cerrada',
            run: () => operate(reopen.url(id.value)),
        });
    }

    return actions;
});

/*
 * «Solicitud completada» is the step that ends an agent's work on a
 * request: while there is no other main step (accept, reopen) it is a
 * button of its own in the header instead of a menu entry.
 */
const completeAsButton = computed(
    () => can.value.close && primary.value.length === 0,
);

/**
 * No comment to write: the dialog only asks for the credit result
 * («Crédito aprobado» with its amount, «Rechazada», or none), already
 * pre-selected from the conversation, and Enter completes it. The request
 * is done and leaves the list. With a farewell configured on the
 * connection, «Sin despedida» skips it.
 */
const completeDialogOpen = ref(false);
const completeFarewell = ref(true);

function openCompleted(farewell = true): void {
    errors.value = {};
    completeFarewell.value = farewell;
    completeDialogOpen.value = true;
}

function confirmCompleted(payload: {
    outcome: string | null;
    outcome_amount: string | null;
}): void {
    operate(
        close.url(id.value),
        { completed: true, farewell: completeFarewell.value, ...payload },
        () => (completeDialogOpen.value = false),
    );
}

const offersSilentComplete = computed(
    () =>
        completeAsButton.value &&
        Boolean(props.conversation.connection.farewell),
);

/*
 * «No es solicitud»: a «gracias», an emoji or an «ok» after a finished
 * request closes without sending anything and outside the reports. On a
 * request waiting in the queue it is a secondary button beside «Aceptar»
 * (no need to take it first); while someone attends it, a menu entry. No
 * confirmation: the toast, and this header while it lasts, offer «Deshacer».
 */
const notRequestAsButton = computed(
    () =>
        Boolean(can.value.mark_not_request) &&
        props.conversation.status === 'pending',
);

function markNotRequest(): void {
    operate(markNotRequestRoute.url(id.value));
}

function undoNotRequest(): void {
    router.delete(undoNotRequestRoute.url(id.value), {
        data: { version: props.conversation.version },
        preserveScroll: true,
        preserveState: true,
        onStart: () => {
            processing.value = true;
            errors.value = {};
        },
        onError: showFirstError,
        onFinish: () => (processing.value = false),
    });
}

/** «Eliminar chat definitivamente»: only with the permission, always last. */
const permanentDeleteOpen = ref(false);

/** Everything else, in a menu. */
const secondary = computed<Action[]>(() => {
    const actions: Action[] = [];

    if (can.value.assign) {
        actions.push({
            key: 'assign',
            label: 'Asignar a un agente',
            icon: UserPlus,
            run: () => openRouteDialog('assign'),
        });
    }

    if (can.value.transfer) {
        actions.push({
            key: 'transfer',
            label: 'Transferir',
            icon: ArrowLeftRight,
            run: () => openRouteDialog('transfer'),
        });
    }

    if (
        can.value.confirm_ready &&
        props.conversation.readiness &&
        !confirmAsPrimary.value
    ) {
        const needsInfo = props.conversation.readiness.value === 'needs_info';

        actions.push({
            key: 'readiness',
            label: needsInfo
                ? 'Confirmar solicitud completa'
                : 'Marcar que necesita información',
            icon: needsInfo ? CircleCheck : CircleHelp,
            success: needsInfo,
            run: () =>
                queueActions.readiness(
                    props.conversation,
                    needsInfo ? 'ready' : 'needs_info',
                ),
        });
    }

    if (can.value.accept && acceptance.value.overridable) {
        actions.push({
            key: 'override',
            label: 'Aceptar con una excepción',
            icon: ShieldAlert,
            run: () => queueActions.override(props.conversation),
        });
    }

    if (can.value.release) {
        actions.push({
            key: 'release',
            label: 'Devolver a Pendientes',
            icon: Undo2,
            run: () => openReasonDialog('release'),
        });
    }

    /*
     * The team attends requests: a request always ends as «Solicitud
     * completada» (with or without the farewell), so the next message is a
     * new request in «Pendientes». There is no plain «Cerrar conversación».
     */
    if (can.value.close && !completeAsButton.value) {
        actions.push({
            key: 'complete',
            label: 'Solicitud completada',
            icon: BadgeCheck,
            run: () => openCompleted(true),
        });

        if (props.conversation.connection.farewell) {
            actions.push({
                key: 'complete-silent',
                label: 'Completada sin despedida',
                icon: BellOff,
                run: () => openCompleted(false),
            });
        }
    }

    if (can.value.mark_not_request && !notRequestAsButton.value) {
        actions.push({
            key: 'not-request',
            label: 'No es solicitud',
            icon: MessageCircleOff,
            hint: 'Se cierra sin enviar nada al cliente y no cuenta en los reportes',
            run: markNotRequest,
        });
    }

    if (can.value.delete_permanently) {
        actions.push({
            key: 'delete-permanently',
            label: 'Eliminar chat definitivamente',
            icon: Trash2,
            destructive: true,
            run: () => {
                permanentDeleteOpen.value = true;
            },
        });
    }

    return actions;
});
</script>

<template>
    <div
        v-if="
            primary.length + secondary.length > 0 ||
            completeAsButton ||
            notRequestAsButton ||
            can.undo_not_request ||
            $slots['menu-extra']
        "
        class="flex shrink-0 items-center gap-1.5"
        role="group"
        aria-label="Operaciones de la conversación"
    >
        <Button
            v-for="action in primary"
            :key="action.key"
            size="sm"
            class="h-9 rounded-full px-3.5 pointer-coarse:h-11"
            :class="
                action.success &&
                'bg-success font-semibold text-background hover:bg-success hover:brightness-110 focus-visible:ring-success/45 active:not-disabled:bg-success'
            "
            :disabled="processing || action.disabled"
            :aria-describedby="action.describedBy"
            :data-conversation-action="action.key"
            :title="action.hint"
            @click="action.run"
        >
            <component :is="action.icon" aria-hidden="true" />
            <!-- A narrow chat (list and details open beside it) keeps the
                 icon and leaves the name to screen readers and the title. -->
            <span class="sm:@max-md/chat-header:sr-only">{{
                action.label
            }}</span>
        </Button>
        <!-- «No es solicitud»: prominent but secondary, beside «Aceptar». -->
        <Button
            v-if="notRequestAsButton"
            variant="outline"
            size="sm"
            class="h-9 rounded-full px-3.5 text-muted-foreground hover:text-foreground pointer-coarse:h-11"
            :disabled="processing"
            aria-label="No es solicitud"
            title="No es solicitud: se cierra sin enviar nada al cliente y no cuenta en los reportes"
            data-conversation-action="not-request"
            @click="markNotRequest"
        >
            <MessageCircleOff aria-hidden="true" />
            <span class="sm:@max-md/chat-header:sr-only">No es solicitud</span>
        </Button>
        <!-- Right after «No es solicitud»: put it back where it was. -->
        <Button
            v-if="can.undo_not_request"
            variant="outline"
            size="sm"
            class="h-9 rounded-full px-3.5 pointer-coarse:h-11"
            :disabled="processing"
            aria-label="Deshacer «No es solicitud»"
            title="Vuelve a donde estaba, con su lugar en la fila"
            data-conversation-action="undo-not-request"
            @click="undoNotRequest"
        >
            <Undo2 aria-hidden="true" />
            <span class="sm:@max-md/chat-header:sr-only">Deshacer</span>
        </Button>
        <!--
            The end of the request: success tone, the icon springs on hover.
            Below small screens only the icon shows (the name stays).
        -->
        <button
            v-if="completeAsButton"
            type="button"
            class="group/complete inline-flex h-9 shrink-0 items-center gap-1.5 rounded-full border border-success/35 bg-success-soft px-2.5 text-sm font-semibold text-success-soft-foreground shadow-(--shadow-control) transition-[background-color,border-color,box-shadow,scale] duration-(--duration-base) ease-(--ease-spring) outline-none hover:border-success/60 hover:shadow-(--shadow-lift) focus-visible:ring-[3px] focus-visible:ring-success/35 active:not-disabled:scale-95 disabled:pointer-events-none disabled:opacity-50 sm:@xl/chat-header:px-3.5 pointer-coarse:h-11 pointer-coarse:min-w-11 pointer-coarse:justify-center"
            :disabled="processing"
            aria-label="Solicitud completada"
            title="Terminar la solicitud y enviar la despedida; si vuelve a escribir, entra como solicitud nueva a «Pendientes»"
            data-conversation-action="complete"
            @click="openCompleted()"
        >
            <BadgeCheck
                class="size-4 shrink-0 transition-transform duration-(--duration-panel) ease-(--ease-spring) group-hover/complete:scale-115 group-hover/complete:-rotate-8"
                aria-hidden="true"
            />
            <span class="hidden sm:@xl/chat-header:inline">
                Solicitud completada
            </span>
        </button>
        <button
            v-if="offersSilentComplete"
            type="button"
            class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-full border bg-background px-2.5 text-sm font-medium text-muted-foreground shadow-(--shadow-control) outline-none hover:bg-accent hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/40 disabled:pointer-events-none disabled:opacity-50 pointer-coarse:h-11 pointer-coarse:min-w-11 pointer-coarse:justify-center"
            :disabled="processing"
            aria-label="Solicitud completada sin despedida"
            title="Terminar la solicitud sin enviar la despedida; si vuelve a escribir, entra como solicitud nueva a «Pendientes»"
            data-conversation-action="complete-silent"
            @click="openCompleted(false)"
        >
            <BellOff class="size-4 shrink-0" aria-hidden="true" />
            <span class="hidden sm:@xl/chat-header:inline"
                >Completada sin despedida</span
            >
        </button>
        <!--
            «menu-extra»: chat controls the header moves into this menu on
            phones (details, colors), after the conversation's operations.
        -->
        <DropdownMenu v-if="secondary.length > 0 || $slots['menu-extra']">
            <DropdownMenuTrigger as-child>
                <Button
                    variant="outline"
                    size="icon"
                    class="rounded-full pointer-coarse:size-11"
                    :disabled="processing"
                    title="Más acciones"
                    aria-label="Más acciones de la conversación"
                >
                    <EllipsisVertical aria-hidden="true" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" class="w-60">
                <!--
                    Three groups: moving the request (assign, transfer,
                    readiness, release), ending it (completed / closed) and
                    erasing it for good.
                -->
                <template
                    v-for="(action, index) in secondary"
                    :key="action.key"
                >
                    <DropdownMenuSeparator
                        v-if="
                            index > 0 &&
                            (action.key === 'complete' ||
                                action.key === 'delete-permanently' ||
                                (action.key === 'not-request' &&
                                    !['complete', 'complete-silent'].includes(
                                        secondary[index - 1]?.key ?? '',
                                    )))
                        "
                    />
                    <DropdownMenuItem
                        :variant="
                            action.destructive ? 'destructive' : 'default'
                        "
                        class="pointer-coarse:min-h-11"
                        :class="
                            (action.key === 'complete' || action.success) &&
                            'text-success focus:bg-success-soft focus:text-success-soft-foreground [&_svg]:text-success!'
                        "
                        :data-conversation-action="action.key"
                        @select="action.run"
                    >
                        <component :is="action.icon" aria-hidden="true" />
                        {{ action.label }}
                    </DropdownMenuItem>
                </template>
                <template v-if="$slots['menu-extra']">
                    <DropdownMenuSeparator v-if="secondary.length > 0" />
                    <slot name="menu-extra" />
                </template>
            </DropdownMenuContent>
        </DropdownMenu>

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
            :destructive="reasonDialog === 'close' && !closeAsCompleted"
            :completed-option="reasonDialog === 'close'"
            :completed-default="closeAsCompleted"
            :processing="processing"
            :error="errors.reason ?? errors.conversation"
            @confirm="confirmReason"
        />

        <CompleteRequestDialog
            v-if="can.close"
            v-model:open="completeDialogOpen"
            mode="complete"
            :conversation-id="conversation.id"
            :farewell="
                completeFarewell && Boolean(conversation.connection.farewell)
            "
            :authorized-amount="
                conversation.request_fields?.find(
                    (field) => field.key === 'monto_autorizado',
                )?.value ?? null
            "
            :processing="processing"
            :error="
                errors.outcome_amount ?? errors.outcome ?? errors.conversation
            "
            @confirm="confirmCompleted"
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
            :queue-exception="queueException"
            @submit="submitRoute"
        />

        <PermanentDeleteDialog
            v-if="can.delete_permanently"
            v-model:open="permanentDeleteOpen"
            title="Eliminar chat definitivamente"
            :description="`Vas a borrar para siempre el chat con ${conversation.contact.name}.`"
            :erased="[
                'Todos los mensajes del chat, enviados y recibidos',
                'Los archivos adjuntos y sus vistas previas, también en el almacenamiento',
                'Notas internas, historial de asignaciones y tiempos de atención',
            ]"
            :kept="[
                'El contacto, sus datos y sus notas privadas',
                'El registro en Auditoría de quién eliminó el chat y cuándo',
            ]"
            :url="`/conversations/${conversation.id}/permanent`"
            confirm-label="Eliminar chat"
        />
    </div>
</template>
