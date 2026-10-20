<script setup lang="ts">
import {
    BadgeCheck,
    ChevronDown,
    CircleQuestionMark,
    Compass,
    ExternalLink,
    Hand,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { formatDateTime } from '@/lib/format';
import type { ContactAttribution } from '@/types';

/**
 * How the contact reached the company. Provider confirmed evidence wins over
 * manual declarations (resolved by the server); without evidence the origin
 * is shown as unidentified, never as "organic".
 */
const props = defineProps<{
    current: ContactAttribution | null;
    history: ContactAttribution[];
    channels: string[];
    canClassify: boolean;
}>();

defineEmits<{
    classify: [];
}>();

const historyOpen = ref(false);

/**
 * Only web links are rendered as anchors.
 */
function safeUrl(url: string | null): string | null {
    return url && /^https?:\/\//i.test(url) ? url : null;
}

const details = computed(() => {
    const origin = props.current;

    if (!origin) {
        return [];
    }

    const rows: { label: string; value: string }[] = [];

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

    if (origin.headline || origin.source_id) {
        rows.push({
            label: origin.source_type === 'post' ? 'Publicación' : 'Anuncio',
            value: origin.headline ?? `ID ${origin.source_id}`,
        });
    }

    if (origin.headline && origin.source_id) {
        rows.push({ label: 'ID del anuncio', value: origin.source_id });
    }

    if (origin.reliability === 'manually_declared' && origin.body) {
        rows.push({ label: 'Notas', value: origin.body });
    }

    return rows;
});

const isConfirmed = computed(
    () => props.current?.reliability === 'provider_confirmed',
);
</script>

<template>
    <SurfaceCard title="Origen" body-class="space-y-4 p-5">
        <template v-if="canClassify" #actions>
            <Button size="sm" variant="outline" @click="$emit('classify')">
                <Compass />
                Clasificar
            </Button>
        </template>

        <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
            <dt class="text-muted-foreground">Canal</dt>
            <dd>
                {{
                    channels.length > 0
                        ? channels.join(', ')
                        : 'Sin canal registrado'
                }}
            </dd>

            <dt class="text-muted-foreground">Origen</dt>
            <dd>
                <span
                    v-if="!current || current.origin_type === 'unknown'"
                    class="inline-flex items-center gap-1.5 text-muted-foreground"
                >
                    <CircleQuestionMark class="size-4" aria-hidden="true" />
                    Origen no identificado
                </span>
                <span v-else class="font-medium">{{
                    current.origin_label
                }}</span>
            </dd>

            <template v-for="row in details" :key="row.label">
                <dt class="text-muted-foreground">{{ row.label }}</dt>
                <dd class="break-words whitespace-pre-line">{{ row.value }}</dd>
            </template>

            <template v-if="current && safeUrl(current.source_url)">
                <dt class="text-muted-foreground">Enlace</dt>
                <dd class="min-w-0">
                    <a
                        :href="safeUrl(current.source_url) ?? undefined"
                        target="_blank"
                        rel="noopener noreferrer nofollow"
                        class="inline-flex max-w-full items-center gap-1 text-primary underline-offset-4 hover:underline"
                    >
                        <span class="truncate">{{ current.source_url }}</span>
                        <ExternalLink
                            class="size-3.5 shrink-0"
                            aria-hidden="true"
                        />
                    </a>
                </dd>
            </template>
        </dl>

        <div v-if="current" class="flex flex-wrap items-center gap-2 text-xs">
            <span
                class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-medium"
                :class="
                    isConfirmed
                        ? 'bg-success/10 text-success'
                        : 'bg-muted text-muted-foreground'
                "
            >
                <BadgeCheck
                    v-if="isConfirmed"
                    class="size-3.5"
                    aria-hidden="true"
                />
                <Hand v-else class="size-3.5" aria-hidden="true" />
                {{ current.reliability_label }}
            </span>
            <span class="text-muted-foreground">
                {{ formatDateTime(current.captured_at) }}
                <template v-if="current.recorded_by">
                    · {{ current.recorded_by.name }}</template
                >
            </span>
        </div>
        <p v-else class="text-xs text-muted-foreground">
            No hay evidencia de cómo llegó este contacto. Cuando escriba desde
            un anuncio de Meta se registrará automáticamente.
        </p>

        <Collapsible v-if="history.length > 1" v-model:open="historyOpen">
            <CollapsibleTrigger as-child>
                <Button variant="ghost" size="sm" class="-ml-2">
                    <ChevronDown
                        class="transition-transform"
                        :class="historyOpen && 'rotate-180'"
                    />
                    Historial de orígenes ({{ history.length }})
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
                            {{ item.reliability_label }} ·
                            {{ formatDateTime(item.captured_at) }}
                            <template v-if="item.recorded_by">
                                · {{ item.recorded_by.name }}</template
                            >
                        </p>
                    </li>
                </ol>
            </CollapsibleContent>
        </Collapsible>
    </SurfaceCard>
</template>
