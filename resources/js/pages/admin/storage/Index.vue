<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    CircleCheck,
    CircleDashed,
    CircleX,
    FileAudio,
    FileImage,
    FileText,
    FileVideo,
    FolderInput,
    HardDrive,
    RefreshCw,
    Save,
    Sticker,
    Weight,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatCard from '@/components/StatCard.vue';
import HeartbeatMonitor from '@/components/storage/HeartbeatMonitor.vue';
import StorageActivityBars from '@/components/storage/StorageActivityBars.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Button } from '@/components/ui/button';
import { NumberTicker } from '@/components/ui/number-ticker';
import { formatFileSize } from '@/lib/format';
import { index, probe, status as statusRoute } from '@/routes/admin/storage';
import type {
    StorageCheck,
    StorageRecentFile,
    StorageStatus,
} from '@/types/storage';

const props = defineProps<{
    status: StorageStatus;
    canManage: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Almacenamiento', href: index() }],
    },
});

/** Seconds between live checks, and how long the monitor trusts the last one. */
const POLL_SECONDS = 10;
const LIVE_SECONDS = POLL_SECONDS * 2.6;

const current = ref<StorageStatus>(props.status);
const receivedAt = ref(Date.now());
const clock = ref(Date.now());
const beat = ref(0);
const reachable = ref(true);
const paused = ref(false);
const probing = ref(false);
const arrived = ref<Set<string>>(new Set());

let pollTimer: number | undefined;
let clockTimer: number | undefined;
let controller: AbortController | null = null;

function xsrfToken(): string | null {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : null;
}

function accept(next: StorageStatus): void {
    const known = new Set(
        current.value.activity.recent.map((file) => file.key),
    );
    const fresh = next.activity.recent
        .filter((file) => !known.has(file.key))
        .map((file) => file.key);

    arrived.value = new Set(fresh);
    current.value = next;
    receivedAt.value = Date.now();
    clock.value = Date.now();
    reachable.value = true;
    beat.value++;
}

async function refresh(): Promise<void> {
    controller?.abort();
    controller = new AbortController();

    try {
        const response = await fetch(statusRoute().url, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            signal: controller.signal,
        });

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        accept((await response.json()) as StorageStatus);
    } catch (error) {
        if ((error as Error).name !== 'AbortError') {
            reachable.value = false;
        }
    }
}

async function probeNow(): Promise<void> {
    probing.value = true;

    try {
        const response = await fetch(probe().url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken() ?? '',
            },
            credentials: 'same-origin',
        });

        if (response.status === 429) {
            toast.error(
                'Ya se comprobó hace un momento. Espera un minuto e inténtalo de nuevo.',
            );

            return;
        }

        if (!response.ok) {
            throw new Error(String(response.status));
        }

        const next = (await response.json()) as StorageStatus;

        accept(next);

        if (next.state === 'down') {
            toast.error(next.headline);
        } else {
            toast.success('Almacenamiento comprobado: responde.');
        }
    } catch {
        toast.error(
            'No se pudo comprobar el almacenamiento. Inténtalo de nuevo en unos minutos.',
        );
    } finally {
        probing.value = false;
    }
}

function startPolling(): void {
    window.clearInterval(pollTimer);
    pollTimer = window.setInterval(() => void refresh(), POLL_SECONDS * 1000);
}

function onVisibility(): void {
    paused.value = document.hidden;

    if (document.hidden) {
        window.clearInterval(pollTimer);
        controller?.abort();

        return;
    }

    void refresh();
    startPolling();
}

onMounted(() => {
    // The page itself was just answered by the server: the first beat.
    beat.value++;
    startPolling();
    clockTimer = window.setInterval(() => (clock.value = Date.now()), 1000);
    document.addEventListener('visibilitychange', onVisibility);
});

onBeforeUnmount(() => {
    window.clearInterval(pollTimer);
    window.clearInterval(clockTimer);
    controller?.abort();
    document.removeEventListener('visibilitychange', onVisibility);
});

/** Seconds since the snapshot arrived, so ages keep counting between checks. */
const elapsed = computed(() =>
    Math.max(0, Math.round((clock.value - receivedAt.value) / 1000)),
);

const live = computed(() => reachable.value && elapsed.value <= LIVE_SECONDS);

/** Age on the server's clock (never the browser's), plus the time since. */
function ageOf(iso: string | null): number | null {
    if (!iso) {
        return null;
    }

    const seconds =
        (Date.parse(current.value.now) - Date.parse(iso)) / 1000 +
        elapsed.value;

    return Number.isFinite(seconds) ? Math.max(0, Math.round(seconds)) : null;
}

function ago(seconds: number | null): string {
    if (seconds === null) {
        return 'sin registro';
    }

    if (seconds < 5) {
        return 'justo ahora';
    }

    if (seconds < 60) {
        return `hace ${seconds} s`;
    }

    if (seconds < 3600) {
        return `hace ${Math.floor(seconds / 60)} min`;
    }

    if (seconds < 86_400) {
        return `hace ${Math.floor(seconds / 3600)} h`;
    }

    const days = Math.floor(seconds / 86_400);

    return days === 1 ? 'hace 1 día' : `hace ${days} días`;
}

const checkAge = computed(() =>
    current.value.check_age_seconds === null
        ? null
        : current.value.check_age_seconds + elapsed.value,
);

const lastSavedAge = computed(() =>
    ageOf(current.value.activity.last_saved_at),
);

const headline = computed(() => {
    if (!live.value) {
        return 'Sin respuesta del servidor';
    }

    return current.value.headline;
});

const message = computed(() => {
    if (!live.value) {
        return 'Esta pantalla dejó de recibir comprobaciones. Revisa tu conexión; el monitor vuelve a latir en cuanto el servidor responda.';
    }

    return current.value.message;
});

const tone = computed(() => (live.value ? current.value.state : 'down'));

const toneLabel = computed(
    () =>
        ({
            ok: 'Todo en orden',
            warning: 'Con avisos',
            down: 'Sin servicio',
        })[tone.value],
);

const usage = computed(() => current.value.usage);

const usagePercent = computed(() =>
    usage.value.percent === null ? null : Math.min(100, usage.value.percent),
);

const usageTone = computed(() => {
    if (usage.value.level === 'exceeded') {
        return 'bg-destructive';
    }

    return usage.value.level === 'warning' ? 'bg-warning' : 'bg-primary';
});

const usageCopy = computed(() => {
    if (usage.value.percent === null) {
        return 'Sin límite de espacio configurado para los adjuntos.';
    }

    const free = Math.max(0, usage.value.quota_bytes - usage.value.used_bytes);

    return `Quedan ${formatFileSize(free)} de ${formatFileSize(usage.value.quota_bytes)}. Se avisa al llegar al ${usage.value.warning_percent} %.`;
});

const fileIcons: Record<StorageRecentFile['kind'], LucideIcon> = {
    image: FileImage,
    video: FileVideo,
    audio: FileAudio,
    document: FileText,
    sticker: Sticker,
};

const fileLabels: Record<StorageRecentFile['kind'], string> = {
    image: 'Imagen',
    video: 'Video',
    audio: 'Audio',
    document: 'Documento',
    sticker: 'Sticker',
};

const checkIcons: Record<StorageCheck['status'], LucideIcon> = {
    ok: CircleCheck,
    fail: CircleX,
    skipped: CircleDashed,
};

const checkTones: Record<StorageCheck['status'], string> = {
    ok: 'text-success',
    fail: 'text-destructive',
    skipped: 'text-muted-foreground',
};

const checkLabels: Record<StorageCheck['status'], string> = {
    ok: 'Correcto',
    fail: 'Falló',
    skipped: 'Sin evaluar',
};

const pollCopy = computed(() => {
    if (paused.value) {
        return 'En pausa mientras la pestaña no está visible';
    }

    return reachable.value
        ? `En vivo, una comprobación cada ${POLL_SECONDS} s`
        : 'Reintentando la conexión con el servidor';
});
</script>

<template>
    <Head title="Almacenamiento" />

    <div class="page-shell">
        <PageHeader
            title="Almacenamiento"
            description="Dónde se guardan los archivos de las conversaciones y si ese almacenamiento responde en este momento. Solo información técnica, sin contenido de clientes."
        >
            <template v-if="canManage" #actions>
                <Button variant="outline" :loading="probing" @click="probeNow">
                    <RefreshCw class="size-4" aria-hidden="true" />
                    Comprobar ahora
                </Button>
            </template>
        </PageHeader>

        <!-- The monitor: an ink surface in both themes, like a bedside screen. -->
        <section
            data-ink
            class="reveal overflow-hidden rounded-xl bg-sidebar text-sidebar-foreground"
            aria-labelledby="storage-headline"
            :data-tone="tone"
        >
            <div
                class="flex flex-col gap-5 px-5 pt-5 sm:px-7 sm:pt-6 xl:flex-row xl:items-start xl:justify-between xl:gap-10"
            >
                <div class="min-w-0 space-y-2">
                    <p
                        class="monitor-tone inline-flex items-center gap-2 text-xs font-semibold"
                        aria-live="polite"
                    >
                        <span class="relative flex size-2" aria-hidden="true">
                            <span
                                v-if="tone !== 'down'"
                                class="absolute inline-flex size-full animate-ping rounded-full bg-current opacity-60 [animation-duration:2s]"
                            />
                            <span
                                class="relative inline-flex size-2 rounded-full bg-current"
                            />
                        </span>
                        {{ toneLabel }}
                    </p>
                    <h2
                        id="storage-headline"
                        class="font-display text-2xl leading-tight font-semibold text-foreground sm:text-3xl"
                    >
                        {{ headline }}
                    </h2>
                    <p class="max-w-prose text-sm text-pretty">
                        {{ message }}
                    </p>
                </div>

                <dl
                    class="grid shrink-0 grid-cols-1 gap-x-8 gap-y-3 sm:grid-cols-3 xl:min-w-[34rem]"
                >
                    <div class="min-w-0">
                        <dt class="text-xs">Última comprobación</dt>
                        <dd
                            class="font-display text-lg font-semibold text-foreground tabular-nums"
                        >
                            {{ ago(checkAge) }}
                        </dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="text-xs">Último archivo guardado</dt>
                        <dd
                            class="font-display text-lg font-semibold text-foreground tabular-nums"
                        >
                            {{ ago(lastSavedAge) }}
                        </dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="text-xs">Disco de adjuntos</dt>
                        <dd
                            class="font-display text-lg font-semibold break-words text-foreground"
                        >
                            {{ current.disk.label }}
                        </dd>
                        <dd v-if="current.disk.location" class="text-xs">
                            Carpeta {{ current.disk.location }}
                        </dd>
                    </div>
                </dl>
            </div>

            <HeartbeatMonitor
                class="mt-4"
                :state="current.state"
                :live="live"
                :beat="beat"
            />

            <div
                class="flex flex-wrap items-center justify-between gap-x-6 gap-y-1 border-t px-5 py-3 text-xs sm:px-7"
            >
                <p>
                    Cada marca bajo el trazo es una comprobación real del
                    servidor; si dejan de llegar, la línea se aplana.
                </p>
                <p class="tabular-nums">{{ pollCopy }}</p>
            </div>
        </section>

        <!-- The server's disk is not the NAS: say it and how to connect it. -->
        <SurfaceCard
            v-if="current.disk.kind === 'local'"
            class="reveal"
            body-class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-start sm:gap-4"
        >
            <HardDrive
                class="mt-0.5 size-5 shrink-0 text-muted-foreground"
                aria-hidden="true"
            />
            <div class="min-w-0 space-y-1.5">
                <p class="font-display text-base font-semibold">
                    Los adjuntos se guardan en el disco del servidor, no en el
                    Synology
                </p>
                <p
                    class="max-w-prose text-sm text-pretty text-muted-foreground"
                >
                    Para guardarlos en el NAS, monta su carpeta compartida en el
                    servidor y define
                    <code class="font-mono text-xs">MEDIA_DISK=nas</code>,
                    <code class="font-mono text-xs">MEDIA_MOUNT_PATH</code> y
                    <code class="font-mono text-xs">MEDIA_MOUNT_ID</code> en el
                    entorno. Los archivos que ya existen siguen abriéndose desde
                    donde se guardaron. El procedimiento completo está en
                    <code class="font-mono text-xs"
                        >docs/infraestructura-y-despliegue.md</code
                    >.
                </p>
            </div>
        </SurfaceCard>

        <div class="auto-grid reveal-stagger [--col-min:14rem]">
            <StatCard
                label="Guardados hoy"
                :value="current.activity.files_today"
                :icon="Save"
                hint="Archivos que llegaron a este disco desde las 00:00"
            />
            <StatCard
                label="Peso de hoy"
                :value="
                    current.activity.bytes_today > 0
                        ? formatFileSize(current.activity.bytes_today)
                        : '0 KB'
                "
                :icon="Weight"
                hint="Suma del tamaño de los archivos de hoy"
            />
            <StatCard
                label="Últimos 7 días"
                :value="current.activity.files_7d"
                :icon="FolderInput"
                hint="Archivos guardados en la última semana"
            />
            <StatCard
                label="En este disco"
                :value="current.activity.total_files"
                :icon="HardDrive"
                hint="Archivos disponibles guardados aquí"
            />
        </div>

        <div class="grid gap-(--section-gap) xl:grid-cols-3">
            <SurfaceCard
                class="xl:col-span-2"
                title="Archivos guardados en las últimas 24 horas"
                description="Cuántos archivos llegaron al disco en cada hora."
                body-class="px-5 py-4"
            >
                <StorageActivityBars :hours="current.activity.hourly" />
            </SurfaceCard>

            <SurfaceCard
                title="Espacio ocupado"
                description="Lo que ocupan los adjuntos registrados en este disco."
                body-class="flex flex-col gap-3 px-5 py-4"
            >
                <p class="font-display text-3xl font-semibold tabular-nums">
                    {{ formatFileSize(usage.used_bytes) || '0 KB' }}
                    <span
                        v-if="usagePercent !== null"
                        class="text-base font-medium text-muted-foreground"
                    >
                        <NumberTicker :value="usage.percent ?? 0" suffix=" %" />
                    </span>
                </p>
                <div
                    v-if="usagePercent !== null"
                    class="relative h-2.5 overflow-hidden rounded-full bg-muted"
                    role="progressbar"
                    aria-label="Espacio ocupado por los adjuntos"
                    :aria-valuenow="usagePercent"
                    aria-valuemin="0"
                    aria-valuemax="100"
                >
                    <div
                        class="h-full rounded-full transition-[width] duration-(--duration-panel) ease-(--ease-out-quint)"
                        :class="usageTone"
                        :style="{ width: `${usagePercent}%` }"
                    />
                    <span
                        class="absolute inset-y-0 w-px bg-foreground/50"
                        :style="{ left: `${usage.warning_percent}%` }"
                        aria-hidden="true"
                    />
                </div>
                <p class="text-sm text-pretty text-muted-foreground">
                    {{ usageCopy }}
                </p>
                <ul
                    v-if="current.activity.other_disks.length > 0"
                    class="space-y-1 border-t pt-3 text-sm text-muted-foreground"
                >
                    <li
                        v-for="other in current.activity.other_disks"
                        :key="other.disk"
                    >
                        {{ other.files.toLocaleString('es-MX') }}
                        {{
                            other.files === 1
                                ? 'archivo sigue'
                                : 'archivos siguen'
                        }}
                        en «{{ other.label }}» ({{
                            formatFileSize(other.bytes)
                        }}) y se abren desde ahí.
                    </li>
                </ul>
            </SurfaceCard>
        </div>

        <div class="grid gap-(--section-gap) xl:grid-cols-3">
            <SurfaceCard
                class="xl:col-span-2"
                title="Últimos archivos guardados"
                description="El nombre se muestra recortado: aquí no se ve el contenido ni a quién pertenece."
            >
                <TransitionGroup
                    v-if="current.activity.recent.length > 0"
                    tag="ul"
                    name="file"
                    class="divide-y"
                >
                    <li
                        v-for="file in current.activity.recent"
                        :key="file.key"
                        class="flex items-center gap-3 px-5 py-2.5"
                        :class="arrived.has(file.key) && 'file-arrived'"
                    >
                        <component
                            :is="fileIcons[file.kind]"
                            class="size-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">
                                {{ fileLabels[file.kind] }}
                                <span class="font-mono text-xs font-normal">{{
                                    file.name
                                }}</span>
                            </p>
                            <p class="truncate text-xs text-muted-foreground">
                                {{ file.source }}
                            </p>
                        </div>
                        <span
                            class="shrink-0 text-xs text-muted-foreground tabular-nums"
                        >
                            {{ formatFileSize(file.size_bytes) }}
                        </span>
                        <span
                            class="w-20 shrink-0 text-right text-xs tabular-nums"
                        >
                            {{ ago(ageOf(file.saved_at)) }}
                        </span>
                    </li>
                </TransitionGroup>
                <EmptyState
                    v-else
                    title="Todavía no se ha guardado ningún archivo en este disco"
                    description="En cuanto llegue una imagen, un audio o un documento por una conversación, aparecerá aquí."
                    class="px-5 py-8"
                />
            </SurfaceCard>

            <SurfaceCard
                title="Comprobaciones"
                description="En el orden en que se ejecutan; si una falla, las siguientes no se evalúan."
            >
                <ul class="divide-y">
                    <li
                        v-for="check in current.checks"
                        :key="check.key"
                        class="flex items-start gap-3 px-5 py-3"
                    >
                        <component
                            :is="checkIcons[check.status]"
                            class="mt-0.5 size-4 shrink-0"
                            :class="checkTones[check.status]"
                            aria-hidden="true"
                        />
                        <div class="min-w-0 space-y-0.5">
                            <p class="text-sm font-medium">
                                {{ check.label }}
                                <span class="sr-only"
                                    >: {{ checkLabels[check.status] }}</span
                                >
                            </p>
                            <p
                                class="text-xs text-pretty text-muted-foreground"
                            >
                                {{ check.detail }}
                            </p>
                        </div>
                    </li>
                </ul>
                <p
                    v-if="
                        current.activity.pending > 0 ||
                        current.activity.failed_24h > 0
                    "
                    class="border-t px-5 py-3 text-xs text-muted-foreground"
                >
                    <template v-if="current.activity.pending > 0">
                        {{ current.activity.pending.toLocaleString('es-MX') }}
                        {{
                            current.activity.pending === 1
                                ? 'archivo espera'
                                : 'archivos esperan'
                        }}
                        su descarga desde el canal.
                    </template>
                    <template v-if="current.activity.failed_24h > 0">
                        {{
                            current.activity.failed_24h.toLocaleString('es-MX')
                        }}
                        no se
                        {{
                            current.activity.failed_24h === 1
                                ? 'pudo'
                                : 'pudieron'
                        }}
                        guardar en las últimas 24 horas.
                    </template>
                </p>
            </SurfaceCard>
        </div>
    </div>
</template>

<style scoped>
/* The label above the headline takes the monitor's colour. */
.monitor-tone {
    color: oklch(0.82 0.17 155);
}

[data-tone='warning'] .monitor-tone {
    color: oklch(0.84 0.15 82);
}

[data-tone='down'] .monitor-tone {
    color: oklch(0.72 0.19 25);
}

/* A file that arrived with the last check slides in and is lit briefly. */
.file-enter-active {
    transition:
        opacity var(--duration-panel) var(--ease-out-quint),
        transform var(--duration-panel) var(--ease-out-quint);
}

.file-enter-from {
    opacity: 0;
    transform: translateY(-0.5rem);
}

.file-move {
    transition: transform var(--duration-panel) var(--ease-out-quint);
}

.file-leave-active {
    display: none;
}

.file-arrived {
    animation: file-lit 2.4s var(--ease-standard);
}

@keyframes file-lit {
    from {
        background-color: var(--primary-soft);
    }

    to {
        background-color: transparent;
    }
}
</style>
