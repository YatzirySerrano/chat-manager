<script setup lang="ts">
import {
    CheckCheck,
    Clock,
    Inbox,
    MessagesSquare,
    UserRoundX,
    Users,
} from '@lucide/vue';
import StatCard from '@/components/StatCard.vue';
import type { DepartmentMetrics } from '@/types';

/**
 * Real conversation counts of the department. When it has never received a
 * conversation an explicit empty state is shown instead of a row of zeros.
 */
defineProps<{
    metrics: DepartmentMetrics;
    membersCount: number;
}>();
</script>

<template>
    <!--
      The counters sit straight on the canvas, one row on wide screens: a
      card around six cards only added a frame.
    -->
    <section aria-label="Métricas operativas">
        <div
            v-if="metrics.total > 0"
            class="auto-grid reveal-stagger [--col-min:9.5rem]"
        >
            <StatCard
                label="Pendientes"
                :value="metrics.pending"
                :icon="Inbox"
                hint="Esperando a que un agente las tome."
            />
            <StatCard
                label="En atención"
                :value="metrics.open"
                :icon="MessagesSquare"
                hint="Con una persona responsable."
            />
            <StatCard
                label="Sin asignar"
                :value="metrics.unassigned"
                :icon="UserRoundX"
                hint="Abiertas sin agente responsable."
            />
            <StatCard
                label="Finalizadas hoy"
                :value="metrics.closed_today"
                :icon="Clock"
                hint="Según la zona horaria de la plataforma."
            />
            <StatCard
                label="Finalizadas en total"
                :value="metrics.closed_total"
                :icon="CheckCheck"
            />
            <StatCard label="Miembros" :value="membersCount" :icon="Users" />
        </div>
        <div
            v-else
            class="flex flex-col gap-4 rounded-xl border border-dashed p-5 sm:flex-row sm:items-center"
        >
            <div class="flex flex-1 items-start gap-3">
                <span
                    class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-surface-sunken text-muted-foreground"
                >
                    <MessagesSquare class="size-4" aria-hidden="true" />
                </span>
                <div>
                    <p class="text-sm font-medium">
                        Aún no hay conversaciones en este departamento
                    </p>
                    <p class="text-sm text-muted-foreground">
                        Las métricas aparecerán cuando reciba su primera
                        conversación.
                    </p>
                </div>
            </div>
            <p
                class="inline-flex items-center gap-1.5 text-sm text-muted-foreground"
            >
                <Users class="size-4" aria-hidden="true" />
                <span class="tabular-nums">{{ membersCount }}</span>
                {{ membersCount === 1 ? 'miembro' : 'miembros' }}
            </p>
        </div>
    </section>
</template>
