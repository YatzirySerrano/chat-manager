<script setup lang="ts">
import { computed } from 'vue';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { formatDateTime } from '@/lib/format';
import type { AuditLogEntry } from '@/types';

const props = defineProps<{
    entry: AuditLogEntry | null;
}>();

const open = defineModel<boolean>('open', { required: true });

type Change = { field: string; old: unknown; new: unknown };

const changes = computed<Change[]>(() => {
    const raw = props.entry?.properties?.changes;

    if (!raw || typeof raw !== 'object') {
        return [];
    }

    return Object.entries(
        raw as Record<string, { old?: unknown; new?: unknown }>,
    ).map(([field, value]) => ({ field, old: value?.old, new: value?.new }));
});

const otherProperties = computed(() =>
    Object.entries(props.entry?.properties ?? {}).filter(
        ([key]) => key !== 'changes',
    ),
);

const subjectTypeLabels: Record<string, string> = {
    user: 'Usuario',
    department: 'Departamento',
    role: 'Rol',
};

function display(value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (typeof value === 'boolean') {
        return value ? 'Sí' : 'No';
    }

    if (Array.isArray(value)) {
        return value.length === 0
            ? '—'
            : value.map((item) => display(item)).join(', ');
    }

    if (typeof value === 'object') {
        return JSON.stringify(value);
    }

    return String(value);
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent class="flex w-full flex-col gap-0 p-0 sm:max-w-lg">
            <template v-if="entry">
                <SheetHeader class="border-b p-5">
                    <SheetTitle>{{ entry.action.label }}</SheetTitle>
                    <SheetDescription>
                        {{
                            formatDateTime(entry.created_at, {
                                dateStyle: 'full',
                                timeStyle: 'medium',
                            })
                        }}
                    </SheetDescription>
                </SheetHeader>

                <div class="flex-1 space-y-6 overflow-y-auto p-5 text-sm">
                    <dl class="grid grid-cols-[8rem_1fr] gap-x-4 gap-y-3">
                        <dt class="text-muted-foreground">Realizado por</dt>
                        <dd class="font-medium">{{ entry.actor.name }}</dd>

                        <dt class="text-muted-foreground">Entidad</dt>
                        <dd>
                            <template v-if="entry.subject.type">
                                {{
                                    subjectTypeLabels[entry.subject.type] ??
                                    entry.subject.type
                                }}
                                <span
                                    v-if="entry.subject.label"
                                    class="text-muted-foreground"
                                >
                                    · {{ entry.subject.label }}
                                </span>
                            </template>
                            <template v-else>Plataforma</template>
                        </dd>

                        <dt class="text-muted-foreground">Acción</dt>
                        <dd>
                            <code class="text-xs">{{
                                entry.action.value
                            }}</code>
                        </dd>

                        <dt class="text-muted-foreground">Dirección IP</dt>
                        <dd>{{ entry.ip_address ?? '—' }}</dd>

                        <dt class="text-muted-foreground">Navegador</dt>
                        <dd class="text-xs break-words text-muted-foreground">
                            {{ entry.user_agent ?? '—' }}
                        </dd>
                    </dl>

                    <section v-if="changes.length > 0" class="space-y-2">
                        <h3 class="font-medium">Cambios</h3>
                        <div class="overflow-hidden rounded-md border">
                            <table class="w-full text-left text-xs">
                                <thead
                                    class="bg-muted/50 text-muted-foreground"
                                >
                                    <tr>
                                        <th class="px-3 py-2 font-medium">
                                            Campo
                                        </th>
                                        <th class="px-3 py-2 font-medium">
                                            Antes
                                        </th>
                                        <th class="px-3 py-2 font-medium">
                                            Después
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y">
                                    <tr
                                        v-for="change in changes"
                                        :key="change.field"
                                    >
                                        <td class="px-3 py-2 font-mono">
                                            {{ change.field }}
                                        </td>
                                        <td
                                            class="px-3 py-2 break-all text-muted-foreground line-through decoration-muted-foreground/40"
                                        >
                                            {{ display(change.old) }}
                                        </td>
                                        <td class="px-3 py-2 break-all">
                                            {{ display(change.new) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section
                        v-if="otherProperties.length > 0"
                        class="space-y-2"
                    >
                        <h3 class="font-medium">Detalles</h3>
                        <dl
                            class="grid grid-cols-[10rem_1fr] gap-x-4 gap-y-2 rounded-md border p-3 text-xs"
                        >
                            <template
                                v-for="[key, value] in otherProperties"
                                :key="key"
                            >
                                <dt class="font-mono text-muted-foreground">
                                    {{ key }}
                                </dt>
                                <dd class="break-all">{{ display(value) }}</dd>
                            </template>
                        </dl>
                    </section>
                </div>
            </template>
        </SheetContent>
    </Sheet>
</template>
