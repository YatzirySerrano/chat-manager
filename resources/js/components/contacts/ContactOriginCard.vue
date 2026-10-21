<script setup lang="ts">
import {
    BadgeCheck,
    ChevronDown,
    CircleQuestionMark,
    ExternalLink,
    Hand,
    NotebookPen,
    Sparkles,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import FieldHelp from '@/components/FieldHelp.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { formatDateTime } from '@/lib/format';
import type { ContactAttribution, ContactReceivingConnection } from '@/types';

/**
 * Informative "Procedencia del contacto" section: how and through which
 * number the person reached the company, and where that knowledge comes
 * from. Provider confirmed evidence wins over declarations (resolved by the
 * server); a declared origin is never presented as confirmed by Meta, and
 * without evidence the origin is "no identificado", never "orgánico".
 *
 * Declaring the origin by hand is an exceptional administrative action
 * (global scope only, enforced by the server) offered at the end.
 */
const props = defineProps<{
    current: ContactAttribution | null;
    history: ContactAttribution[];
    channels: string[];
    connections: ContactReceivingConnection[];
    firstInteractionAt: string | null;
    canClassify: boolean;
}>();

defineEmits<{
    classify: [];
}>();

const historyOpen = ref(false);

type DataSource = {
    kind: 'confirmed' | 'declared' | 'inferred';
    label: string;
};

/**
 * Only web links are rendered as anchors.
 */
function safeUrl(url: string | null): string | null {
    return url && /^https?:\/\//i.test(url) ? url : null;
}

function dataSource(attribution: ContactAttribution): DataSource {
    if (attribution.reliability === 'provider_confirmed') {
        return { kind: 'confirmed', label: attribution.reliability_label };
    }

    if (attribution.reliability === 'manually_declared') {
        return {
            kind: 'declared',
            label: attribution.recorded_by
                ? `Declarado por ${attribution.recorded_by.name}`
                : 'Declarado manualmente',
        };
    }

    return { kind: 'inferred', label: 'Inferido' };
}

const identified = computed(
    () => props.current !== null && props.current.origin_type !== 'unknown',
);

const source = computed(() =>
    props.current ? dataSource(props.current) : null,
);

/**
 * Advertising reference exactly as it was recorded; nothing is guessed.
 */
const reference = computed(() => {
    const origin = props.current;

    if (!origin) {
        return [];
    }

    const rows: { label: string; value: string }[] = [];

    if (origin.headline) {
        rows.push({
            label: origin.source_type === 'post' ? 'Publicación' : 'Anuncio',
            value: origin.headline,
        });
    }

    if (origin.source_id) {
        rows.push({
            label:
                origin.source_type === 'post'
                    ? 'ID de la publicación'
                    : 'ID del anuncio',
            value: origin.source_id,
        });
    }

    if (origin.ad_platform) {
        rows.push({ label: 'Plataforma', value: origin.ad_platform });
    }

    if (origin.page_name) {
        rows.push({ label: 'Página', value: origin.page_name });
    }

    if (origin.campaign_name || origin.campaign_id) {
        rows.push({
            label: 'Campaña',
            value: origin.campaign_name ?? origin.campaign_id ?? '',
        });
    }

    if (origin.reliability === 'manually_declared' && origin.body) {
        rows.push({ label: 'Notas', value: origin.body });
    }

    return rows;
});

const referenceUrl = computed(() => safeUrl(props.current?.source_url ?? null));

const sourceTone: Record<DataSource['kind'], string> = {
    confirmed: 'bg-success/10 text-success',
    declared: 'bg-muted text-muted-foreground',
    inferred: 'bg-muted text-muted-foreground',
};
</script>

<template>
    <SurfaceCard title="Procedencia del contacto" body-class="space-y-4 p-5">
        <dl class="grid gap-3 text-sm">
            <div>
                <dt class="text-xs text-muted-foreground">Canal de atención</dt>
                <dd>
                    {{
                        channels.length > 0
                            ? channels.join(', ')
                            : 'Sin canal registrado'
                    }}
                </dd>
            </div>

            <div>
                <dt class="text-xs text-muted-foreground">
                    Conexión receptora
                </dt>
                <dd v-if="connections.length > 0">
                    <ul class="space-y-0.5">
                        <li
                            v-for="connection in connections"
                            :key="connection.id"
                            class="min-w-0"
                        >
                            <span class="font-medium">{{
                                connection.name
                            }}</span>
                            <span
                                v-if="connection.display_phone_number"
                                class="font-mono text-xs text-muted-foreground tabular-nums"
                            >
                                · {{ connection.display_phone_number }}</span
                            >
                        </li>
                    </ul>
                </dd>
                <dd v-else class="text-muted-foreground">
                    Sin conexión registrada
                </dd>
            </div>

            <div>
                <dt class="text-xs text-muted-foreground">
                    Procedencia identificada
                </dt>
                <dd>
                    <span
                        v-if="!identified"
                        class="inline-flex items-center gap-1.5 text-muted-foreground"
                    >
                        <CircleQuestionMark class="size-4" aria-hidden="true" />
                        Origen no identificado
                    </span>
                    <span v-else class="font-medium">{{
                        current?.origin_label
                    }}</span>
                </dd>
            </div>

            <div v-if="reference.length > 0 || referenceUrl">
                <dt class="text-xs text-muted-foreground">
                    Referencia publicitaria
                </dt>
                <dd>
                    <dl
                        class="mt-1 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 rounded-lg bg-muted/40 p-3 text-sm"
                    >
                        <template v-for="row in reference" :key="row.label">
                            <dt class="text-xs text-muted-foreground">
                                {{ row.label }}
                            </dt>
                            <dd class="min-w-0 break-words whitespace-pre-line">
                                {{ row.value }}
                            </dd>
                        </template>
                        <template v-if="referenceUrl">
                            <dt class="text-xs text-muted-foreground">
                                Enlace
                            </dt>
                            <dd class="min-w-0">
                                <a
                                    :href="referenceUrl"
                                    target="_blank"
                                    rel="noopener noreferrer nofollow"
                                    class="inline-flex max-w-full items-center gap-1 text-primary underline-offset-4 hover:underline"
                                >
                                    <span class="truncate">{{
                                        current?.source_url
                                    }}</span>
                                    <ExternalLink
                                        class="size-3.5 shrink-0"
                                        aria-hidden="true"
                                    />
                                </a>
                            </dd>
                        </template>
                    </dl>
                </dd>
            </div>

            <div>
                <dt class="text-xs text-muted-foreground">
                    Fecha de primera interacción
                </dt>
                <dd>
                    {{
                        firstInteractionAt
                            ? formatDateTime(firstInteractionAt)
                            : 'Sin interacción registrada'
                    }}
                </dd>
            </div>

            <div>
                <dt
                    class="flex items-center gap-1 text-xs text-muted-foreground"
                >
                    Fuente del dato
                    <FieldHelp
                        title="Fuente del dato"
                        :help="{
                            description:
                                'Indica de dónde proviene la procedencia mostrada.',
                            howTo: '«Confirmado por Meta»: lo envió Meta al llegar el mensaje (por ejemplo, desde un anuncio). «Declarado por»: lo registró manualmente un administrador según lo que dijo la persona. «Inferido»: lo dedujo el sistema sin confirmación.',
                            warning:
                                'Un dato declarado nunca se convierte en «Confirmado por Meta» y no reemplaza una procedencia confirmada.',
                        }"
                    />
                </dt>
                <dd v-if="current && source" class="flex flex-wrap gap-2">
                    <span
                        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
                        :class="sourceTone[source.kind]"
                    >
                        <BadgeCheck
                            v-if="source.kind === 'confirmed'"
                            class="size-3.5"
                            aria-hidden="true"
                        />
                        <Hand
                            v-else-if="source.kind === 'declared'"
                            class="size-3.5"
                            aria-hidden="true"
                        />
                        <Sparkles v-else class="size-3.5" aria-hidden="true" />
                        {{ source.label }}
                    </span>
                    <span class="text-xs text-muted-foreground">
                        {{ formatDateTime(current.captured_at) }}
                    </span>
                </dd>
                <dd v-else class="text-xs text-muted-foreground">
                    No hay evidencia de cómo llegó este contacto. Cuando escriba
                    desde un anuncio de Meta se registrará automáticamente.
                </dd>
            </div>
        </dl>

        <Collapsible v-if="history.length > 1" v-model:open="historyOpen">
            <CollapsibleTrigger as-child>
                <Button variant="ghost" size="sm" class="-ml-2">
                    <ChevronDown
                        class="transition-transform"
                        :class="historyOpen && 'rotate-180'"
                    />
                    Historial de procedencia ({{ history.length }})
                </Button>
            </CollapsibleTrigger>
            <CollapsibleContent>
                <ol class="mt-2 space-y-2 border-l pl-4">
                    <li v-for="item in history" :key="item.id" class="text-sm">
                        <p class="font-medium">
                            {{ item.origin_label }}
                            <span
                                v-if="item.id === current?.id"
                                class="text-xs font-normal text-success"
                                >(vigente)</span
                            >
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ dataSource(item).label }} ·
                            {{ formatDateTime(item.captured_at) }}
                        </p>
                    </li>
                </ol>
            </CollapsibleContent>
        </Collapsible>

        <div
            v-if="canClassify"
            class="flex flex-wrap items-center justify-between gap-2 border-t pt-3"
        >
            <p class="flex items-center gap-1 text-xs text-muted-foreground">
                Acción administrativa excepcional
                <FieldHelp
                    title="Registrar procedencia declarada"
                    :help="{
                        description:
                            'Solo para administradores con alcance global, cuando la persona indicó cómo nos conoció y no hay evidencia del proveedor.',
                        howTo: 'Registra únicamente lo que la persona dijo. Quedará como «Declarado por» con tu nombre; el historial anterior se conserva.',
                        warning:
                            'Lo declarado nunca se muestra como «Confirmado por Meta» ni reemplaza una procedencia confirmada.',
                    }"
                />
            </p>
            <Button
                variant="ghost"
                size="sm"
                class="text-muted-foreground"
                @click="$emit('classify')"
            >
                <NotebookPen />
                Registrar procedencia declarada
            </Button>
        </div>
    </SurfaceCard>
</template>
