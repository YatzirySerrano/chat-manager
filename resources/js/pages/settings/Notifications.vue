<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    BellRing,
    Info,
    ListChecks,
    MonitorSmartphone,
    ShieldCheck,
    Trash2,
    Volume2,
    VolumeOff,
} from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import StatusPill from '@/components/admin/StatusPill.vue';
import FieldHelp from '@/components/FieldHelp.vue';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import {
    browserNotificationPermission,
    notificationSoundState,
    playNotificationSound,
    requestBrowserNotificationPermission,
} from '@/lib/notifications/alerts';
import type { BrowserPermission } from '@/lib/notifications/alerts';
import {
    currentPushSubscription,
    endpointHash,
    pushSupport,
    subscribeToPush,
    unsubscribeFromPush,
} from '@/lib/notifications/push';
import type {
    PushDevice,
    PushSettings,
    PushSupport,
} from '@/lib/notifications/push';
import { formatDate, formatUserAgent } from '@/lib/format';
import { pushTest } from '@/routes/notifications';
import { edit, update } from '@/routes/notifications/preferences';
import {
    destroy as destroyPushSubscription,
    store as storePushSubscription,
} from '@/routes/notifications/push-subscriptions';
import type { NotificationKindOption, NotificationPreference } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Notificaciones', href: edit() }],
    },
});

const props = defineProps<{
    kinds: NotificationKindOption[];
    push: PushSettings;
}>();

type PreferenceRow = NotificationPreference & { kind: string };

function rows(): PreferenceRow[] {
    return props.kinds.map((kind) => ({
        kind: kind.value,
        ...kind.preferences,
    }));
}

const form = useForm<{ preferences: PreferenceRow[] }>({
    preferences: rows(),
});

const permission = ref<BrowserPermission>('default');

const support = ref<PushSupport>('unsupported');
const currentHash = ref<string | null>(null);
const pushBusy = ref(false);
const pushError = ref<string | null>(null);

onMounted(async () => {
    permission.value = browserNotificationPermission();
    support.value = pushSupport();

    const subscription = await currentPushSubscription();

    currentHash.value = subscription
        ? await endpointHash(subscription.endpoint)
        : null;
});

const currentDevice = computed<PushDevice | null>(
    () =>
        props.push.subscriptions.find(
            (device) => device.endpoint_hash === currentHash.value,
        ) ?? null,
);

const pushText = computed(() => {
    if (props.push.public_key === null) {
        return 'Las notificaciones del navegador aún no están activadas en la plataforma. Pide a un administrador que las active.';
    }

    if (support.value === 'insecure') {
        return 'Las notificaciones del navegador solo funcionan si abres la plataforma con una dirección segura (https://).';
    }

    if (support.value === 'unsupported') {
        return 'Este navegador no admite notificaciones del navegador. Prueba con Chrome, Edge, Firefox o Safari actualizados.';
    }

    if (permission.value === 'denied') {
        return 'Bloqueaste las notificaciones de este sitio. Para recibirlas, permite las notificaciones de este sitio en la configuración del navegador.';
    }

    return currentDevice.value
        ? 'Este navegador está activado: recibes un aviso de Windows por cada mensaje, aunque la plataforma esté minimizada o cerrada (con el navegador abierto). Cada aviso dice qué pasó y en qué conversación.'
        : 'Se activan solas: el navegador pide permiso la primera vez que haces clic en la plataforma y se vuelve a suscribir en cada inicio de sesión. Si no recibes avisos de Windows, actívalas aquí.';
});

const canOfferPush = computed(
    () =>
        props.push.public_key !== null &&
        support.value === 'supported' &&
        permission.value !== 'denied',
);

const testingPush = ref(false);

function sendTestPush(): void {
    router.post(
        pushTest.url(),
        {},
        {
            preserveScroll: true,
            onStart: () => (testingPush.value = true),
            onFinish: () => (testingPush.value = false),
        },
    );
}

async function enablePush(): Promise<void> {
    const publicKey = props.push.public_key;

    if (publicKey === null || pushBusy.value) {
        return;
    }

    pushBusy.value = true;
    pushError.value = null;

    try {
        const endpoint = await subscribeToPush(publicKey);

        permission.value = browserNotificationPermission();

        if (endpoint === null) {
            pushBusy.value = false;

            return;
        }

        const hash = await endpointHash(endpoint);

        router.post(
            storePushSubscription.url(),
            { endpoint },
            {
                preserveScroll: true,
                onSuccess: () => {
                    currentHash.value = hash;
                },
                onError: (errors) => {
                    pushError.value =
                        errors.endpoint ??
                        'No se pudieron activar las notificaciones del navegador.';
                },
                onFinish: () => {
                    pushBusy.value = false;
                },
            },
        );
    } catch {
        pushError.value =
            'El navegador no pudo activar las notificaciones. Inténtalo de nuevo.';
        pushBusy.value = false;
    }
}

async function removeDevice(device: PushDevice): Promise<void> {
    if (pushBusy.value) {
        return;
    }

    pushBusy.value = true;
    pushError.value = null;

    if (device.endpoint_hash === currentHash.value) {
        // A browser that fails to unsubscribe still lets the device be
        // removed from the account (it stops receiving with no record).
        try {
            await unsubscribeFromPush();
        } catch {
            // Ignored: the server record is what sends the notifications.
        }

        currentHash.value = null;
    }

    router.delete(destroyPushSubscription.url(device.id), {
        preserveScroll: true,
        onFinish: () => {
            pushBusy.value = false;
        },
    });
}

const browserAllowed = computed(() => permission.value === 'granted');

/* The state of this browser in one pill (header of the permission card). */
const permissionPill = computed<{
    label: string;
    tone: 'success' | 'warning' | 'danger' | 'muted';
}>(() => {
    switch (permission.value) {
        case 'granted':
            return { label: 'Permitidas', tone: 'success' };
        case 'denied':
            return { label: 'Bloqueadas', tone: 'danger' };
        case 'unsupported':
            return { label: 'No disponibles', tone: 'muted' };
        default:
            return { label: 'Sin permiso', tone: 'warning' };
    }
});

async function askPermission(): Promise<void> {
    permission.value = await requestBrowserNotificationPermission();
}

const permissionText = computed(() => {
    switch (permission.value) {
        case 'granted':
            return 'Este navegador puede mostrarte notificaciones del sistema cuando la pestaña no está a la vista.';
        case 'denied':
            return 'Bloqueaste las notificaciones de este sitio. Para activarlas cámbialo en la configuración del navegador.';
        case 'unsupported':
            return 'Este navegador no admite notificaciones del sistema.';
        default:
            return 'Para recibir avisos del sistema, primero permite las notificaciones en este navegador.';
    }
});

function submit(): void {
    form.put(update.url(), {
        preserveScroll: true,
        onSuccess: () => {
            form.defaults({ preferences: rows() });
            form.reset();
        },
    });
}
</script>

<template>
    <Head title="Notificaciones" />

    <SettingsSection
        :icon="BellRing"
        title="Este navegador"
        description="Permiso del navegador, sonido y dispositivos."
        bare
    >
        <div class="grid gap-4 xl:grid-cols-2">
            <div class="admin-card flex min-w-0 flex-col gap-4 p-4 sm:p-5">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="admin-icon-tile size-9">
                            <Volume2 class="size-4" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold">
                                Permiso y sonido
                            </p>
                            <p
                                class="text-sm text-pretty text-muted-foreground"
                            >
                                {{ permissionText }}
                            </p>
                        </div>
                    </div>
                    <StatusPill
                        :label="permissionPill.label"
                        :tone="permissionPill.tone"
                        class="shrink-0"
                    />
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="permission === 'default'"
                        type="button"
                        size="sm"
                        @click="askPermission"
                    >
                        Permitir en este navegador
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        @click="playNotificationSound(true)"
                    >
                        <Volume2 />
                        Probar sonido
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        @click="playNotificationSound(false, 'team-chat')"
                    >
                        <Volume2 />
                        Probar sonido del chat interno
                    </Button>
                </div>
                <p
                    v-if="notificationSoundState === 'locked'"
                    class="flex items-center gap-2 rounded-lg bg-warning-soft/60 px-3 py-2 text-xs text-warning-soft-foreground"
                    role="status"
                    data-test="sound-locked-notice"
                >
                    <VolumeOff class="size-3.5 shrink-0" aria-hidden="true" />
                    <span class="min-w-0 flex-1"
                        >Haz clic en la página para activar el sonido</span
                    >
                    <FieldHelp title="Sonido bloqueado">
                        <p class="text-muted-foreground">
                            El navegador bloquea el sonido hasta que interactúes
                            con la página (un clic o una tecla) después de
                            abrirla o recargarla. Con la pestaña en segundo
                            plano puede no sonar; activa las notificaciones del
                            navegador para no perder avisos.
                        </p>
                    </FieldHelp>
                </p>
                <p
                    v-else-if="notificationSoundState === 'unsupported'"
                    class="flex items-center gap-2 text-xs text-muted-foreground"
                    role="status"
                >
                    <VolumeOff class="size-3.5 shrink-0" aria-hidden="true" />
                    Este navegador no reproduce el sonido de aviso
                </p>
                <p
                    class="mt-auto flex items-center gap-2 text-xs text-muted-foreground"
                >
                    <Info class="size-3.5 shrink-0" aria-hidden="true" />
                    <span class="min-w-0 flex-1">Suena una vez por aviso</span>
                    <FieldHelp title="Cuándo suena">
                        <p class="text-muted-foreground">
                            Con varias pestañas abiertas el aviso suena una sola
                            vez. Los mensajes del chat interno que tienes
                            abierto y a la vista no suenan. El sonido depende
                            también del volumen y de los ajustes de tu
                            dispositivo.
                        </p>
                    </FieldHelp>
                </p>
            </div>

            <div class="admin-card flex min-w-0 flex-col gap-4 p-4 sm:p-5">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="admin-icon-tile size-9">
                            <MonitorSmartphone
                                class="size-4"
                                aria-hidden="true"
                            />
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold">
                                Notificaciones del navegador
                            </p>
                            <p
                                class="text-sm text-pretty text-muted-foreground"
                            >
                                {{ pushText }}
                            </p>
                            <p
                                v-if="pushError"
                                class="mt-1 text-sm text-destructive"
                                role="alert"
                            >
                                {{ pushError }}
                            </p>
                        </div>
                    </div>
                    <StatusPill
                        v-if="currentDevice"
                        label="Activadas"
                        tone="success"
                        live
                        class="shrink-0"
                    />
                </div>
                <div v-if="canOfferPush" class="flex flex-wrap gap-2">
                    <Button
                        v-if="currentDevice"
                        type="button"
                        size="sm"
                        variant="outline"
                        :disabled="pushBusy"
                        :loading="pushBusy"
                        @click="removeDevice(currentDevice)"
                    >
                        Desactivar en este navegador
                    </Button>
                    <Button
                        v-else
                        type="button"
                        size="sm"
                        :disabled="pushBusy"
                        :loading="pushBusy"
                        @click="enablePush"
                    >
                        Activar en este navegador
                    </Button>
                    <!-- Proves the whole chain: server keys, queue worker, push service and this device. -->
                    <Button
                        v-if="push.subscriptions.length > 0"
                        type="button"
                        size="sm"
                        variant="outline"
                        :disabled="pushBusy || testingPush"
                        :loading="testingPush"
                        @click="sendTestPush"
                    >
                        Enviar notificación de prueba
                    </Button>
                </div>
                <ul
                    v-if="push.subscriptions.length > 0"
                    class="grid gap-1.5"
                    aria-label="Navegadores con notificaciones activadas"
                >
                    <li
                        v-for="device in push.subscriptions"
                        :key="device.id"
                        class="flex items-center justify-between gap-3 rounded-xl border px-3 py-2 text-sm transition-colors"
                        :class="
                            device.id === currentDevice?.id
                                ? 'border-primary/30 bg-primary-soft/40'
                                : 'border-border/70 bg-surface-sunken/50 hover:bg-surface-sunken'
                        "
                    >
                        <div class="flex min-w-0 items-center gap-2.5">
                            <MonitorSmartphone
                                class="size-4 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <div class="min-w-0">
                                <p class="truncate">
                                    {{ formatUserAgent(device.user_agent) }}
                                    <span
                                        v-if="device.id === currentDevice?.id"
                                        class="text-xs text-muted-foreground"
                                    >
                                        (este navegador)
                                    </span>
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    Activado el
                                    {{ formatDate(device.created_at) }}
                                </p>
                            </div>
                        </div>
                        <Button
                            type="button"
                            size="icon-sm"
                            variant="ghost"
                            class="text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                            :disabled="pushBusy"
                            :aria-label="`Quitar ${formatUserAgent(device.user_agent)}`"
                            @click="removeDevice(device)"
                        >
                            <Trash2 />
                        </Button>
                    </li>
                </ul>
            </div>
        </div>
    </SettingsSection>

    <form novalidate @submit.prevent="submit">
        <SettingsSection
            :icon="ListChecks"
            title="Qué te avisamos"
            description="Dónde y cómo te llega cada aviso."
            flush
        >
            <template #aside>
                <p
                    class="flex items-center gap-2 pt-1 text-xs text-muted-foreground"
                >
                    <ShieldCheck class="size-3.5 shrink-0" aria-hidden="true" />
                    Nunca muestra el texto de los clientes
                    <FieldHelp title="Cómo funcionan los avisos">
                        <p class="text-muted-foreground">
                            Si desactivas un aviso en la app tampoco sonará ni
                            aparecerá en el navegador. «Navegador» viene
                            activado para los mensajes y los avisos urgentes.
                            Cada aviso del navegador muestra su título y un
                            resumen seguro (quién escribió y en qué
                            conversación), nunca el texto de los mensajes de los
                            clientes; los del chat interno no incluyen vista
                            previa.
                        </p>
                    </FieldHelp>
                </p>
            </template>
            <div>
                <div
                    class="hidden grid-cols-[1fr_repeat(3,5.5rem)] gap-2 border-b border-border/70 bg-surface-sunken/40 px-4 py-2 text-xs font-medium text-muted-foreground sm:grid sm:px-6"
                    aria-hidden="true"
                >
                    <span>Aviso</span>
                    <span class="text-center">En la app</span>
                    <span class="text-center">Sonido</span>
                    <span class="text-center">Navegador</span>
                </div>
                <ul class="divide-y divide-border/60">
                    <li
                        v-for="(kind, index) in kinds"
                        :key="kind.value"
                        class="grid gap-3 px-4 py-3 transition-colors hover:bg-primary-soft/25 sm:grid-cols-[1fr_repeat(3,5.5rem)] sm:items-center sm:gap-2 sm:px-6"
                    >
                        <div class="min-w-0">
                            <p
                                class="flex flex-wrap items-center gap-2 text-sm font-medium"
                            >
                                {{ kind.label }}
                                <StatusPill
                                    :label="kind.level_label"
                                    :tone="
                                        kind.level === 'urgent'
                                            ? 'danger'
                                            : 'muted'
                                    "
                                />
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ kind.description }}
                            </p>
                        </div>
                        <label
                            class="flex items-center justify-between gap-2 text-xs sm:justify-center"
                        >
                            <span class="sm:sr-only">En la app</span>
                            <Switch
                                v-model="form.preferences[index].in_app"
                                :aria-label="`${kind.label}: en la app`"
                            />
                        </label>
                        <label
                            class="flex items-center justify-between gap-2 text-xs sm:justify-center"
                        >
                            <span class="sm:sr-only">Sonido</span>
                            <Switch
                                v-model="form.preferences[index].sound"
                                :disabled="!form.preferences[index].in_app"
                                :aria-label="`${kind.label}: sonido`"
                            />
                        </label>
                        <label
                            class="flex items-center justify-between gap-2 text-xs sm:justify-center"
                        >
                            <span class="sm:sr-only">Navegador</span>
                            <Switch
                                v-model="form.preferences[index].browser"
                                :disabled="
                                    !form.preferences[index].in_app ||
                                    (!browserAllowed &&
                                        !form.preferences[index].browser)
                                "
                                :aria-label="`${kind.label}: notificación del navegador`"
                                :title="
                                    browserAllowed
                                        ? undefined
                                        : 'Permite primero las notificaciones en este navegador'
                                "
                            />
                        </label>
                    </li>
                </ul>
            </div>

            <template #footer>
                <p
                    v-if="form.isDirty"
                    class="text-sm text-muted-foreground"
                    aria-live="polite"
                >
                    Tienes cambios sin guardar
                </p>
                <div class="ml-auto flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="!form.isDirty || form.processing"
                        @click="form.reset()"
                    >
                        Descartar
                    </Button>
                    <Button
                        type="submit"
                        :disabled="!form.isDirty"
                        :loading="form.processing"
                    >
                        Guardar preferencias
                    </Button>
                </div>
            </template>
        </SettingsSection>
    </form>
</template>
