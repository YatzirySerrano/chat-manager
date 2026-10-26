<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, History, Lock } from '@lucide/vue';
import { computed } from 'vue';
import HistoryTimeline from '@/components/history-import/HistoryTimeline.vue';
import {
    ARCHIVE_LIST_KEY,
    formatNumber,
} from '@/components/history-import/labels';
import PageHeader from '@/components/PageHeader.vue';
import StatusNote from '@/components/StatusNote.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/format';
import { formatPhone } from '@/lib/phone';
import { index as historyIndex } from '@/routes/admin/history';
import { show as conversationShow } from '@/routes/admin/history/conversations';
import type {
    ArchivedConversation,
    ArchivedTimelinePage,
} from '@/types/history-import';

defineOptions({
    layout: (pageProps: { conversation: ArchivedConversation }) => ({
        breadcrumbs: [
            { title: 'Historial de Whaticket', href: historyIndex() },
            {
                title:
                    pageProps.conversation.contact?.name ??
                    (pageProps.conversation.contact?.phone
                        ? formatPhone(pageProps.conversation.contact.phone)
                        : 'Conversación importada'),
                href: conversationShow(pageProps.conversation.id),
            },
        ],
    }),
});

const props = defineProps<{
    conversation: ArchivedConversation;
    timeline: ArchivedTimelinePage;
}>();

/** The archive list as it was left (filters and page), if known. */
function archiveListUrl(): string {
    const fallback = historyIndex.url();

    try {
        const saved = sessionStorage.getItem(ARCHIVE_LIST_KEY);

        return saved && saved.startsWith(fallback) ? saved : fallback;
    } catch {
        return fallback;
    }
}

const backHref = archiveListUrl();

const contactName = computed(
    () =>
        props.conversation.contact?.name ??
        (props.conversation.contact?.phone
            ? formatPhone(props.conversation.contact.phone)
            : 'Contacto sin nombre'),
);

const SOURCE_LABELS: Record<string, string> = {
    kind: 'Tipo de registro',
    ticket_id: 'Ticket',
    conversation_id: 'Id de conversación',
    chat_id: 'Id de chat',
    chat_link: 'Enlace en Whaticket',
    number: 'Número',
    agent: 'Agente',
    department: 'Departamento',
    connection: 'Conexión',
    status: 'Estado',
    tags: 'Etiquetas',
};

const KIND_VALUES: Record<string, string> = {
    ticket: 'Ticket',
    contact: 'Contacto',
    chat_txt: 'Chat.txt',
};

const sourceRows = computed(() =>
    Object.entries(props.conversation.source)
        .map(([key, value]) => {
            const text = Array.isArray(value)
                ? value.join(', ')
                : typeof value === 'string'
                  ? key === 'kind'
                      ? (KIND_VALUES[value] ?? value)
                      : value
                  : '';

            return { key, label: SOURCE_LABELS[key] ?? key, value: text };
        })
        .filter((row) => row.value !== ''),
);
</script>

<template>
    <Head :title="`${contactName} · Historial de Whaticket`" />

    <div class="page-shell">
        <PageHeader
            help="historial"
            :title="contactName"
            show-title
            :description="`${conversation.origin_label} · ${formatNumber(conversation.messages_count)} mensajes`"
        >
            <template #actions>
                <Button variant="ghost" as-child>
                    <Link :href="backHref">
                        <ArrowLeft />
                        Historial
                    </Link>
                </Button>
            </template>
        </PageHeader>

        <StatusNote
            tone="info"
            :icon="History"
            title="Historial importado de Whaticket · solo consulta"
            data-read-only-banner
        >
            No se puede responder, reabrir, asignar ni etiquetar desde aquí.
            <template v-if="conversation.destination === 'import'">
                Esta conversación también aparece en Cerradas.
            </template>
        </StatusNote>

        <div
            class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start"
        >
            <HistoryTimeline
                :conversation-id="conversation.id"
                :contact-name="contactName"
                :initial="timeline"
            />

            <SurfaceCard
                title="Datos de la conversación"
                body-class="p-4 text-sm sm:p-5"
                class="lg:sticky lg:top-[4.5rem]"
            >
                <dl
                    class="grid grid-cols-[minmax(0,8rem)_minmax(0,1fr)] gap-x-3 gap-y-2"
                >
                    <dt class="text-muted-foreground">Destino</dt>
                    <dd>
                        <span class="flex flex-wrap items-center gap-1.5">
                            <Lock
                                class="size-3.5 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            {{
                                conversation.destination === 'archive'
                                    ? 'Archivada · solo consulta'
                                    : 'Importada · cerrada'
                            }}
                        </span>
                    </dd>
                    <dt class="text-muted-foreground">Número</dt>
                    <dd class="wrap-anywhere">
                        {{
                            conversation.contact?.phone
                                ? formatPhone(conversation.contact.phone)
                                : '—'
                        }}
                    </dd>
                    <dt class="text-muted-foreground">En el directorio</dt>
                    <dd>{{ conversation.contact?.saved ? 'Sí' : 'No' }}</dd>
                    <dt class="text-muted-foreground">Departamento</dt>
                    <dd>{{ conversation.department?.name ?? '—' }}</dd>
                    <dt class="text-muted-foreground">Agente</dt>
                    <dd>{{ conversation.agent?.name ?? '—' }}</dd>
                    <dt class="text-muted-foreground">Conexión</dt>
                    <dd class="wrap-anywhere">
                        {{ conversation.connection?.name ?? '—' }}
                        <span
                            v-if="conversation.connection?.historical"
                            class="block text-xs text-muted-foreground"
                        >
                            Conexión histórica sin envío
                        </span>
                    </dd>
                    <dt class="text-muted-foreground">Etiquetas</dt>
                    <dd>
                        {{
                            conversation.tags.length > 0
                                ? conversation.tags
                                      .map((tag) => tag.name)
                                      .join(', ')
                                : '—'
                        }}
                    </dd>
                    <dt class="text-muted-foreground">Inicio</dt>
                    <dd>{{ formatDateTime(conversation.started_at) }}</dd>
                    <dt class="text-muted-foreground">Último mensaje</dt>
                    <dd>{{ formatDateTime(conversation.last_message_at) }}</dd>
                    <dt class="text-muted-foreground">Cierre</dt>
                    <dd>{{ formatDateTime(conversation.closed_at) }}</dd>
                </dl>
                <template v-if="sourceRows.length > 0">
                    <h3 class="mt-5 mb-2 text-sm font-semibold">
                        Datos en Whaticket
                    </h3>
                    <dl
                        class="grid grid-cols-[minmax(0,8rem)_minmax(0,1fr)] gap-x-3 gap-y-2"
                    >
                        <template v-for="row in sourceRows" :key="row.key">
                            <dt class="text-muted-foreground">
                                {{ row.label }}
                            </dt>
                            <dd class="wrap-anywhere">{{ row.value }}</dd>
                        </template>
                    </dl>
                </template>
            </SurfaceCard>
        </div>
    </div>
</template>
