<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, History, Lock, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import HistoryTimeline from '@/components/history-import/HistoryTimeline.vue';
import StatusPill from '@/components/admin/StatusPill.vue';
import '@/components/admin/admin.css';
import ListAvatar from '@/components/filters/ListAvatar.vue';
import { ARCHIVE_LIST_KEY, plural } from '@/components/history-import/labels';
import PermanentDeleteDialog from '@/components/PermanentDeleteDialog.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatusNote from '@/components/StatusNote.vue';
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
    /** May erase this archived chat for good (Superadministrador). */
    canDeletePermanently?: boolean;
}>();

const permanentDeleteOpen = ref(false);

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

/* The three moments of the conversation, as a small vertical timeline. */
const moments = computed(() => [
    { label: 'Inicio', value: props.conversation.started_at },
    { label: 'Último mensaje', value: props.conversation.last_message_at },
    { label: 'Cierre', value: props.conversation.closed_at },
]);

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
            :description="`${conversation.origin_label} · ${plural(conversation.messages_count, 'mensaje')}`"
        >
            <template #actions>
                <Button variant="ghost" as-child>
                    <Link :href="backHref">
                        <ArrowLeft />
                        Historial
                    </Link>
                </Button>
                <Button
                    v-if="canDeletePermanently"
                    variant="outline"
                    class="text-destructive hover:text-destructive"
                    data-archive-action="delete-permanently"
                    @click="permanentDeleteOpen = true"
                >
                    <Trash2 />
                    Eliminar chat definitivamente
                </Button>
            </template>
        </PageHeader>

        <PermanentDeleteDialog
            v-if="canDeletePermanently"
            v-model:open="permanentDeleteOpen"
            title="Eliminar chat definitivamente"
            :description="`Vas a borrar para siempre el historial archivado de ${contactName}.`"
            :erased="[
                'Todos los mensajes importados de este chat',
                'Sus archivos, también en el almacenamiento',
            ]"
            :kept="[
                'El contacto y sus datos',
                'El registro en Auditoría de quién lo eliminó y cuándo',
            ]"
            :url="`/conversations/${conversation.id}/permanent`"
            confirm-label="Eliminar chat"
        />

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
            class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-start xl:grid-cols-[minmax(0,1fr)_24rem]"
        >
            <HistoryTimeline
                :conversation-id="conversation.id"
                :contact-name="contactName"
                :initial="timeline"
            />

            <aside
                class="admin-section enter-spring overflow-hidden text-sm lg:sticky lg:top-[4.5rem]"
                aria-labelledby="archive-details-title"
            >
                <header
                    class="flex items-center gap-3 border-b accent-gradient-soft px-4 py-4 sm:px-5"
                >
                    <ListAvatar :name="contactName" channel="whatsapp" />
                    <div class="min-w-0 flex-1">
                        <h2
                            id="archive-details-title"
                            class="font-display text-lg leading-tight font-semibold"
                        >
                            Datos de la conversación
                        </h2>
                        <p class="truncate text-xs text-muted-foreground">
                            {{ contactName }}
                        </p>
                    </div>
                </header>
                <div class="p-4 sm:p-5">
                    <dl
                        class="grid grid-cols-[minmax(0,8rem)_minmax(0,1fr)] gap-x-3 gap-y-2.5"
                    >
                        <dt class="text-muted-foreground">Destino</dt>
                        <dd>
                            <StatusPill
                                :label="
                                    conversation.destination === 'archive'
                                        ? 'Archivada · solo consulta'
                                        : 'Importada · cerrada'
                                "
                                :tone="
                                    conversation.destination === 'archive'
                                        ? 'info'
                                        : 'success'
                                "
                            >
                                <Lock
                                    class="size-3 shrink-0"
                                    aria-hidden="true"
                                />
                            </StatusPill>
                        </dd>
                        <dt class="text-muted-foreground">Número</dt>
                        <dd class="wrap-anywhere tabular-nums">
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
                            <span
                                v-if="conversation.tags.length > 0"
                                class="flex flex-wrap gap-1"
                            >
                                <span
                                    v-for="(tag, tagIndex) in conversation.tags"
                                    :key="tagIndex"
                                    class="rounded-full bg-muted px-2 py-px text-xs text-muted-foreground"
                                    >{{ tag.name }}</span
                                >
                            </span>
                            <template v-else>—</template>
                        </dd>
                    </dl>

                    <ol
                        class="relative mt-5 grid gap-3 border-l border-border pl-4"
                        aria-label="Fechas de la conversación"
                    >
                        <li
                            v-for="moment in moments"
                            :key="moment.label"
                            class="relative"
                        >
                            <span
                                class="absolute top-1.5 -left-[1.3125rem] size-2.5 rounded-full border-2 border-card bg-primary/70"
                                aria-hidden="true"
                            />
                            <p class="text-xs text-muted-foreground">
                                {{ moment.label }}
                            </p>
                            <p class="tabular-nums">
                                {{ formatDateTime(moment.value) }}
                            </p>
                        </li>
                    </ol>

                    <template v-if="sourceRows.length > 0">
                        <h3
                            class="mt-6 mb-2 border-t pt-4 text-sm font-semibold"
                        >
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
                </div>
            </aside>
        </div>
    </div>
</template>
