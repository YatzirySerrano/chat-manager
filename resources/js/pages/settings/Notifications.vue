<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { BellRing, Volume2 } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import {
    browserNotificationPermission,
    playNotificationSound,
    requestBrowserNotificationPermission,
} from '@/lib/notifications/alerts';
import type { BrowserPermission } from '@/lib/notifications/alerts';
import { edit, update } from '@/routes/notifications/preferences';
import type { NotificationKindOption, NotificationPreference } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Notificaciones', href: edit() }],
    },
});

const props = defineProps<{
    kinds: NotificationKindOption[];
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

onMounted(() => {
    permission.value = browserNotificationPermission();
});

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

    <h1 class="sr-only">Notificaciones</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Notificaciones"
            description="Elige qué avisos quieres recibir dentro de la plataforma, con sonido o como notificación del navegador."
        />

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
                    </div>
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
                                    class="rounded-full border px-1.5 text-[11px] font-normal"
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
                los mensajes de los clientes.
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
