<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronDown } from '@lucide/vue';
import { computed } from 'vue';
import AuditRestrictedBadge from '@/components/admin/AuditRestrictedBadge.vue';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
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

const resultClasses = {
    success:
        'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
    warning:
        'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300',
    danger: 'border-destructive/30 bg-destructive/10 text-destructive',
} as const;

const technicalJson = computed(() =>
    props.entry?.technical
        ? JSON.stringify(props.entry.technical.properties, null, 2)
        : '',
);
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent class="flex w-full flex-col gap-0 p-0 sm:max-w-lg">
            <template v-if="entry">
                <SheetHeader class="border-b p-5">
                    <SheetTitle class="flex items-center gap-2">
                        {{ entry.action.label }}
                        <AuditRestrictedBadge v-if="entry.restricted" />
                    </SheetTitle>
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
                    <p class="leading-relaxed">{{ entry.summary }}</p>

                    <div
                        v-if="entry.result || entry.error"
                        class="space-y-2 rounded-md border p-3"
                        :class="resultClasses[entry.result?.tone ?? 'danger']"
                    >
                        <p v-if="entry.result" class="font-medium">
                            Resultado: {{ entry.result.label }}
                        </p>
                        <p v-if="entry.error">{{ entry.error }}</p>
                    </div>

                    <dl class="grid grid-cols-[8rem_1fr] gap-x-4 gap-y-3">
                        <dt class="text-muted-foreground">Realizado por</dt>
                        <dd class="font-medium">{{ entry.actor.name }}</dd>

                        <dt class="text-muted-foreground">Elemento</dt>
                        <dd>
                            <template v-if="entry.subject.type_label">
                                {{ entry.subject.type_label }}
                                <template v-if="entry.subject.label">
                                    ·
                                    <Link
                                        v-if="entry.subject.url"
                                        :href="entry.subject.url"
                                        class="font-medium underline-offset-4 hover:underline"
                                    >
                                        {{ entry.subject.label }}
                                    </Link>
                                    <span v-else class="text-muted-foreground">
                                        {{ entry.subject.label }}
                                    </span>
                                </template>
                            </template>
                            <template v-else>Plataforma</template>
                        </dd>

                        <dt class="text-muted-foreground">Dirección IP</dt>
                        <dd>{{ entry.ip_address ?? '—' }}</dd>
                    </dl>

                    <section v-if="entry.changes.length > 0" class="space-y-2">
                        <h3 class="font-medium">Cambios</h3>
                        <div class="overflow-x-auto rounded-md border">
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
                                        v-for="change in entry.changes"
                                        :key="change.field"
                                    >
                                        <td class="px-3 py-2 font-medium">
                                            {{ change.label }}
                                        </td>
                                        <td
                                            class="px-3 py-2 break-words text-muted-foreground"
                                        >
                                            {{ change.before }}
                                        </td>
                                        <td class="px-3 py-2 break-words">
                                            {{ change.after }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section v-if="entry.details.length > 0" class="space-y-2">
                        <h3 class="font-medium">Detalles</h3>
                        <dl
                            class="grid grid-cols-[minmax(0,10rem)_1fr] gap-x-4 gap-y-2 rounded-md border p-3 text-xs"
                        >
                            <template
                                v-for="detail in entry.details"
                                :key="detail.key"
                            >
                                <dt class="text-muted-foreground">
                                    {{ detail.label }}
                                </dt>
                                <dd class="break-words">{{ detail.value }}</dd>
                            </template>
                        </dl>
                    </section>

                    <Collapsible v-if="entry.technical" class="space-y-2">
                        <CollapsibleTrigger
                            class="group flex items-center gap-1 text-xs font-medium text-muted-foreground hover:text-foreground"
                        >
                            <ChevronDown
                                class="size-3.5 transition-transform group-data-[state=open]:rotate-180"
                                aria-hidden="true"
                            />
                            Información técnica
                        </CollapsibleTrigger>
                        <CollapsibleContent
                            class="space-y-2 rounded-md border bg-muted/30 p-3 text-xs"
                        >
                            <p>
                                <span class="text-muted-foreground"
                                    >Clave de la acción:</span
                                >
                                <code class="ml-1 break-all">{{
                                    entry.technical.action
                                }}</code>
                            </p>
                            <p v-if="entry.technical.error">
                                <span class="text-muted-foreground"
                                    >Detalle del error:</span
                                >
                                {{ entry.technical.error }}
                            </p>
                            <p v-if="entry.technical.user_agent">
                                <span class="text-muted-foreground"
                                    >Navegador:</span
                                >
                                <span class="break-words">{{
                                    entry.technical.user_agent
                                }}</span>
                            </p>
                            <pre
                                v-if="
                                    technicalJson !== '{}' &&
                                    technicalJson !== '[]'
                                "
                                class="max-h-64 overflow-auto rounded bg-background p-2 font-mono text-xs leading-snug"
                                >{{ technicalJson }}</pre>
                        </CollapsibleContent>
                    </Collapsible>
                </div>
            </template>
        </SheetContent>
    </Sheet>
</template>
