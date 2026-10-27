<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { BellRing, MonitorSmartphone, Trash2, Volume2 } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
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
        ? 'Este navegador recibe avisos aunque la plataforma esté cerrada. Solo indican cuántas notificaciones tienes sin leer, nunca su contenido.'
        : 'Recibe avisos aunque la plataforma esté cerrada, para los tipos con «Navegador» activado. Solo indican cuántas notificaciones tienes sin leer, nunca su contenido.';
});

const canOfferPush = computed(
    () =>
        props.push.public_key !== null &&
        support.value === 'supported' &&
        permission.value !== 'denied',
);

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
        await unsubscribeFromPush();
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

    <div class="space-y-6">
        <h2 class="mb-1 text-lg font-semibold">Notificaciones</h2>
        <p class="text-sm text-pretty text-muted-foreground">
            Elige qué avisos quieres recibir dentro de la plataforma, con sonido
            o como notificación del navegador.
        </p>

        <div class="rounded-xl border bg-card p-4 shadow-card">
            <div class="flex items-start gap-3">
                <BellRing
                    class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <div class="min-w-0 flex-1 space-y-3">
                    <div>
                        <p class="text-sm font-medium">
                            Notificaciones del navegador
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ permissionText }}
                        </p>
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
                        class="text-xs text-muted-foreground"
                        role="status"
                        data-test="sound-locked-notice"
                    >
                        El navegador bloquea el sonido hasta que interactúes con
                        la página (un clic o una tecla) después de abrirla o
                        recargarla. Con la pestaña en segundo plano puede no
                        sonar; activa las notificaciones del navegador para no
                        perder avisos.
                    </p>
                    <p
                        v-else-if="notificationSoundState === 'unsupported'"
                        class="text-xs text-muted-foreground"
                        role="status"
                    >
                        Este navegador no permite reproducir el sonido de aviso.
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Con varias pestañas abiertas el aviso suena una sola
                        vez. Los mensajes del chat interno que tienes abierto y
                        a la vista no suenan. El sonido depende también del
                        volumen y de los ajustes de tu dispositivo.
                    </p>
                </div>
            </div>
        </div>

        <div class="rounded-xl border bg-card p-4 shadow-card">
            <div class="flex items-start gap-3">
                <MonitorSmartphone
                    class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <div class="min-w-0 flex-1 space-y-3">
                    <div>
                        <p class="text-sm font-medium">
                            Notificaciones del navegador
                        </p>
                        <p class="text-sm text-muted-foreground">
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
                    <div v-if="canOfferPush" class="flex flex-wrap gap-2">
                        <Button
                            v-if="currentDevice"
                            type="button"
                            size="sm"
                            variant="outline"
                            :disabled="pushBusy"
                            @click="removeDevice(currentDevice)"
                        >
                            <Spinner v-if="pushBusy" />
                            Desactivar en este navegador
                        </Button>
                        <Button
                            v-else
                            type="button"
                            size="sm"
                            :disabled="pushBusy"
                            @click="enablePush"
                        >
                            <Spinner v-if="pushBusy" />
                            Activar en este navegador
                        </Button>
                    </div>
                    <ul
                        v-if="push.subscriptions.length > 0"
                        class="divide-y rounded-lg border"
                        aria-label="Navegadores con notificaciones activadas"
                    >
                        <li
                            v-for="device in push.subscriptions"
                            :key="device.id"
                            class="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                        >
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
                            <Button
                                type="button"
                                size="icon-sm"
                                variant="ghost"
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
        </div>

        <form class="space-y-4" novalidate @submit.prevent="submit">
            <div class="overflow-hidden rounded-xl border bg-card shadow-card">
                <div
                    class="hidden grid-cols-[1fr_repeat(3,5.5rem)] gap-2 border-b px-4 py-2 text-xs font-medium text-muted-foreground sm:grid"
                    aria-hidden="true"
                >
                    <span>Aviso</span>
                    <span class="text-center">En la app</span>
                    <span class="text-center">Sonido</span>
                    <span class="text-center">Navegador</span>
                </div>
                <ul class="divide-y">
                    <li
                        v-for="(kind, index) in kinds"
                        :key="kind.value"
                        class="grid gap-3 px-4 py-3 sm:grid-cols-[1fr_repeat(3,5.5rem)] sm:items-center sm:gap-2"
                    >
                        <div class="min-w-0">
                            <p
                                class="flex flex-wrap items-center gap-2 text-sm font-medium"
                            >
                                {{ kind.label }}
                                <span
                                    class="rounded-full border px-1.5 text-xs font-normal"
                                    :class="
                                        kind.level === 'urgent'
                                            ? 'border-destructive/30 text-destructive'
                                            : 'text-muted-foreground'
                                    "
                                >
                                    {{ kind.level_label }}
                                </span>
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

            <p class="text-xs text-muted-foreground">
                Si desactivas un aviso en la app tampoco sonará ni aparecerá en
                el navegador. Las notificaciones nunca muestran el contenido de
                los mensajes de los clientes. Las del chat interno muestran
                quién escribió y un fragmento breve solo dentro de la
                plataforma; las del navegador no incluyen el fragmento.
            </p>

            <div class="flex justify-end gap-2">
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
                    :disabled="!form.isDirty || form.processing"
                >
                    <Spinner v-if="form.processing" />
                    Guardar preferencias
                </Button>
            </div>
        </form>
    </div>
</template>
