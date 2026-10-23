<script setup lang="ts">
import { router, useHttp, usePage } from '@inertiajs/vue3';
import {
    ArrowRightLeft,
    EllipsisVertical,
    Hand,
    Pause,
    Play,
    RotateCcw,
    Undo2,
    UserPlus,
    XCircle,
} from '@lucide/vue';
import { computed, onScopeDispose, ref, watch } from 'vue';
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
import { subscribe } from '@/lib/realtime';
import type { RealtimeConfig } from '@/lib/realtime';
import {
    accept,
    assign,
    close,
    destinations,
    hold,
    release,
    reopen,
    resume,
    transfer,
} from '@/routes/conversations';
import type {
    ConversationSummary,
    TransferOptions,
    ViewerAvailability,
} from '@/types';

/**
 * Operations of the engine on the open conversation (accept, assign,
 * transfer, hold, resume, release, close, reopen), shown according to the
 * policy's answer for the viewer. Each one sends the conversation version,
 * so a decision taken on stale data is rejected by the server.
 */
const props = defineProps<{
    conversation: ConversationSummary;
    availability: ViewerAvailability;
    transferReasonRequired: boolean;
}>();

const can = computed(() => props.conversation.can);
const id = computed(() => props.conversation.id);
const processing = ref(false);
const errors = ref<Record<string, string | undefined>>({});
const isUnavailable = computed(() => props.availability.value !== 'available');

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
            'La atención termina y su historial se conserva. No se envía ningún mensaje al cliente ni cambia la ventana de WhatsApp.',
        confirm: 'Cerrar conversación',
        label: 'Motivo de cierre',
    },
};

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

/*
 * While the dialog is open, an agent switching availability (signal on the
 * viewer's inbox channels) refreshes the destinations, so the availability
 * and load shown next to each person stay current.
 */
const page = usePage();
const inboxChannels = computed<string[]>(() => {
    const config = page.props.realtime as RealtimeConfig | undefined;

    return config?.enabled ? config.inbox_channels : [];
});
let stopAvailability: (() => void)[] = [];
let availabilityTimer: ReturnType<typeof setTimeout> | null = null;

function stopAvailabilitySignals(): void {
    stopAvailability.forEach((stop) => stop());
    stopAvailability = [];

    if (availabilityTimer) {
        clearTimeout(availabilityTimer);
        availabilityTimer = null;
    }
}

watch(
    () => [routeDialog.value !== null, inboxChannels.value.join(',')] as const,
    ([isOpen]) => {
        stopAvailabilitySignals();

        if (!isOpen) {
            return;
        }

        stopAvailability = inboxChannels.value.map((channel) =>
            subscribe(channel, 'availability.changed', () => {
                if (availabilityTimer) {
                    clearTimeout(availabilityTimer);
                }

                availabilityTimer = setTimeout(() => {
                    availabilityTimer = null;
                    void loadRouteOptions();
                }, 300);
            }),
        );
    },
);

onScopeDispose(stopAvailabilitySignals);

function submitRoute(payload: {
    department_id: number | null;
    user_id: number | null;
    reason: string;
    confirm_unavailable: boolean;
    confirm_over_capacity: boolean;
}): void {
    if (routeDialog.value === 'assign') {
        operate(
            assign.url(id.value),
            {
                user_id: payload.user_id,
                confirm_unavailable: payload.confirm_unavailable,
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
            disabled: isUnavailable.value,
            hint: isUnavailable.value
                ? 'Cambia tu disponibilidad a Disponible para aceptar conversaciones.'
                : 'Hacerte responsable de esta conversación',
            run: () => operate(accept.url(id.value)),
        });
    }

    if (can.value.resume) {
        actions.push({
            key: 'resume',
            label: 'Retomar',
            icon: Play,
            hint: 'Volver a atender la conversación en espera',
            run: () => operate(resume.url(id.value)),
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
            :disabled="processing || action.disabled"
            :title="action.hint"
            @click="action.run"
        >
            <component :is="action.icon" aria-hidden="true" />
            <span class="hidden sm:inline">{{ action.label }}</span>
            <span class="sr-only sm:hidden">{{ action.label }}</span>
        </Button>
        <DropdownMenu v-if="secondary.length > 0">
            <DropdownMenuTrigger as-child>
                <Button
                    variant="outline"
                    size="icon"
                    class="size-8"
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
