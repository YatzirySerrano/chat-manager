<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight, Star } from '@lucide/vue';
import ChannelIcon from '@/components/connections/ChannelIcon.vue';
import DeletedBadge from '@/components/DeletedBadge.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import type { DepartmentConnection } from '@/types';
import '@/components/admin/admin.css';

/**
 * Read-only list of the connections authorized to route conversations to the
 * department. The association is edited from each connection's form.
 */
defineProps<{
    connections: DepartmentConnection[];
    /** Connections module index, null when the viewer cannot open it. */
    manageUrl: string | null;
}>();

const toneClasses: Record<DepartmentConnection['health']['tone'], string> = {
    success: 'bg-success-soft text-success-soft-foreground',
    primary: 'bg-primary-soft text-primary-soft-foreground',
    warning: 'bg-warning-soft text-warning-soft-foreground',
    danger: 'bg-destructive-soft text-destructive-soft-foreground',
    neutral: 'bg-muted text-muted-foreground',
};
</script>

<template>
    <SurfaceCard
        title="Conexiones"
        description="Se asignan desde cada conexión."
    >
        <template v-if="manageUrl" #actions>
            <Link
                :href="manageUrl"
                class="text-sm font-medium text-primary hover:underline"
            >
                Ver conexiones
            </Link>
        </template>

        <ul v-if="connections.length > 0" class="grid gap-0.5 p-2">
            <li
                v-for="connection in connections"
                :key="connection.id"
                class="min-w-0"
            >
                <component
                    :is="connection.url ? Link : 'div'"
                    v-bind="
                        connection.url
                            ? { href: `${connection.url}#departamentos` }
                            : {}
                    "
                    class="flex min-w-0 items-center gap-3 rounded-xl px-3 py-2.5"
                    :class="
                        connection.url &&
                        'group/connection hover-lift outline-none hover:bg-accent/50 focus-visible:ring-2 focus-visible:ring-ring'
                    "
                >
                    <span
                        class="channel-tile size-9 shrink-0"
                        :class="
                            !connection.is_active &&
                            'opacity-55 grayscale-[0.6]'
                        "
                        :data-channel-theme="connection.channel"
                        aria-hidden="true"
                    >
                        <ChannelIcon
                            :channel="connection.channel"
                            class="size-4"
                        />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p
                            class="flex min-w-0 items-center gap-1.5 text-sm font-medium"
                        >
                            <span class="truncate">{{ connection.name }}</span>
                            <span
                                v-if="connection.is_default"
                                class="inline-flex shrink-0 items-center"
                                title="Departamento predeterminado de esta conexión"
                            >
                                <Star
                                    class="size-3 fill-current text-warning"
                                    aria-hidden="true"
                                />
                                <span class="sr-only">(predeterminado)</span>
                            </span>
                        </p>
                        <p class="truncate text-xs text-muted-foreground">
                            {{
                                connection.display_phone_number ??
                                'Sin número registrado'
                            }}
                        </p>
                    </div>
                    <DeletedBadge v-if="connection.is_deleted" feminine />
                    <span
                        v-else
                        class="max-w-28 shrink-0 truncate rounded-full px-2 py-0.5 text-xs font-medium sm:max-w-none"
                        :title="connection.health.label"
                        :class="toneClasses[connection.health.tone]"
                    >
                        {{ connection.health.label }}
                    </span>
                    <ChevronRight
                        v-if="connection.url"
                        class="size-4 shrink-0 text-muted-foreground transition-transform duration-(--duration-base) ease-(--ease-spring) group-hover/connection:translate-x-0.5"
                        aria-hidden="true"
                    />
                </component>
            </li>
        </ul>
        <p v-else class="px-5 py-6 text-sm text-muted-foreground">
            Ninguna conexión le envía conversaciones.
        </p>
    </SurfaceCard>
</template>
