<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Bell,
    BellRing,
    CheckCheck,
    Download,
    Lock,
    RotateCcw,
    Settings2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useNotificationCenter } from '@/composables/useNotificationCenter';
import { formatDateTime, formatRelative } from '@/lib/format';
import { cn } from '@/lib/utils';
import { edit as editPreferences } from '@/routes/notifications/preferences';
import type { AppNotification } from '@/types';

const center = useNotificationCenter();
const open = ref(false);

const badge = computed(() =>
    center.unread.value.total > 99 ? '99+' : String(center.unread.value.total),
);

const label = computed(() =>
    center.unread.value.total === 0
        ? 'Notificaciones, sin pendientes'
        : `Notificaciones, ${center.unread.value.total} sin leer`,
);

/**
 * A ready export: opening it downloads the file (the server turns the
 * visit into a full browser download, or explains that it expired).
 */
function isDownload(item: AppNotification): boolean {
    return item.kind === 'export.finished' && item.url !== null;
}

function select(item: AppNotification): void {
    if (item.url) {
        open.value = false;
    }

    center.open(item);
}

function onOpenChange(value: boolean): void {
    open.value = value;

    if (value) {
        void center.load();
    }
}
</script>

<template>
    <Popover :open="open" @update:open="onOpenChange">
        <!--
            The tooltip has its own floating anchor: without an explicit
            anchor outside it, the popover would attach to the tooltip's and
            never be positioned (it stayed off screen).
        -->
        <PopoverAnchor as-child>
            <span class="inline-flex">
                <Tooltip>
                    <TooltipTrigger as-child>
                        <PopoverTrigger as-child>
                            <Button
                                variant="ghost"
                                size="icon"
                                class="relative size-9 rounded-lg text-muted-foreground pointer-coarse:size-10"
                                :aria-label="label"
                            >
                                <BellRing
                                    v-if="center.unread.value.urgent > 0"
                                    class="size-4 text-foreground"
                                />
                                <Bell v-else class="size-4" />
                                <span
                                    v-if="center.unread.value.total > 0"
                                    :class="
                                        cn(
                                            'absolute -top-1 -right-1 flex h-[1.125rem] min-w-[1.125rem] items-center justify-center rounded-full px-1 text-xs leading-none font-semibold tabular-nums',
                                            center.unread.value.urgent > 0
                                                ? 'bg-destructive text-destructive-foreground'
                                                : 'bg-primary text-primary-foreground',
                                        )
                                    "
                                    aria-hidden="true"
                                >
                                    {{ badge }}
                                </span>
                            </Button>
                        </PopoverTrigger>
                    </TooltipTrigger>
                    <TooltipContent>Notificaciones</TooltipContent>
                </Tooltip>
            </span>
        </PopoverAnchor>

        <PopoverContent
            align="end"
            :side-offset="8"
            class="flex max-h-[min(36rem,calc(100vh-5rem))] w-[min(24rem,calc(100vw-1.5rem))] flex-col p-0"
        >
            <div
                class="flex items-center justify-between gap-2 border-b px-4 py-3"
            >
                <div>
                    <h2 class="text-sm font-semibold">Notificaciones</h2>
                    <p class="text-xs text-muted-foreground" aria-live="polite">
                        {{
                            center.unread.value.total === 0
                                ? 'Estás al día'
                                : `${center.unread.value.total} sin leer`
                        }}
                    </p>
                </div>
                <Button
                    variant="ghost"
                    size="sm"
                    :disabled="center.unread.value.total === 0"
                    @click="center.markAllRead()"
                >
                    <CheckCheck />
                    Marcar todo leído
                </Button>
            </div>

            <div class="flex gap-1 border-b px-3 py-2" role="tablist">
                <Button
                    v-for="option in [
                        { value: false, label: 'Todas' },
                        { value: true, label: 'Sin leer' },
                    ]"
                    :key="option.label"
                    role="tab"
                    size="sm"
                    :variant="
                        center.onlyUnread.value === option.value
                            ? 'secondary'
                            : 'ghost'
                    "
                    :aria-selected="center.onlyUnread.value === option.value"
                    @click="center.onlyUnread.value = option.value"
                >
                    {{ option.label }}
                </Button>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto">
                <div
                    v-if="!center.loaded.value && center.loading.value"
                    class="space-y-3 p-4"
                    aria-busy="true"
                >
                    <div v-for="index in 3" :key="index" class="space-y-2">
                        <Skeleton class="h-4 w-3/4" />
                        <Skeleton class="h-3 w-1/2" />
                    </div>
                </div>

                <div
                    v-else-if="
                        center.failed.value && center.items.value.length === 0
                    "
                    class="flex flex-col items-center gap-3 p-6 text-center text-sm"
                >
                    <AlertTriangle
                        class="size-5 text-warning"
                        aria-hidden="true"
                    />
                    <p class="text-muted-foreground">
                        No se pudieron cargar las notificaciones.
                    </p>
                    <Button variant="outline" size="sm" @click="center.load()">
                        <RotateCcw />
                        Reintentar
                    </Button>
                </div>

                <div
                    v-else-if="center.items.value.length === 0"
                    class="flex flex-col items-center gap-2 p-8 text-center"
                >
                    <Bell
                        class="size-5 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <p class="text-sm font-medium">
                        {{
                            center.onlyUnread.value
                                ? 'No tienes notificaciones sin leer'
                                : 'Aún no tienes notificaciones'
                        }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Aquí verás asignaciones, transferencias, mensajes nuevos
                        y avisos importantes.
                    </p>
                </div>

                <ul v-else class="divide-y">
                    <li v-for="item in center.items.value" :key="item.id">
                        <button
                            type="button"
                            class="flex w-full gap-3 px-4 py-3 text-left transition-colors hover:bg-muted/60 focus-visible:bg-muted/60 focus-visible:outline-none"
                            :class="
                                item.read_at === null && 'bg-primary-soft/30'
                            "
                            @click="select(item)"
                        >
                            <span
                                class="mt-1.5 size-2 shrink-0 rounded-full"
                                :class="
                                    item.read_at !== null
                                        ? 'bg-transparent'
                                        : item.level === 'urgent'
                                          ? 'bg-destructive'
                                          : 'bg-primary'
                                "
                                aria-hidden="true"
                            />
                            <span class="min-w-0 flex-1">
                                <span class="flex items-start gap-2">
                                    <span
                                        class="min-w-0 flex-1 text-sm"
                                        :class="
                                            item.read_at === null
                                                ? 'font-medium'
                                                : 'text-muted-foreground'
                                        "
                                    >
                                        {{ item.title }}
                                    </span>
                                    <Lock
                                        v-if="!item.accessible"
                                        class="mt-0.5 size-3.5 shrink-0 text-muted-foreground"
                                        aria-label="Sin acceso"
                                    />
                                </span>
                                <span
                                    v-if="item.body"
                                    class="mt-0.5 line-clamp-2 block text-xs text-muted-foreground"
                                >
                                    {{ item.body }}
                                </span>
                                <span
                                    class="mt-1 flex items-center gap-2 text-xs text-muted-foreground"
                                >
                                    <time
                                        :datetime="item.updated_at ?? undefined"
                                        :title="formatDateTime(item.updated_at)"
                                    >
                                        {{ formatRelative(item.updated_at) }}
                                    </time>
                                    <span
                                        v-if="isDownload(item)"
                                        class="inline-flex items-center gap-1 font-medium text-primary-soft-foreground"
                                    >
                                        <Download
                                            class="size-3.5"
                                            aria-hidden="true"
                                        />
                                        Descargar
                                    </span>
                                    <span
                                        v-if="item.level === 'urgent'"
                                        class="rounded-full border border-destructive/30 px-1.5 text-destructive"
                                    >
                                        Requiere acción
                                    </span>
                                    <span
                                        v-if="item.read_at === null"
                                        class="sr-only"
                                    >
                                        Sin leer
                                    </span>
                                </span>
                            </span>
                        </button>
                    </li>
                </ul>

                <div
                    v-if="center.hasMore.value"
                    class="border-t p-2 text-center"
                >
                    <Button
                        variant="ghost"
                        size="sm"
                        :disabled="center.loading.value"
                        @click="center.loadMore()"
                    >
                        <Spinner v-if="center.loading.value" />
                        Ver anteriores
                    </Button>
                </div>
            </div>

            <div class="border-t px-2 py-2">
                <Button
                    variant="ghost"
                    size="sm"
                    class="w-full justify-start"
                    as-child
                >
                    <Link :href="editPreferences()" @click="open = false">
                        <Settings2 />
                        Preferencias de notificación
                    </Link>
                </Button>
            </div>
        </PopoverContent>
    </Popover>
</template>
