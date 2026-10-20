<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Zap } from '@lucide/vue';
import StatusBadge from '@/components/StatusBadge.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import type { DepartmentQuickReplies } from '@/types';

/**
 * Read-only list of the quick replies shared specifically with the department;
 * global replies are only summarized by count. Availability is edited from
 * each quick reply's form.
 */
defineProps<{
    quickReplies: DepartmentQuickReplies;
    /** Quick replies module index, null when the viewer cannot manage them. */
    manageUrl: string | null;
}>();
</script>

<template>
    <SurfaceCard
        title="Respuestas rápidas disponibles"
        description="Respuestas compartidas específicamente con este departamento."
    >
        <template v-if="manageUrl" #actions>
            <Link
                :href="manageUrl"
                class="text-sm font-medium text-primary hover:underline"
            >
                Administrar respuestas
            </Link>
        </template>

        <ul v-if="quickReplies.items.length > 0" class="divide-y">
            <li
                v-for="reply in quickReplies.items"
                :key="reply.id"
                class="flex items-center gap-3 px-5 py-3"
            >
                <span
                    class="flex size-8 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground"
                >
                    <Zap class="size-4" aria-hidden="true" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium">
                        {{ reply.title }}
                    </p>
                    <p class="truncate font-mono text-xs text-muted-foreground">
                        /{{ reply.shortcut }}
                    </p>
                </div>
                <StatusBadge
                    :active="reply.is_active"
                    active-label="Activa"
                    inactive-label="Inactiva"
                />
            </li>
        </ul>
        <div v-else class="px-5 py-8 text-center">
            <p class="text-sm font-medium">
                Sin respuestas exclusivas del departamento
            </p>
            <p class="text-sm text-muted-foreground">
                Ninguna respuesta rápida está limitada a este departamento.
            </p>
        </div>

        <p class="border-t px-5 py-3 text-xs text-muted-foreground">
            {{
                quickReplies.global_count === 0
                    ? 'No hay respuestas globales activas.'
                    : quickReplies.global_count === 1
                      ? 'Además, 1 respuesta global activa está disponible para todos los departamentos.'
                      : `Además, ${quickReplies.global_count} respuestas globales activas están disponibles para todos los departamentos.`
            }}
            La disponibilidad se edita desde el formulario de cada respuesta.
        </p>
    </SurfaceCard>
</template>
