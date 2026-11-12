<script setup lang="ts">
import {
    Check,
    LogOut,
    QrCode,
    RefreshCw,
    ServerOff,
    Smartphone,
    TriangleAlert,
    Unplug,
    WifiOff,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import WhatsAppWebRiskNotice from '@/components/connections/WhatsAppWebRiskNotice.vue';
import WhatsAppWebStateBadge from '@/components/connections/WhatsAppWebStateBadge.vue';
import StatusNote from '@/components/StatusNote.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import type { LinkState, WhatsAppWebActionName } from '@/types';

/**
 * Links a phone to a WhatsApp Web (QR) connection: the real QR drawn by the
 * server, the live state of the session and what to do on the phone.
 *
 * The dialog owns no data: the session panel polls the server and passes
 * the state down, so one request feeds both. Every button asks for one of
 * the actions the server offers in `state.actions`; nothing here decides
 * what is possible.
 *
 * The QR always sits on a plain white card with a quiet zone (cameras need
 * the contrast, also in the dark theme), framed by a line that runs out as
 * the code expires.
 */
const props = defineProps<{
    connectionName: string;
    state: LinkState | null;
    /** Seconds the current QR is still valid (counted down by the caller). */
    secondsLeft: number;
    running: WhatsAppWebActionName | null;
    actionError: string | null;
    /** False while the server cannot be reached at all. */
    reachable: boolean;
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    action: [name: WhatsAppWebActionName];
    /** The person closed the dialog before the link finished. */
    cancel: [];
    /** "Cerrar sesión del dispositivo" needs its own confirmation. */
    logout: [];
}>();

type Stage =
    | 'loading'
    | 'unreachable'
    | 'service'
    | 'starting'
    | 'qr'
    | 'expired'
    | 'pairing'
    | 'connected'
    | 'reconnecting'
    | 'disconnected'
    | 'logged_out'
    | 'unconfigured'
    | 'error';

const stage = computed<Stage>(() => {
    const current = props.state;

    if (current === null) {
        return props.reachable ? 'loading' : 'unreachable';
    }

    if (!current.service_available) {
        return 'service';
    }

    if (current.state === 'qr_pending') {
        // Waiting for the scan with no code yet: the next one is on its way.
        if (current.qr === null) {
            return 'starting';
        }

        return props.secondsLeft > 0 ? 'qr' : 'expired';
    }

    return current.state;
});

/** Lifetime of the QR on screen: the longest validity seen for this code. */
const qrLifetime = ref(0);

watch(
    () => [props.state?.qr?.expires_at ?? null, props.secondsLeft] as const,
    ([expiresAt, seconds], previous) => {
        if (expiresAt === null) {
            qrLifetime.value = 0;

            return;
        }

        qrLifetime.value =
            previous && previous[0] === expiresAt
                ? Math.max(qrLifetime.value, seconds)
                : seconds;
    },
    { immediate: true },
);

/** Share of the QR's life that is left (0–100), drawn as the frame line. */
const remaining = computed(() =>
    stage.value === 'qr' && qrLifetime.value > 0
        ? Math.min(100, (props.secondsLeft / qrLifetime.value) * 100)
        : 0,
);

const expiringSoon = computed(
    () => stage.value === 'qr' && props.secondsLeft <= 5,
);

/** Full line once linked; otherwise what is left of the code. */
const ringOffset = computed(() =>
    stage.value === 'connected' ? 0 : 100 - remaining.value,
);

const message = computed(() => {
    switch (stage.value) {
        case 'loading':
            return 'Consultando el estado de la sesión…';
        case 'unreachable':
            return 'No se pudo consultar el estado. Revisa tu conexión a internet; se reintenta solo.';
        case 'service':
            return 'El servicio de WhatsApp Web del servidor no responde, así que no se puede generar un código. Pide a quien administra el servidor que lo revise en Administración → Sistema.';
        case 'starting':
            return 'Preparando un código QR…';
        case 'qr':
            return 'Esperando que escanees el código con el teléfono.';
        case 'expired':
            return 'El código caducó sin escanearse. Genera uno nuevo para continuar.';
        case 'pairing':
            return 'Teléfono detectado. WhatsApp está terminando la vinculación: no cierres la app.';
        case 'connected':
            return 'Sesión operativa. Los mensajes de este número ya llegan a Conversaciones y se pueden contestar desde ahí.';
        case 'reconnecting':
            return 'Se perdió la conexión con WhatsApp y se está reintentando. No hace falta escanear de nuevo.';
        case 'disconnected':
            return 'La sesión está desconectada a propósito y conserva su vinculación. Reconéctala sin escanear otra vez.';
        case 'logged_out':
            return 'WhatsApp cerró esta sesión. Genera un código nuevo y escanéalo para vincular otra vez.';
        case 'unconfigured':
            return 'Este número aún no está vinculado. Genera un código y escanéalo con el teléfono.';
        default:
            return (
                props.state?.last_error ??
                'No se pudo conectar con WhatsApp. Inténtalo de nuevo.'
            );
    }
});

/** What the card shows when there is no QR to scan. */
const placeholder = computed<{ icon: LucideIcon; label: string } | null>(() => {
    switch (stage.value) {
        case 'unreachable':
            return { icon: WifiOff, label: 'Sin respuesta del servidor' };
        case 'service':
            return { icon: ServerOff, label: 'Servicio no disponible' };
        case 'pairing':
            return { icon: Smartphone, label: 'Vinculando…' };
        case 'reconnecting':
            return { icon: RefreshCw, label: 'Reconectando…' };
        case 'disconnected':
            return { icon: Unplug, label: 'Desconectada' };
        case 'logged_out':
            return { icon: LogOut, label: 'Sesión cerrada' };
        case 'unconfigured':
            return { icon: QrCode, label: 'Sin vincular' };
        case 'error':
            return { icon: TriangleAlert, label: 'Con error' };
        default:
            return null;
    }
});

const steps = [
    'Abre WhatsApp en el teléfono del número que vas a conectar.',
    'Toca Más opciones (⋮) en Android o Configuración en iPhone, y entra a «Dispositivos vinculados».',
    'Toca «Vincular un dispositivo» y desbloquea el teléfono si te lo pide.',
    'Apunta la cámara al código de esta ventana.',
];

/** The phone already did its part once WhatsApp reports the scan. */
const stepsDone = computed(
    () => stage.value === 'pairing' || stage.value === 'connected',
);

const busy = computed(() => props.running !== null);

/** The one action that moves the link forward from the current stage. */
const primary = computed<{
    action: WhatsAppWebActionName;
    label: string;
    icon: LucideIcon;
} | null>(() => {
    const actions = props.state?.actions;

    if (!actions) {
        return null;
    }

    if (stage.value === 'expired' && actions.refresh) {
        return { action: 'refresh', label: 'Renovar QR', icon: RefreshCw };
    }

    if (stage.value === 'disconnected' && actions.reconnect) {
        return { action: 'reconnect', label: 'Reconectar', icon: RefreshCw };
    }

    if (
        ['unconfigured', 'logged_out', 'error', 'expired'].includes(
            stage.value,
        ) &&
        actions.link
    ) {
        return { action: 'link', label: 'Generar QR', icon: QrCode };
    }

    return null;
});

const canRenewNow = computed(
    () => stage.value === 'qr' && props.state?.actions.refresh === true,
);

function close(): void {
    if (stage.value !== 'connected') {
        emit('cancel');
    }

    open.value = false;
}

function onOpenChange(next: boolean): void {
    if (!next) {
        close();
    }
}

function countdown(seconds: number): string {
    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
}
</script>

<template>
    <Dialog :open="open" @update:open="onOpenChange">
        <DialogContent
            class="gap-0 overflow-hidden p-0 sm:max-w-3xl"
            data-test="whatsapp-web-link"
        >
            <div class="grid sm:grid-cols-[19rem_minmax(0,1fr)]">
                <!-- Stage: the code, on ink so the white card stands alone. -->
                <div
                    data-ink
                    class="flex flex-col items-center justify-center gap-4 bg-sidebar px-6 py-8 text-sidebar-foreground"
                >
                    <div class="qr-frame relative size-60 max-w-full">
                        <!-- The line around the card runs out with the code. -->
                        <svg
                            class="pointer-events-none absolute -inset-2 size-[calc(100%+1rem)]"
                            viewBox="0 0 100 100"
                            fill="none"
                            aria-hidden="true"
                        >
                            <rect
                                x="1"
                                y="1"
                                width="98"
                                height="98"
                                rx="8"
                                class="stroke-foreground/10"
                                stroke-width="1.5"
                            />
                            <rect
                                x="1"
                                y="1"
                                width="98"
                                height="98"
                                rx="8"
                                pathLength="100"
                                stroke-width="1.5"
                                stroke-linecap="round"
                                stroke-dasharray="100"
                                :stroke-dashoffset="ringOffset"
                                class="qr-ring stroke-primary"
                                :class="expiringSoon && 'animate-pulse'"
                            />
                        </svg>

                        <!--
                          Literal white and black: a QR is only readable with
                          full contrast, in any palette and in the dark theme.
                        -->
                        <div
                            class="relative grid size-full place-items-center overflow-hidden rounded-xl p-3 shadow-elevated"
                            style="background: #fff; color: #111"
                        >
                            <Transition name="qr-swap" mode="out-in">
                                <div
                                    v-if="
                                        (stage === 'qr' ||
                                            stage === 'expired') &&
                                        state?.qr
                                    "
                                    :key="state.qr.expires_at"
                                    class="relative size-full"
                                >
                                    <div
                                        class="size-full transition-[opacity,filter] duration-(--duration-panel) [&_svg]:block [&_svg]:size-full"
                                        :class="
                                            stage === 'expired' &&
                                            'opacity-15 blur-[2px]'
                                        "
                                        role="img"
                                        aria-label="Código QR para vincular el teléfono"
                                        v-html="state.qr.svg"
                                    />
                                    <p
                                        v-if="stage === 'expired'"
                                        class="absolute inset-0 grid place-items-center text-center font-display text-base font-semibold"
                                    >
                                        Código caducado
                                    </p>
                                </div>

                                <div
                                    v-else-if="stage === 'connected'"
                                    key="connected"
                                    class="grid place-items-center gap-3 text-center"
                                >
                                    <span
                                        class="grid size-20 place-items-center rounded-full bg-primary text-primary-foreground"
                                    >
                                        <svg
                                            class="size-10"
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="2.5"
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            aria-hidden="true"
                                        >
                                            <path
                                                class="qr-check"
                                                d="M5 12.5 9.5 17 19 7.5"
                                                pathLength="1"
                                            />
                                        </svg>
                                    </span>
                                    <span
                                        class="font-display text-lg leading-tight font-semibold"
                                    >
                                        Teléfono vinculado
                                    </span>
                                </div>

                                <div
                                    v-else-if="placeholder"
                                    :key="stage"
                                    class="grid place-items-center gap-3 text-center"
                                >
                                    <component
                                        :is="placeholder.icon"
                                        class="size-12 opacity-70"
                                        :class="
                                            stage === 'reconnecting' &&
                                            'animate-spin [animation-duration:1.6s]'
                                        "
                                        stroke-width="1.5"
                                        aria-hidden="true"
                                    />
                                    <span
                                        class="font-display text-base font-semibold"
                                    >
                                        {{ placeholder.label }}
                                    </span>
                                </div>

                                <div
                                    v-else
                                    key="waiting"
                                    class="grid place-items-center gap-3 text-center"
                                >
                                    <Spinner class="size-8" />
                                    <span class="text-sm font-medium">
                                        {{
                                            stage === 'loading'
                                                ? 'Consultando…'
                                                : 'Generando código…'
                                        }}
                                    </span>
                                </div>
                            </Transition>
                        </div>
                    </div>

                    <p
                        class="min-h-5 text-center text-sm text-muted-foreground tabular-nums"
                    >
                        <template v-if="stage === 'qr'">
                            Este código caduca en
                            <strong class="font-semibold text-foreground">{{
                                countdown(secondsLeft)
                            }}</strong>
                        </template>
                        <template v-else-if="stage === 'connected'">
                            <strong
                                class="font-display text-base font-semibold text-foreground"
                                >{{
                                    state?.phone ?? 'Número reconocido'
                                }}</strong
                            >
                        </template>
                        <template v-else-if="stage === 'pairing'">
                            No cierres WhatsApp en el teléfono
                        </template>
                    </p>
                </div>

                <!-- What is happening and what to do on the phone. -->
                <div class="grid content-start gap-5 p-6">
                    <div class="grid gap-1.5 pr-8">
                        <DialogTitle
                            class="font-display text-xl leading-tight font-semibold"
                        >
                            {{
                                stage === 'connected'
                                    ? 'Número conectado'
                                    : 'Vincular teléfono'
                            }}
                        </DialogTitle>
                        <DialogDescription class="truncate">
                            {{ connectionName }} · WhatsApp Web (QR)
                        </DialogDescription>
                    </div>

                    <div
                        class="grid gap-2"
                        role="status"
                        aria-live="polite"
                        aria-atomic="true"
                    >
                        <div v-if="state" class="flex">
                            <WhatsAppWebStateBadge
                                :state="state.state"
                                :label="state.state_label"
                                :tone="state.tone"
                            />
                        </div>
                        <p class="max-w-prose text-sm text-pretty">
                            {{ message }}
                        </p>
                    </div>

                    <dl
                        v-if="stage === 'connected' && state"
                        class="grid grid-cols-2 gap-4 border-t pt-4 text-sm"
                    >
                        <div class="min-w-0">
                            <dt class="text-xs text-muted-foreground">
                                Número reconocido
                            </dt>
                            <dd class="truncate font-medium tabular-nums">
                                {{ state.phone ?? 'Sin dato de WhatsApp' }}
                            </dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="text-xs text-muted-foreground">
                                Nombre en WhatsApp
                            </dt>
                            <dd class="truncate font-medium">
                                {{ state.push_name ?? 'Sin nombre' }}
                            </dd>
                        </div>
                    </dl>

                    <ol
                        v-else
                        class="grid gap-2.5 border-t pt-4 text-sm"
                        aria-label="Cómo escanear el código"
                    >
                        <li
                            v-for="(step, index) in steps"
                            :key="step"
                            class="flex items-start gap-3"
                        >
                            <span
                                class="mt-px grid size-5 shrink-0 place-items-center rounded-full text-xs font-semibold tabular-nums transition-colors duration-(--duration-base)"
                                :class="
                                    stepsDone
                                        ? 'bg-success text-primary-foreground'
                                        : 'bg-muted text-muted-foreground'
                                "
                                aria-hidden="true"
                            >
                                <Check
                                    v-if="stepsDone"
                                    class="size-3"
                                    stroke-width="3"
                                />
                                <template v-else>{{ index + 1 }}</template>
                            </span>
                            <span
                                class="text-pretty"
                                :class="stepsDone && 'text-muted-foreground'"
                                >{{ step }}</span
                            >
                        </li>
                    </ol>

                    <StatusNote v-if="actionError" tone="danger" live="polite">
                        {{ actionError }}
                    </StatusNote>

                    <WhatsAppWebRiskNotice
                        v-if="stage !== 'connected'"
                        compact
                    />

                    <div
                        class="flex flex-wrap items-center gap-2 border-t pt-4"
                    >
                        <Button
                            v-if="stage === 'connected'"
                            type="button"
                            @click="open = false"
                        >
                            Listo
                        </Button>
                        <template v-else>
                            <Button
                                v-if="primary"
                                type="button"
                                data-test="whatsapp-web-primary"
                                :loading="running === primary.action"
                                :disabled="busy"
                                @click="emit('action', primary.action)"
                            >
                                <component :is="primary.icon" />
                                {{ primary.label }}
                            </Button>
                            <Button
                                v-else-if="canRenewNow"
                                type="button"
                                variant="outline"
                                :loading="running === 'refresh'"
                                :disabled="busy"
                                @click="emit('action', 'refresh')"
                            >
                                <RefreshCw />
                                Renovar QR
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                :disabled="busy"
                                @click="close"
                            >
                                Cancelar
                            </Button>
                        </template>

                        <Button
                            v-if="
                                stage === 'connected' && state?.actions.logout
                            "
                            type="button"
                            variant="ghost"
                            class="text-destructive hover:bg-destructive/10 hover:text-destructive sm:ml-auto"
                            :disabled="busy"
                            @click="emit('logout')"
                        >
                            <LogOut />
                            Desvincular
                        </Button>
                    </div>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>

<style scoped>
.qr-ring {
    transition:
        stroke-dashoffset 600ms linear,
        stroke var(--duration-base) var(--ease-standard);
}

.qr-check {
    stroke-dasharray: 1;
    stroke-dashoffset: 0;
    animation: check-draw 420ms var(--ease-out-quint) 160ms backwards;
}

.qr-swap-enter-active {
    transition:
        opacity var(--duration-panel) var(--ease-out-quint),
        scale var(--duration-panel) var(--ease-out-quint);
}

.qr-swap-leave-active {
    transition: opacity var(--duration-fast) var(--ease-standard);
}

.qr-swap-enter-from {
    opacity: 0;
    scale: 0.96;
}

.qr-swap-leave-to {
    opacity: 0;
}
</style>
