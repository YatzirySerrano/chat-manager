<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    LogOut,
    PauseCircle,
    QrCode,
    RefreshCw,
    ServerOff,
    Trash2,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import WhatsAppWebLinkDialog from '@/components/connections/WhatsAppWebLinkDialog.vue';
import WhatsAppWebStateBadge from '@/components/connections/WhatsAppWebStateBadge.vue';
import StatusNote from '@/components/StatusNote.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useWhatsAppWebSession } from '@/composables/useWhatsAppWebSession';
import { formatDateTime, formatRelative } from '@/lib/format';
import type {
    WhatsAppWebActionName,
    WhatsAppWebSessionSummary,
    WhatsAppWebState,
} from '@/types';

/**
 * Session of a WhatsApp Web (QR) connection, inside the "Estado" section of
 * its page: the real state, the linked number, the last activity, and the
 * actions the server offers for it.
 *
 * Three actions end a session in different ways and are kept apart, each
 * with its consequence in words and its own confirmation: pausing it
 * (reversible, no QR), signing the device out (a new QR is needed) and
 * deleting the connection (handled by the page; history is kept).
 */
const props = defineProps<{
    connectionId: number;
    connectionName: string;
    /** State sent with the connection, shown until the first live answer. */
    summary?: WhatsAppWebSessionSummary | null;
    /** A deleted connection has no session to poll or act on. */
    deleted?: boolean;
    canDelete?: boolean;
    /** Open the linking dialog as soon as the state is known. */
    autoLink?: boolean;
}>();

const emit = defineEmits<{
    /** "Eliminar conexión": the page owns that dialog. */
    delete: [];
}>();

const session = useWhatsAppWebSession(() => props.connectionId, {
    enabled: () => !props.deleted,
});
const { state, reachable, running, actionError, qrSecondsLeft } = session;

const linkOpen = ref(false);
/* The kind stays while the dialog animates out, so its text never flips. */
const confirmKind = ref<'disconnect' | 'logout'>('disconnect');
const confirmOpen = ref(false);

function askToEnd(kind: 'disconnect' | 'logout'): void {
    confirmKind.value = kind;
    confirmOpen.value = true;
}

const badge = computed(() => state.value ?? props.summary ?? null);

const headline = computed(() => {
    const current = state.value;

    if (!current) {
        return props.summary?.state_label ?? '';
    }

    return current.state === 'connected' && current.phone
        ? current.phone
        : current.state_label;
});

const explanations: Record<WhatsAppWebState, string> = {
    unconfigured:
        'Este número aún no está vinculado. Vincula el teléfono escaneando un código QR desde WhatsApp.',
    starting: 'El servicio está abriendo la sesión con WhatsApp.',
    qr_pending:
        'Hay un código QR vigente esperando que lo escanees desde el teléfono.',
    pairing:
        'El teléfono escaneó el código y WhatsApp está terminando la vinculación.',
    connected:
        'La sesión está abierta: los mensajes de este número llegan a Conversaciones y se pueden contestar desde ahí.',
    reconnecting:
        'Se perdió la conexión con WhatsApp y se está reintentando sola. Mientras tanto no se pueden enviar mensajes.',
    disconnected:
        'La sesión está desconectada a propósito y conserva su vinculación. Se puede reconectar sin escanear de nuevo.',
    logged_out:
        'WhatsApp cerró esta sesión (desde el teléfono o desde aquí). Hay que escanear un código nuevo para volver a usar el número.',
    error: 'La sesión no pudo recuperarse sola. Revisa el último error y vuelve a intentar.',
};

const details = computed(() => {
    const current = state.value;

    if (!current) {
        return [];
    }

    return [
        { label: 'Número vinculado', value: current.phone, at: null },
        { label: 'Nombre en WhatsApp', value: current.push_name, at: null },
        {
            label: 'Conectada desde',
            value: current.connected_since
                ? formatRelative(current.connected_since)
                : null,
            at: current.connected_since,
        },
        {
            label: 'Última actividad',
            value: current.last_event_at
                ? formatRelative(current.last_event_at)
                : null,
            at: current.last_event_at,
        },
        {
            label: 'Último mensaje recibido',
            value: current.last_inbound_at
                ? formatRelative(current.last_inbound_at)
                : null,
            at: current.last_inbound_at,
        },
        {
            label: 'Último envío',
            value: current.last_outbound_at
                ? formatRelative(current.last_outbound_at)
                : null,
            at: current.last_outbound_at,
        },
        {
            label: 'Reconexiones',
            value: String(current.reconnects),
            at: null,
        },
    ];
});

/** The action that moves a session without a live socket forward. */
const primary = computed<{
    key: 'link' | 'reconnect' | 'resume';
    label: string;
    icon: LucideIcon;
} | null>(() => {
    const current = state.value;

    if (!current || props.deleted) {
        return null;
    }

    if (
        current.state === 'starting' ||
        current.state === 'qr_pending' ||
        current.state === 'pairing'
    ) {
        return { key: 'resume', label: 'Ver código QR', icon: QrCode };
    }

    if (current.actions.reconnect) {
        return { key: 'reconnect', label: 'Reconectar', icon: RefreshCw };
    }

    if (current.actions.link) {
        return { key: 'link', label: 'Vincular teléfono', icon: QrCode };
    }

    return null;
});

async function runAction(
    action: WhatsAppWebActionName,
    success?: string,
): Promise<boolean> {
    const done = await session.run(action);

    if (done && success) {
        toast.success(success);
    } else if (!done && actionError.value && !linkOpen.value) {
        toast.error(actionError.value);
    }

    return done;
}

function startLinking(): void {
    linkOpen.value = true;

    if (state.value?.actions.link) {
        void runAction('link');
    }
}

function onPrimary(): void {
    if (primary.value?.key === 'reconnect') {
        void runAction('reconnect', 'Reconectando la sesión…');

        return;
    }

    if (primary.value?.key === 'resume') {
        linkOpen.value = true;

        return;
    }

    startLinking();
}

/** Closing the dialog mid-link stops the pending QR when the server allows it. */
function onCancelLink(): void {
    const current = state.value;

    if (
        current &&
        (current.state === 'starting' || current.state === 'qr_pending') &&
        current.actions.disconnect
    ) {
        void runAction('disconnect');
    }
}

async function confirmEnding(): Promise<void> {
    const action = confirmKind.value;
    const done = await runAction(
        action,
        action === 'disconnect'
            ? 'Sesión desconectada. Conserva su vinculación.'
            : 'Sesión cerrada en el dispositivo.',
    );

    if (done) {
        linkOpen.value = false;
    }

    confirmOpen.value = false;
}

const confirmCopy = computed(() =>
    confirmKind.value === 'logout'
        ? {
              title: `¿Cerrar la sesión de ${props.connectionName} en el dispositivo?`,
              description:
                  'ChatManager dejará de aparecer en «Dispositivos vinculados» del teléfono y este número no enviará ni recibirá mensajes aquí. Para volver a usarlo tendrás que escanear un código QR nuevo. Las conversaciones y su historial se conservan.',
              label: 'Cerrar sesión del dispositivo',
          }
        : {
              title: `¿Desconectar ${props.connectionName} temporalmente?`,
              description:
                  'La sesión se detiene y conserva su vinculación: podrás reconectarla sin escanear de nuevo. Mientras esté desconectada no se envían ni se reciben mensajes de este número en ChatManager; el teléfono sigue funcionando con normalidad.',
              label: 'Desconectar temporalmente',
          },
);

/* The header badges and the checklist come from the page props. */
watch(
    () => state.value?.state,
    (next, previous) => {
        if (previous !== undefined && next !== previous) {
            router.reload({ only: ['connection', 'diagnostics'] });
        }
    },
);

/* A person arriving from "Vincular teléfono" in the list goes straight to the code. */
let autoLinked = false;

watch(
    state,
    (current) => {
        if (!props.autoLink || autoLinked || current === null) {
            return;
        }

        autoLinked = true;

        if (current.state !== 'connected' && primary.value !== null) {
            onPrimary();
        }
    },
    { immediate: true },
);
</script>

<template>
    <div class="grid gap-5" data-test="whatsapp-web-session">
        <StatusNote
            v-if="deleted"
            tone="info"
            title="La conexión está eliminada"
        >
            Su sesión de WhatsApp Web se cerró al eliminarla. Restaura la
            conexión para vincular un teléfono de nuevo.
        </StatusNote>

        <StatusNote
            v-else-if="!reachable"
            tone="warning"
            title="No se pudo consultar el estado"
            live="polite"
        >
            La plataforma no respondió. Se vuelve a intentar sola en unos
            segundos.
        </StatusNote>

        <StatusNote
            v-else-if="state && !state.service_available"
            tone="danger"
            :icon="ServerOff"
            title="El servicio de WhatsApp Web no responde"
            live="polite"
        >
            Sin ese servicio no se puede vincular, enviar ni recibir por este
            número. Pide a quien administra el servidor que lo revise en
            Administración → Sistema. El estado de abajo es el último conocido.
        </StatusNote>

        <div
            v-if="!deleted"
            class="flex flex-wrap items-start justify-between gap-x-6 gap-y-4"
        >
            <div class="grid min-w-0 gap-2" role="status" aria-live="polite">
                <div class="flex flex-wrap items-center gap-2">
                    <WhatsAppWebStateBadge
                        v-if="badge"
                        :state="badge.state"
                        :label="badge.state_label"
                        :tone="badge.tone"
                    />
                    <Skeleton v-else class="h-5 w-28 rounded-full" />
                    <span
                        v-if="state"
                        class="text-xs font-medium"
                        :class="
                            state.can_send
                                ? 'text-success'
                                : 'text-muted-foreground'
                        "
                    >
                        {{
                            state.can_send
                                ? 'Lista para enviar'
                                : 'No puede enviar ahora'
                        }}
                    </span>
                </div>
                <p
                    v-if="headline"
                    class="truncate font-display text-2xl leading-tight font-semibold tabular-nums"
                >
                    {{ headline }}
                </p>
                <Skeleton v-else class="h-8 w-56" />
                <p
                    v-if="state"
                    class="max-w-prose text-sm text-pretty text-muted-foreground"
                >
                    {{ explanations[state.state] }}
                </p>
                <Skeleton v-else class="h-4 w-80 max-w-full" />
            </div>

            <Button
                v-if="primary"
                type="button"
                data-test="whatsapp-web-link-open"
                :variant="primary.key === 'resume' ? 'outline' : 'default'"
                :loading="
                    primary.key === 'reconnect' && running === 'reconnect'
                "
                :disabled="running !== null"
                @click="onPrimary"
            >
                <component :is="primary.icon" />
                {{ primary.label }}
            </Button>
        </div>

        <dl
            v-if="state && !deleted"
            class="auto-grid border-t pt-5 [--col-min:11rem] [--grid-gap:1rem]"
        >
            <div v-for="detail in details" :key="detail.label" class="min-w-0">
                <dt class="text-xs text-muted-foreground">
                    {{ detail.label }}
                </dt>
                <dd
                    class="truncate text-sm font-medium tabular-nums"
                    :class="!detail.value && 'text-muted-foreground'"
                    :title="detail.at ? formatDateTime(detail.at) : undefined"
                >
                    {{ detail.value ?? 'Sin dato' }}
                </dd>
            </div>
        </dl>

        <StatusNote
            v-if="state?.last_error && !deleted"
            tone="danger"
            title="Último error"
        >
            {{ state.last_error }}
        </StatusNote>

        <ul
            v-if="
                state &&
                !deleted &&
                (state.actions.disconnect || state.actions.logout || canDelete)
            "
            class="grid gap-px overflow-hidden rounded-xl border bg-border"
            aria-label="Formas de terminar la sesión"
        >
            <li
                v-if="state.actions.disconnect"
                class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3 bg-card px-4 py-3.5"
            >
                <div class="min-w-0 grow basis-80">
                    <p class="text-sm font-medium">Desconectar temporalmente</p>
                    <p class="max-w-prose text-xs text-muted-foreground">
                        Detiene la sesión y conserva la vinculación. Se
                        reconecta después sin escanear.
                    </p>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="running !== null"
                    @click="askToEnd('disconnect')"
                >
                    <PauseCircle />
                    Desconectar
                </Button>
            </li>
            <li
                v-if="state.actions.logout"
                class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3 bg-card px-4 py-3.5"
            >
                <div class="min-w-0 grow basis-80">
                    <p class="text-sm font-medium">
                        Cerrar sesión del dispositivo
                    </p>
                    <p class="max-w-prose text-xs text-muted-foreground">
                        Quita a ChatManager de los dispositivos vinculados del
                        teléfono. Habrá que escanear un código nuevo.
                    </p>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="running !== null"
                    @click="askToEnd('logout')"
                >
                    <LogOut />
                    Cerrar sesión
                </Button>
            </li>
            <li
                v-if="canDelete"
                class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3 bg-card px-4 py-3.5"
            >
                <div class="min-w-0 grow basis-80">
                    <p class="text-sm font-medium">Eliminar conexión</p>
                    <p class="max-w-prose text-xs text-muted-foreground">
                        Quita la conexión de la plataforma y cierra su sesión.
                        No borra conversaciones, contactos ni historial, y se
                        puede restaurar.
                    </p>
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                    @click="emit('delete')"
                >
                    <Trash2 />
                    Eliminar
                </Button>
            </li>
        </ul>

        <WhatsAppWebLinkDialog
            v-model:open="linkOpen"
            :connection-name="connectionName"
            :state="state"
            :seconds-left="qrSecondsLeft"
            :running="running"
            :action-error="actionError"
            :reachable="reachable"
            @action="(name) => runAction(name)"
            @cancel="onCancelLink"
            @logout="askToEnd('logout')"
        />

        <ConfirmDialog
            v-model:open="confirmOpen"
            :title="confirmCopy.title"
            :description="confirmCopy.description"
            :confirm-label="confirmCopy.label"
            :destructive="confirmKind === 'logout'"
            :processing="running !== null"
            @confirm="confirmEnding"
        />
    </div>
</template>
