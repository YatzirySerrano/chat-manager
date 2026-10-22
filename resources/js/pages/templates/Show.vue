<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { TriangleAlert } from '@lucide/vue';
import DeletedRecordNotice from '@/components/DeletedRecordNotice.vue';
import PageHeader from '@/components/PageHeader.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import TemplatePreview from '@/components/templates/TemplatePreview.vue';
import TemplateStatusBadge from '@/components/templates/TemplateStatusBadge.vue';
import { formatDateTime } from '@/lib/format';
import { index as templatesIndex } from '@/routes/templates';
import type { MessageTemplateSummary } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Plantillas de WhatsApp', href: templatesIndex() },
        ],
    },
});

defineProps<{
    template: MessageTemplateSummary;
    components: Record<string, unknown>[];
}>();
</script>

<template>
    <Head :title="`Plantilla ${template.name}`" />

    <div
        class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8"
    >
        <PageHeader
            :title="template.name"
            :description="`${template.language} · ${template.connection?.name ?? ''}`"
        />

        <DeletedRecordNotice
            v-if="template.is_deleted"
            title="Meta ya no devuelve esta plantilla"
            :deleted-at="template.deleted_at"
        >
            Se conserva para el historial de los mensajes que la usaron, pero no
            puede enviarse. Si vuelve a existir en Meta, la siguiente
            sincronización la restaura.
        </DeletedRecordNotice>

        <div class="grid gap-6 lg:grid-cols-[1fr_22rem]">
            <SurfaceCard title="Vista previa" body-class="grid gap-4 p-5">
                <div class="rounded-xl bg-muted/50 p-4">
                    <TemplatePreview :template="template" />
                </div>
                <p class="text-xs text-muted-foreground">
                    Las variables se muestran con los ejemplos registrados en
                    Meta. Al enviarla desde una conversación, el agente captura
                    los valores reales.
                </p>
            </SurfaceCard>

            <div class="grid content-start gap-6">
                <SurfaceCard
                    title="Estado según Meta"
                    body-class="grid gap-3 p-5 text-sm"
                >
                    <TemplateStatusBadge
                        :status="template.status"
                        class="w-fit"
                    />
                    <dl class="grid gap-2 text-xs">
                        <div class="flex justify-between gap-3">
                            <dt class="text-muted-foreground">Categoría</dt>
                            <dd class="font-medium">
                                {{ template.category.label ?? '—' }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-muted-foreground">Calidad</dt>
                            <dd class="font-medium">
                                {{
                                    template.quality_score ?? 'Sin dato de Meta'
                                }}
                            </dd>
                        </div>
                        <div
                            v-if="template.rejected_reason"
                            class="flex justify-between gap-3"
                        >
                            <dt class="text-muted-foreground">
                                Motivo de rechazo
                            </dt>
                            <dd class="font-medium">
                                {{ template.rejected_reason }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-muted-foreground">
                                Cambio de estado
                            </dt>
                            <dd>
                                {{ formatDateTime(template.status_updated_at) }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-muted-foreground">Sincronizada</dt>
                            <dd>
                                {{ formatDateTime(template.last_synced_at) }}
                            </dd>
                        </div>
                    </dl>
                    <div
                        v-if="!template.sendable && template.unsupported_reason"
                        class="flex gap-2 rounded-lg bg-warning/10 p-3 text-xs"
                    >
                        <TriangleAlert
                            class="mt-0.5 size-4 shrink-0 text-warning"
                            aria-hidden="true"
                        />
                        <p>{{ template.unsupported_reason }}</p>
                    </div>
                </SurfaceCard>

                <SurfaceCard
                    title="Variables"
                    body-class="grid gap-2 p-5 text-sm"
                >
                    <p
                        v-if="template.parameters.length === 0"
                        class="text-xs text-muted-foreground"
                    >
                        Esta plantilla no tiene variables.
                    </p>
                    <div
                        v-for="parameter in template.parameters"
                        :key="parameter.key"
                        class="rounded-lg border p-2"
                    >
                        <p class="text-xs font-medium">{{ parameter.label }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{
                                parameter.kind === 'media_url'
                                    ? 'URL https del archivo'
                                    : `Texto, hasta ${parameter.max} caracteres`
                            }}
                            <template v-if="parameter.example">
                                · Ejemplo: {{ parameter.example }}</template
                            >
                        </p>
                    </div>
                </SurfaceCard>
            </div>
        </div>
    </div>
</template>
