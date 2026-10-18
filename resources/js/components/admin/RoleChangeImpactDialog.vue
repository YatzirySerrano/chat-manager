<script setup lang="ts">
import {
    AlertTriangle,
    ArrowRight,
    Ban,
    Building2,
    MessageSquare,
    Minus,
    Plus,
} from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import type { RoleChangeImpact } from '@/types';

/**
 * Explains the consequences of a role change before it is applied. The same
 * assessment runs again on the server when the change is confirmed.
 */
const props = defineProps<{
    userName: string;
    impact: RoleChangeImpact | null;
    processing?: boolean;
    stale?: boolean;
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    confirm: [];
}>();

const summary = computed(() => {
    const impact = props.impact;

    if (!impact) {
        return '';
    }

    const departments = impact.current_departments.map((d) => d.name);
    const membership =
        departments.length > 0
            ? ` Pertenece ${departments.length === 1 ? 'al departamento' : 'a los departamentos'} ${departments.join(', ')} como ${impact.from?.label ?? 'usuario sin rol'}.`
            : '';
    const losing =
        impact.lost_permissions.length > 0
            ? ` Perderá ${impact.lost_permissions.length} ${impact.lost_permissions.length === 1 ? 'permiso' : 'permisos'}.`
            : '';

    return `${props.userName} pasará de ${impact.from?.label ?? 'sin rol'} a ${impact.to.label}.${membership}${losing}`;
});

const groupedLost = computed(() =>
    groupByModule(props.impact?.lost_permissions ?? []),
);
const groupedGained = computed(() =>
    groupByModule(props.impact?.gained_permissions ?? []),
);

function groupByModule<T extends { group: string }>(
    items: T[],
): [string, T[]][] {
    const groups = new Map<string, T[]>();

    for (const item of items) {
        groups.set(item.group, [...(groups.get(item.group) ?? []), item]);
    }

    return [...groups.entries()];
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="flex max-h-[90dvh] flex-col gap-0 overflow-hidden p-0 sm:max-w-xl"
        >
            <DialogHeader class="border-b px-6 pt-5 pb-4 text-left">
                <DialogTitle class="flex items-center gap-2 text-base">
                    <AlertTriangle class="size-4 text-warning" />
                    Confirmar cambio de rol
                </DialogTitle>
                <DialogDescription>{{ summary }}</DialogDescription>
            </DialogHeader>

            <div
                v-if="impact"
                class="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 py-5 text-sm"
            >
                <p
                    v-if="stale"
                    class="rounded-lg border border-warning/40 bg-warning/10 px-3 py-2 text-foreground"
                >
                    El impacto cambió mientras lo revisabas. Esta es la versión
                    actualizada.
                </p>

                <div
                    class="flex items-center gap-3 rounded-lg border bg-muted/40 px-4 py-3"
                >
                    <div class="min-w-0 flex-1">
                        <p class="text-xs text-muted-foreground">Rol actual</p>
                        <p class="font-medium">
                            {{ impact.from?.label ?? 'Sin rol' }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ impact.from?.scope_label }}
                        </p>
                    </div>
                    <ArrowRight class="size-4 shrink-0 text-muted-foreground" />
                    <div class="min-w-0 flex-1">
                        <p class="text-xs text-muted-foreground">Nuevo rol</p>
                        <p class="font-medium">{{ impact.to.label }}</p>
                        <p
                            class="text-xs"
                            :class="
                                impact.scope_changed
                                    ? 'font-medium text-warning'
                                    : 'text-muted-foreground'
                            "
                        >
                            {{ impact.to.scope_label }}
                        </p>
                    </div>
                </div>

                <div v-if="impact.blockers.length > 0" class="space-y-2">
                    <p
                        class="flex items-center gap-2 font-medium text-destructive"
                    >
                        <Ban class="size-4" /> No es posible aplicar el cambio
                    </p>
                    <ul class="list-disc space-y-1 pl-6 text-muted-foreground">
                        <li v-for="blocker in impact.blockers" :key="blocker">
                            {{ blocker }}
                        </li>
                    </ul>
                </div>

                <section v-if="groupedLost.length > 0" class="space-y-2">
                    <h3 class="flex items-center gap-2 font-medium">
                        <Minus class="size-4 text-destructive" />
                        Permisos que perderá
                    </h3>
                    <div
                        v-for="[group, permissions] in groupedLost"
                        :key="group"
                    >
                        <p class="text-xs font-medium text-muted-foreground">
                            {{ group }}
                        </p>
                        <ul class="mt-1 flex flex-wrap gap-1.5">
                            <li
                                v-for="permission in permissions"
                                :key="permission.value"
                                class="rounded-md border px-2 py-0.5 text-xs"
                                :class="
                                    permission.sensitive
                                        ? 'border-destructive/30 bg-destructive/10 text-destructive'
                                        : 'bg-card'
                                "
                            >
                                {{ permission.label }}
                            </li>
                        </ul>
                    </div>
                </section>

                <section v-if="groupedGained.length > 0" class="space-y-2">
                    <h3 class="flex items-center gap-2 font-medium">
                        <Plus class="size-4 text-success" />
                        Permisos que obtendrá
                    </h3>
                    <div
                        v-for="[group, permissions] in groupedGained"
                        :key="group"
                    >
                        <p class="text-xs font-medium text-muted-foreground">
                            {{ group }}
                        </p>
                        <ul class="mt-1 flex flex-wrap gap-1.5">
                            <li
                                v-for="permission in permissions"
                                :key="permission.value"
                                class="rounded-md border bg-success/10 px-2 py-0.5 text-xs text-success"
                            >
                                {{ permission.label }}
                            </li>
                        </ul>
                    </div>
                </section>

                <p
                    v-if="impact.departments_to_leave.length > 0"
                    class="flex gap-2 rounded-lg border px-3 py-2"
                >
                    <Building2
                        class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                    />
                    <span>
                        El nuevo rol tiene alcance sobre toda la organización,
                        por lo que dejará de pertenecer a:
                        <strong>{{
                            impact.departments_to_leave
                                .map((d) => d.name)
                                .join(', ')
                        }}</strong
                        >.
                    </span>
                </p>

                <p
                    v-if="impact.conversations_to_release > 0"
                    class="flex gap-2 rounded-lg border px-3 py-2"
                >
                    <MessageSquare
                        class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                    />
                    <span>
                        {{ impact.conversations_to_release }}
                        {{
                            impact.conversations_to_release === 1
                                ? 'conversación abierta regresará'
                                : 'conversaciones abiertas regresarán'
                        }}
                        a la cola de su departamento.
                    </span>
                </p>

                <p
                    v-if="
                        impact.current_departments.length > 0 &&
                        impact.departments_to_leave.length === 0
                    "
                    class="text-muted-foreground"
                >
                    Conservará sus departamentos actuales; solo cambia lo que
                    puede hacer en ellos.
                </p>
            </div>

            <DialogFooter class="gap-2 border-t bg-muted/40 px-6 py-3.5">
                <Button
                    variant="outline"
                    :disabled="processing"
                    @click="open = false"
                >
                    Revisar de nuevo
                </Button>
                <Button
                    :disabled="
                        processing || !impact || impact.blockers.length > 0
                    "
                    @click="emit('confirm')"
                >
                    <Spinner v-if="processing" />
                    Confirmar cambio de rol
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
