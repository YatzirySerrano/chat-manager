<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight, Plug, Star } from '@lucide/vue';
import DeletedBadge from '@/components/DeletedBadge.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import type { DepartmentConnection } from '@/types';

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
        title="Conexiones asociadas"
        description="Números que pueden enviar conversaciones a este departamento."
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
            <li v-for="connection in connections" :key="connection.id">
                <component
                    :is="connection.url ? Link : 'div'"
                    v-bind="connection.url ? { href: connection.url } : {}"
                    class="flex items-center gap-3 rounded-xl px-3 py-2.5"
                    :class="
                        connection.url &&
                        'group/connection hover-lift outline-none hover:bg-accent/50 focus-visible:ring-2 focus-visible:ring-ring'
                    "
                >
                    <span
                        class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-primary-soft text-primary-soft-foreground"
                    >
                        <Plug class="size-4" aria-hidden="true" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p
                            class="flex items-center gap-1.5 truncate text-sm font-medium"
                        >
                            {{ connection.name }}
                            <span
                                v-if="connection.is_default"
                                class="inline-flex items-center gap-1 text-xs font-normal text-muted-foreground"
                            >
                                <Star class="size-3" aria-hidden="true" />
                                Predeterminado
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
                        class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
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
        <div v-else class="px-5 py-6">
            <p class="text-sm font-medium">Sin conexiones asociadas</p>
            <p class="text-sm text-muted-foreground">
                Ninguna conexión envía conversaciones a este departamento.
            </p>
        </div>

        <p
            class="border-t bg-surface-sunken/50 px-5 py-3 text-xs text-muted-foreground"
        >
            La asociación se edita desde el formulario de cada conexión.
        </p>
    </SurfaceCard>
</template>
