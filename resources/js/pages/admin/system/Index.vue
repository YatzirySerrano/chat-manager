<script setup lang="ts">
import { Head, router, usePoll } from '@inertiajs/vue3';
import {
    Activity,
    CircleAlert,
    Clock,
    Inbox,
    ListX,
    RefreshCw,
    RotateCcw,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import ConnectionHealthBadge from '@/components/connections/ConnectionHealthBadge.vue';
import DataPagination from '@/components/DataPagination.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatCard from '@/components/StatCard.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import HealthStatusBadge from '@/components/system/HealthStatusBadge.vue';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDateTime, formatDuration, formatRelative } from '@/lib/format';
import { splitTechnicalDetail } from '@/lib/metaErrors';
import { toastFirstError } from '@/lib/toastErrors';
import { verify } from '@/routes/connections';
import { index as systemIndex } from '@/routes/admin/system';
import {
    destroy as destroyFailedJob,
    retry as retryFailedJob,
} from '@/routes/admin/system/failed-jobs';
import type { SystemFailedJob, SystemPageProps } from '@/types/system';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Sistema', href: systemIndex() }],
    },
});

const props = defineProps<SystemPageProps>();

const LIVE_PROPS = [
    'overall',
    'checks',
    'queues',
    'scheduler',
    'checkedAt',
] as const;

// Light, read-only refresh of the health summary; the failed jobs list and
// the log tail refresh on demand with the "Actualizar" button.
usePoll(30000, { only: [...LIVE_PROPS] });

const refreshing = ref(false);

function refreshAll(): void {
    router.reload({
        onStart: () => (refreshing.value = true),
        onFinish: () => (refreshing.value = false),
    });
}

const overallCopy = computed(() => {
    switch (props.overall) {
        case 'fail':
            return 'Hay fallas que afectan la operación. Revisa las verificaciones marcadas.';
        case 'warning':
            return 'El sistema funciona, pero hay puntos que requieren atención.';
        default:
            return 'Todos los componentes responden correctamente.';
    }
});

const totalPending = computed(() =>
    props.queues.reduce((sum, queue) => sum + (queue.pending ?? 0), 0),
);

const oldestAge = computed(() => {
    const ages = props.queues
        .map((queue) => queue.oldest_pending_age)
        .filter((age): age is number => age !== null);

    return ages.length > 0 ? Math.max(...ages) : null;
});

const ALL = 'all';
const queueFilter = ref(props.filters.queue ?? ALL);

function applyQueueFilter(value: unknown): void {
    queueFilter.value = typeof value === 'string' ? value : ALL;

    const query: Record<string, string> = {};

    if (queueFilter.value !== ALL) {
        query.queue = queueFilter.value;
    }

    router.get(systemIndex.url({ query }), undefined, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['failedJobs', 'filters'],
    });
}

type PendingAction = { kind: 'retry' | 'forget'; job: SystemFailedJob };

const pending = ref<PendingAction | null>(null);
const confirmOpen = ref(false);
const processing = ref(false);

function ask(kind: PendingAction['kind'], job: SystemFailedJob): void {
    pending.value = { kind, job };
    confirmOpen.value = true;
}

function confirmAction(): void {
    if (pending.value === null) {
        return;
    }

    const { kind, job } = pending.value;
    const route =
        kind === 'retry'
            ? retryFailedJob(job.uuid)
            : destroyFailedJob(job.uuid);

    router.visit(route, {
        preserveScroll: true,
        only: ['failedJobs', 'failedQueues', 'checks', 'overall'],
        onStart: () => (processing.value = true),
        onFinish: () => {
            processing.value = false;
            confirmOpen.value = false;
        },
        onError: toastFirstError,
    });
}

const confirmCopy = computed(() => {
    if (pending.value === null) {
        return { title: '', description: '', label: '' };
    }

    return pending.value.kind === 'retry'
        ? {
              title: `¿Reintentar esta tarea (${pending.value.job.job})?`,
              description:
                  'La tarea se vuelve a programar desde cero. Solo se permite para tareas que pueden repetirse sin hacer nada dos veces. La acción queda registrada en auditoría.',
              label: 'Reintentar',
          }
        : {
              title: `¿Descartar esta tarea (${pending.value.job.job})?`,
              description:
                  'La tarea se quita de la lista de tareas con error y no se volverá a ejecutar. La acción queda registrada en auditoría.',
              label: 'Descartar',
          };
});

const verifyingId = ref<number | null>(null);

function probeConnection(id: number): void {
    router.visit(verify(id), {
        preserveScroll: true,
        only: ['connections'],
        onStart: () => (verifyingId.value = id),
        onFinish: () => (verifyingId.value = null),
        onError: toastFirstError,
    });
}

/**
 * A check summary is a plain sentence, optionally followed by "Detalle
 * técnico: …" for the technical team; that part goes in a folded block.
 */
function splitCheckSummary(summary: string): {
    message: string;
    technical: string | null;
} {
    const [message, technical] = summary.split(/\s*Detalle técnico:\s*/u, 2);

    return { message, technical: technical ?? null };
}

/** Readable name of the configured WhatsApp transport. */
function transportLabel(transport: string): string {
    switch (transport) {
        case 'meta':
            return 'Real (Meta)';
        case 'simulated':
            return 'Modo de prueba';
        default:
            return 'Apagado';
    }
}

function age(seconds: number | null): string {
    if (seconds === null) {
        return '—';
    }

    return seconds < 60 ? `${seconds} s` : formatDuration(seconds);
}
</script>

<template>
    <Head title="Sistema" />

    <div class="page-shell">
        <PageHeader
            help="sistema"
            title="Sistema"
            description="Estado técnico de la plataforma: servicios, tareas en segundo plano, tareas con error y conexiones. Solo información técnica, sin contenido de clientes."
        >
            <template #actions>
                <span class="text-xs text-muted-foreground">
                    Revisado {{ formatRelative(checkedAt) }}
                </span>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="refreshing"
                    data-tour="system.refresh"
                    @click="refreshAll"
                >
                    <Spinner v-if="refreshing" />
                    <RefreshCw v-else />
                    Actualizar
                </Button>
            </template>
        </PageHeader>

        <SurfaceCard
            body-class="flex flex-col gap-2 px-5 py-4 sm:flex-row sm:items-center sm:gap-4"
            data-tour="system.overall"
        >
            <HealthStatusBadge :status="overall" />
            <p class="text-sm">{{ overallCopy }}</p>
        </SurfaceCard>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard
                label="Tareas pendientes"
                :value="queuesAsync ? totalPending : null"
                :icon="Inbox"
                :hint="`Sistema de tareas: ${queueConnection}`"
                unavailable-label="Las tareas se ejecutan al momento (sin procesos en segundo plano)"
            />
            <StatCard
                label="Espera más larga"
                :value="queuesAsync ? age(oldestAge) : null"
                :icon="Clock"
                hint="Tarea pendiente más antigua"
                unavailable-label="No aplica"
            />
            <StatCard
                label="Tareas con error"
                :value="failedJobs ? failedJobs.meta.total : null"
                :icon="ListX"
                hint="En la lista de tareas con error"
                unavailable-label="No disponible en esta instalación"
            />
            <StatCard
                label="Programador"
                :value="
                    scheduler.age_seconds === null
                        ? null
                        : `hace ${age(scheduler.age_seconds)}`
                "
                :icon="Activity"
                :hint="`Se considera detenido después de ${age(scheduler.stale_after_seconds)}`"
                unavailable-label="Sin registro todavía"
            />
        </div>

        <SurfaceCard
            data-tour="system.checks"
            title="Verificaciones"
            description="Una falla marca la plataforma como «no lista» ante el monitoreo externo; las advertencias solo se muestran aquí."
        >
            <ul class="divide-y">
                <li
                    v-for="check in checks"
                    :key="check.key"
                    class="flex flex-col gap-1 px-5 py-3 sm:flex-row sm:items-start sm:gap-4"
                >
                    <div class="flex w-56 shrink-0 items-center gap-2">
                        <HealthStatusBadge :status="check.status" />
                        <span class="text-sm font-medium">{{
                            check.label
                        }}</span>
                    </div>
                    <div class="text-sm text-muted-foreground">
                        <p>{{ splitCheckSummary(check.summary).message }}</p>
                        <details
                            v-if="splitCheckSummary(check.summary).technical"
                            class="mt-1 text-xs"
                        >
                            <summary class="cursor-pointer select-none">
                                Detalles técnicos (para el equipo técnico)
                            </summary>
                            <span class="font-mono break-words">{{
                                splitCheckSummary(check.summary).technical
                            }}</span>
                        </details>
                    </div>
                </li>
            </ul>
        </SurfaceCard>

        <SurfaceCard
            title="Tareas en segundo plano"
            description="Las tareas en segundo plano se agrupan por tipo; cada grupo tiene su propio proceso."
        >
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Grupo de tareas</TableHead>
                        <TableHead class="text-right">Pendientes</TableHead>
                        <TableHead class="text-right">Programados</TableHead>
                        <TableHead class="text-right">En proceso</TableHead>
                        <TableHead class="text-right">Espera máxima</TableHead>
                        <TableHead>Estado</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="queue in queues"
                        :key="queue.responsibility"
                    >
                        <TableCell>
                            <p class="font-medium">{{ queue.label }}</p>
                            <p class="font-mono text-xs text-muted-foreground">
                                {{ queue.name }}
                            </p>
                        </TableCell>
                        <TableCell class="text-right tabular-nums">{{
                            queue.pending ?? '—'
                        }}</TableCell>
                        <TableCell class="text-right tabular-nums">{{
                            queue.delayed ?? '—'
                        }}</TableCell>
                        <TableCell class="text-right tabular-nums">{{
                            queue.reserved ?? '—'
                        }}</TableCell>
                        <TableCell class="text-right tabular-nums">{{
                            age(queue.oldest_pending_age)
                        }}</TableCell>
                        <TableCell>
                            <div class="flex flex-col gap-1">
                                <HealthStatusBadge :status="queue.status" />
                                <span
                                    class="max-w-xs text-xs text-muted-foreground"
                                    >{{ queue.summary }}</span
                                >
                            </div>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </SurfaceCard>

        <SurfaceCard
            data-tour="system.failed"
            title="Tareas con error"
            description="Solo las tareas que pueden repetirse sin hacer nada dos veces admiten reintento. Los mensajes de WhatsApp se reintentan desde la conversación."
        >
            <template v-if="failedJobs && failedQueues.length > 0" #actions>
                <Select
                    :model-value="queueFilter"
                    @update:model-value="applyQueueFilter"
                >
                    <SelectTrigger
                        class="w-48"
                        aria-label="Filtrar por grupo de tareas"
                    >
                        <SelectValue placeholder="Todos los grupos" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="ALL">Todos los grupos</SelectItem>
                        <SelectItem
                            v-for="queue in failedQueues"
                            :key="queue"
                            :value="queue"
                            >{{ queue }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </template>

            <div v-if="failedJobs === null" class="p-5">
                <EmptyState
                    :icon="CircleAlert"
                    title="Las tareas con error no se guardan en esta instalación"
                    description="Pide al equipo técnico que guarde las tareas con error para poder revisarlas aquí."
                >
                    <details class="text-xs text-muted-foreground">
                        <summary class="cursor-pointer select-none">
                            Detalles técnicos (para el equipo técnico)
                        </summary>
                        <span class="font-mono break-words"
                            >QUEUE_FAILED_DRIVER=database-uuids</span
                        >
                    </details>
                </EmptyState>
            </div>
            <div v-else-if="failedJobs.data.length === 0" class="p-5">
                <EmptyState
                    :icon="ListX"
                    title="No hay tareas con error"
                    description="Cuando una tarea agote sus intentos aparecerá aquí con la primera línea del error."
                />
            </div>
            <template v-else>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Tarea</TableHead>
                            <TableHead>Grupo</TableHead>
                            <TableHead>Falló</TableHead>
                            <TableHead>Error</TableHead>
                            <TableHead v-if="canManage" class="text-right"
                                >Acciones</TableHead
                            >
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="job in failedJobs.data"
                            :key="job.uuid"
                        >
                            <TableCell>
                                <p class="font-medium" :title="job.job_class">
                                    {{ job.job }}
                                </p>
                                <p
                                    class="font-mono text-xs text-muted-foreground"
                                >
                                    {{ job.uuid.slice(0, 8) }}
                                </p>
                            </TableCell>
                            <TableCell class="font-mono text-xs">{{
                                job.queue
                            }}</TableCell>
                            <TableCell class="text-sm whitespace-nowrap">{{
                                formatDateTime(job.failed_at)
                            }}</TableCell>
                            <TableCell
                                class="max-w-md text-xs break-words whitespace-normal text-muted-foreground"
                                >{{ job.exception }}</TableCell
                            >
                            <TableCell v-if="canManage" class="text-right">
                                <div class="flex justify-end gap-1">
                                    <Button
                                        v-if="job.retryable"
                                        variant="outline"
                                        size="sm"
                                        @click="ask('retry', job)"
                                    >
                                        <RotateCcw />
                                        Reintentar
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        @click="ask('forget', job)"
                                    >
                                        <Trash2 />
                                        Descartar
                                    </Button>
                                </div>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
                <div class="border-t px-5 py-3">
                    <DataPagination
                        :meta="failedJobs.meta"
                        noun="tareas con error"
                    />
                </div>
            </template>
        </SurfaceCard>

        <SurfaceCard
            v-if="connections !== null"
            title="Conexiones de WhatsApp"
            description="Estado según lo último que pasó (avisos de Meta, envíos y verificaciones); no consulta a Meta en cada visita. «Probar» comprueba con Meta que los datos de la conexión siguen siendo válidos."
        >
            <div v-if="connections.length === 0" class="p-5">
                <EmptyState
                    title="Sin conexiones"
                    description="Aún no hay números de WhatsApp registrados."
                />
            </div>
            <Table v-else>
                <TableHeader>
                    <TableRow>
                        <TableHead>Conexión</TableHead>
                        <TableHead>Estado</TableHead>
                        <TableHead>Último aviso de Meta</TableHead>
                        <TableHead>Último error</TableHead>
                        <TableHead class="text-right">Acciones</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="connection in connections"
                        :key="connection.id"
                    >
                        <TableCell>
                            <p class="font-medium">{{ connection.name }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ connection.phone ?? 'Sin número' }}
                            </p>
                        </TableCell>
                        <TableCell>
                            <ConnectionHealthBadge
                                :health="connection.health"
                            />
                        </TableCell>
                        <TableCell class="text-sm whitespace-nowrap">{{
                            formatRelative(connection.last_webhook_at)
                        }}</TableCell>
                        <TableCell
                            class="max-w-xs text-xs whitespace-normal text-muted-foreground"
                        >
                            <template v-if="connection.last_error_at">
                                {{ formatDateTime(connection.last_error_at) }}
                                <template v-if="connection.last_error">
                                    —
                                    {{
                                        splitTechnicalDetail(
                                            connection.last_error,
                                        ).message
                                    }}
                                    <details
                                        v-if="
                                            splitTechnicalDetail(
                                                connection.last_error,
                                            ).technical
                                        "
                                        class="mt-1"
                                    >
                                        <summary
                                            class="cursor-pointer select-none"
                                        >
                                            Detalles técnicos
                                        </summary>
                                        <span class="font-mono break-words">{{
                                            splitTechnicalDetail(
                                                connection.last_error,
                                            ).technical
                                        }}</span>
                                    </details>
                                </template>
                            </template>
                            <template v-else>Sin errores registrados</template>
                        </TableCell>
                        <TableCell class="text-right">
                            <Button
                                v-if="connection.can_verify"
                                variant="outline"
                                size="sm"
                                :disabled="verifyingId === connection.id"
                                @click="probeConnection(connection.id)"
                            >
                                <Spinner v-if="verifyingId === connection.id" />
                                Probar
                            </Button>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </SurfaceCard>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <SurfaceCard
                class="lg:col-span-2"
                title="Errores recientes"
                :description="
                    recentErrors.file
                        ? `Últimos errores registrados (solo la primera línea; los datos sensibles se ocultan). Archivo: ${recentErrors.file}.`
                        : 'Todavía no se ha registrado ningún error.'
                "
            >
                <div v-if="recentErrors.entries.length === 0" class="p-5">
                    <EmptyState
                        title="Sin errores recientes"
                        description="No se han registrado errores recientemente."
                    />
                </div>
                <ul v-else class="divide-y">
                    <li
                        v-for="(entry, index) in recentErrors.entries"
                        :key="index"
                        class="flex flex-col gap-1 px-5 py-3"
                    >
                        <div class="flex items-center gap-2 text-xs">
                            <span
                                class="rounded-sm bg-destructive-soft px-1.5 py-0.5 font-medium text-destructive-soft-foreground uppercase"
                                >{{ entry.level }}</span
                            >
                            <span class="text-muted-foreground">{{
                                formatDateTime(entry.at)
                            }}</span>
                        </div>
                        <p class="font-mono text-xs break-words">
                            {{ entry.message }}
                        </p>
                    </li>
                </ul>
            </SurfaceCard>

            <SurfaceCard title="Entorno">
                <dl class="grid grid-cols-2 gap-x-4 gap-y-3 px-5 py-4 text-sm">
                    <dt class="text-muted-foreground">Ambiente</dt>
                    <dd class="font-medium">{{ environment.app_env }}</dd>
                    <dt class="text-muted-foreground">Depuración</dt>
                    <dd
                        class="font-medium"
                        :class="environment.debug ? 'text-destructive' : ''"
                    >
                        {{ environment.debug ? 'Activada' : 'Desactivada' }}
                    </dd>
                    <dt class="text-muted-foreground">PHP</dt>
                    <dd class="font-medium">{{ environment.php }}</dd>
                    <dt class="text-muted-foreground">Laravel</dt>
                    <dd class="font-medium">{{ environment.laravel }}</dd>
                    <dt class="text-muted-foreground">Memoria temporal</dt>
                    <dd class="font-medium">{{ environment.cache }}</dd>
                    <dt class="text-muted-foreground">Tiempo real</dt>
                    <dd class="font-medium">{{ environment.broadcasting }}</dd>
                    <dt class="text-muted-foreground">Envío WhatsApp</dt>
                    <dd class="font-medium">
                        {{ transportLabel(environment.whatsapp_transport) }}
                        <span
                            v-if="
                                environment.whatsapp_transport === 'simulated'
                            "
                            class="block text-xs text-amber-700 dark:text-amber-300"
                            >Modo de prueba: los mensajes no salen a
                            WhatsApp</span
                        >
                    </dd>
                </dl>
            </SurfaceCard>
        </div>

        <ConfirmDialog
            v-model:open="confirmOpen"
            :title="confirmCopy.title"
            :description="confirmCopy.description"
            :confirm-label="confirmCopy.label"
            :destructive="pending?.kind === 'forget'"
            :processing="processing"
            @confirm="confirmAction"
        />
    </div>
</template>
