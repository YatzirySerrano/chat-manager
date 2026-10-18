<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Building2, ChevronRight, Plus, UserRound, Users } from '@lucide/vue';
import { ref } from 'vue';
import DepartmentFormDialog from '@/components/admin/DepartmentFormDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { swatchClasses } from '@/lib/swatches';
import { index as departmentsIndex, show } from '@/routes/departments';
import type { ColorSwatch, DepartmentSummary } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Departamentos', href: departmentsIndex() }],
    },
});

defineProps<{
    departments: DepartmentSummary[];
    colors: ColorSwatch[];
    can: { create: boolean };
}>();

const formOpen = ref(false);
</script>

<template>
    <Head title="Departamentos" />

    <div
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8"
    >
        <PageHeader
            title="Departamentos"
            :description="
                can.create
                    ? 'Organiza a los equipos y define quién atiende cada tipo de conversación.'
                    : 'Departamentos a los que perteneces.'
            "
        >
            <template v-if="can.create" #actions>
                <Button @click="formOpen = true">
                    <Plus />
                    Nuevo departamento
                </Button>
            </template>
        </PageHeader>

        <ul
            v-if="departments.length > 0"
            class="grid gap-3 md:grid-cols-2 xl:grid-cols-3"
        >
            <li v-for="department in departments" :key="department.id">
                <Link
                    :href="show(department.id)"
                    prefetch
                    class="group flex h-full flex-col gap-4 rounded-xl border bg-card p-5 shadow-card transition-all outline-none hover:-translate-y-0.5 hover:border-primary/30 hover:shadow-elevated focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    :class="!department.is_active && 'opacity-70'"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <span
                                class="flex size-10 shrink-0 items-center justify-center rounded-lg"
                                :class="swatchClasses[department.color].soft"
                            >
                                <Building2 class="size-4" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <p class="truncate font-medium">
                                    {{ department.name }}
                                </p>
                                <StatusBadge
                                    :active="department.is_active"
                                    active-label="Activo"
                                    inactive-label="Inactivo"
                                />
                            </div>
                        </div>
                        <ChevronRight
                            class="size-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5"
                            aria-hidden="true"
                        />
                    </div>

                    <p
                        class="line-clamp-2 min-h-10 text-sm text-muted-foreground"
                    >
                        {{ department.description || 'Sin descripción.' }}
                    </p>

                    <div
                        class="mt-auto flex flex-wrap items-center gap-x-4 gap-y-1 border-t pt-3 text-xs text-muted-foreground"
                    >
                        <span class="inline-flex items-center gap-1.5">
                            <Users class="size-3.5" aria-hidden="true" />
                            {{ department.members_count ?? 0 }}
                            {{
                                department.members_count === 1
                                    ? 'miembro'
                                    : 'miembros'
                            }}
                        </span>
                        <span class="inline-flex min-w-0 items-center gap-1.5">
                            <UserRound
                                class="size-3.5 shrink-0"
                                aria-hidden="true"
                            />
                            <span class="truncate">
                                {{
                                    department.leaders &&
                                    department.leaders.length > 0
                                        ? department.leaders
                                              .map((leader) => leader.name)
                                              .join(', ')
                                        : 'Sin responsable'
                                }}
                            </span>
                        </span>
                    </div>
                </Link>
            </li>
        </ul>

        <EmptyState
            v-else
            :icon="Building2"
            :title="
                can.create
                    ? 'Aún no hay departamentos'
                    : 'No perteneces a ningún departamento'
            "
            :description="
                can.create
                    ? 'Crea el primer departamento para organizar la atención.'
                    : 'Un administrador debe agregarte a un departamento.'
            "
        >
            <Button v-if="can.create" @click="formOpen = true">
                <Plus />
                Nuevo departamento
            </Button>
        </EmptyState>

        <DepartmentFormDialog
            v-if="can.create"
            v-model:open="formOpen"
            :department="null"
            :colors="colors"
        />
    </div>
</template>
