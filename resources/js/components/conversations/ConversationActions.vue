<script setup lang="ts">
import { router, useHttp } from '@inertiajs/vue3';
import {
    ArrowRightLeft,
    EllipsisVertical,
    Hand,
    RotateCcw,
    Undo2,
    UserPlus,
    XCircle,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConversationReasonDialog from '@/components/conversations/ConversationReasonDialog.vue';
import ConversationRouteDialog from '@/components/conversations/ConversationRouteDialog.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
 * so a decision taken on stale data is rejected by the server.
 */
const props = defineProps<{
    conversation: ConversationSummary;
    transferReasonRequired: boolean;
}>();

const can = computed(() => props.conversation.can);
const id = computed(() => props.conversation.id);
const processing = ref(false);
const errors = ref<Record<string, string | undefined>>({});

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

/* Closing never changes WhatsApp's 24 h window; other channels have none. */
const closeEffect = computed(() =>
    props.conversation.connection.channel === 'whatsapp'
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
        title: 'Devolver a la cola',
        description:
            'Dejarás de ser responsable y la conversación esperará en la cola de su departamento.',
        confirm: 'Devolver a la cola',
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
            destinations.url(id.value),
        );

        if (request === latestDestinations) {
            transferOptions.value = result;
        }
    } catch {
        if (request === latestDestinations && routeDialog.value !== null) {
            toast.error(
                'No se pudieron cargar los destinos. Cierra el diálogo e inténtalo de nuevo.',
            );
        }
    } finally {
        if (request === latestDestinations) {
            loadingOptions.value = false;
        }
    }
}

function openRouteDialog(mode: 'assign' | 'transfer'): void {
    errors.value = {};
    transferOptions.value = undefined;
    routeDialog.value = mode;
    void loadRouteOptions();
}

function submitRoute(payload: {
    department_id: number | null;
    user_id: number | null;
    reason: string;
    confirm_over_capacity: boolean;
}): void {
    if (routeDialog.value === 'assign') {
        operate(
            assign.url(id.value),
            {
                user_id: payload.user_id,
                confirm_over_capacity: payload.confirm_over_capacity,
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
};

/** The main next step, shown as a button. */
const primary = computed<Action[]>(() => {
    const actions: Action[] = [];

    if (can.value.accept) {
        actions.push({
            key: 'accept',
            label: 'Aceptar',
            icon: Hand,
            hint: 'Hacerte responsable de esta conversación',
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
            icon: ArrowRightLeft,
            run: () => openRouteDialog('transfer'),
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
            label: 'Cerrar conversación',
            icon: XCircle,
            destructive: true,
            run: () => openReasonDialog('close'),
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
            @submit="submitRoute"
        />
    </div>
</template>
