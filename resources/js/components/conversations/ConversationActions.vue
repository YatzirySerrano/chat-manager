<script setup lang="ts">
import { router, useHttp } from '@inertiajs/vue3';
import {
    ArrowLeftRight,
    CircleCheck,
    CircleHelp,
    EllipsisVertical,
    Hand,
    RotateCcw,
    ShieldAlert,
    Trash2,
    Undo2,
    UserPlus,
    XCircle,
} from '@lucide/vue';
import { computed, inject, ref } from 'vue';
import { toast } from 'vue-sonner';
import {
    ACCEPT_BLOCK,
    noAcceptBlock,
} from '@/components/conversations/acceptBlock';
import ConversationReasonDialog from '@/components/conversations/ConversationReasonDialog.vue';
import ConversationRouteDialog from '@/components/conversations/ConversationRouteDialog.vue';
import PermanentDeleteDialog from '@/components/PermanentDeleteDialog.vue';
import {
    noQueueActions,
    QUEUE_ACTIONS,
    useAcceptance,
} from '@/components/conversations/queueRules';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
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
import type { ConversationSummary, TransferOptions } from '@/types';

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
    props.conversation.connection.channel === 'whatsapp' &&
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
        title: 'Cerrar conversación',
        description: `La atención termina y su historial se conserva. ${closeEffect.value}`,
        confirm: 'Cerrar conversación',
        label: 'Motivo de cierre',
    },
}));

function openReasonDialog(operation: Operation): void {
    errors.value = {};
    reasonDialog.value = operation;
}

function confirmReason(reason: string): void {
    const operation = reasonDialog.value;

    if (operation === null) {
        return;
    }

    const urls: Record<Operation, string> = {
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
};

/* Disabled with the reason while customers wait too long for the viewer. */
const acceptBlock = inject(ACCEPT_BLOCK, noAcceptBlock);
const queueActions = inject(QUEUE_ACTIONS, noQueueActions);
const acceptance = useAcceptance(() => props.conversation);

/** The main next step, shown as a button. */
const primary = computed<Action[]>(() => {
    const actions: Action[] = [];

    if (can.value.accept) {
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

    if (can.value.confirm_ready && props.conversation.readiness) {
        const needsInfo = props.conversation.readiness.value === 'needs_info';

        actions.push({
            key: 'readiness',
            label: needsInfo
                ? 'Confirmar solicitud completa'
                : 'Marcar que necesita información',
            icon: needsInfo ? CircleCheck : CircleHelp,
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

    if (can.value.close) {
        actions.push({
            key: 'close',
            label: 'Cerrar conversación',
            icon: XCircle,
            destructive: true,
            run: () => openReasonDialog('close'),
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
        v-if="primary.length + secondary.length > 0"
        class="flex shrink-0 items-center gap-1.5"
        role="group"
        aria-label="Operaciones de la conversación"
    >
        <Button
            v-for="action in primary"
            :key="action.key"
            size="sm"
            class="pointer-coarse:h-10"
            :disabled="processing || action.disabled"
            :aria-describedby="action.describedBy"
            :data-conversation-action="action.key"
            :title="action.hint"
            @click="action.run"
        >
            <component :is="action.icon" aria-hidden="true" />
            {{ action.label }}
        </Button>
        <DropdownMenu v-if="secondary.length > 0">
            <DropdownMenuTrigger as-child>
                <Button
                    variant="outline"
                    size="icon"
                    class="pointer-coarse:size-10"
                    :disabled="processing"
                    title="Más acciones"
                    aria-label="Más acciones de la conversación"
                >
                    <EllipsisVertical aria-hidden="true" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" class="w-56">
                <DropdownMenuItem
                    v-for="action in secondary"
                    :key="action.key"
                    :variant="action.destructive ? 'destructive' : 'default'"
                    class="pointer-coarse:min-h-11"
                    :data-conversation-action="action.key"
                    @select="action.run"
                >
                    <component :is="action.icon" aria-hidden="true" />
                    {{ action.label }}
                </DropdownMenuItem>
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
