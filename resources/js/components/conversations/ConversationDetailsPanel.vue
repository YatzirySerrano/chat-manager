<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ExternalLink,
    History,
    Mail,
    Megaphone,
    NotebookPen,
    Phone,
    UserRound,
    X,
} from '@lucide/vue';
import ConversationStatusBadge from '@/components/conversations/ConversationStatusBadge.vue';
import CustomerWindowIndicator from '@/components/conversations/CustomerWindowIndicator.vue';
import DepartmentTag from '@/components/DepartmentTag.vue';
import ExportMenu from '@/components/ExportMenu.vue';
import TagBadge from '@/components/tags/TagBadge.vue';
import TagPicker from '@/components/tags/TagPicker.vue';
import { Button } from '@/components/ui/button';
import { formatDate, formatDateTime } from '@/lib/format';
import { formatPhone } from '@/lib/phone';
import { update as updateTags } from '@/routes/conversations/tags';
import type {
    ConversationDetails,
    ConversationSummary,
    CustomerWindow,
} from '@/types';

/**
 * Right panel of the inbox: who the customer is, where they verifiably came
 * from, the tags, who attends the conversation, its previous conversations
 * (only those the viewer may see) and the contact's latest private notes.
 */
defineProps<{
    conversation: ConversationSummary;
    window: CustomerWindow;
    details: ConversationDetails;
    contactUrl: string | null;
}>();

const emit = defineEmits<{
    close: [];
    select: [conversationId: number];
}>();

/**
 * Origin links come from the provider; only web links are rendered.
 */
function safeUrl(url: string | null): string | null {
    return url !== null && /^https?:\/\//i.test(url) ? url : null;
}
</script>

<template>
    <aside
        class="flex h-full min-h-0 flex-col border-l bg-background"
        aria-label="Información del cliente y de la conversación"
    >
        <header
            class="flex items-center justify-between gap-2 border-b px-4 py-3"
        >
            <h2 class="text-sm font-semibold">Detalles</h2>
            <Button
                variant="ghost"
                size="icon-sm"
                title="Cerrar el panel de detalles"
                aria-label="Cerrar el panel de detalles"
                @click="emit('close')"
            >
                <X aria-hidden="true" />
            </Button>
        </header>

        <div class="min-h-0 flex-1 overflow-y-auto">
            <section class="grid gap-3 border-b px-4 py-4">
                <div class="flex items-start gap-3">
                    <span
                        class="flex size-11 shrink-0 items-center justify-center rounded-full bg-primary-soft text-primary-soft-foreground"
                    >
                        <UserRound class="size-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="truncate font-semibold">
                            {{ details.contact.name }}
                        </p>
                        <p
                            v-if="
                                details.contact.profile_name &&
                                details.contact.profile_name !==
                                    details.contact.name
                            "
                            class="truncate text-xs text-muted-foreground"
                        >
                            En WhatsApp: {{ details.contact.profile_name }}
                        </p>
                    </div>
                </div>
                <dl class="grid gap-1.5 text-sm">
                    <div class="flex items-center gap-2">
                        <dt class="sr-only">Teléfono</dt>
                        <Phone
                            class="size-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <dd class="truncate">
                            {{
                                details.contact.phone_e164
                                    ? formatPhone(details.contact.phone_e164)
                                    : 'Sin teléfono'
                            }}
                        </dd>
                    </div>
                    <div
                        v-if="details.contact.email"
                        class="flex items-center gap-2"
                    >
                        <dt class="sr-only">Correo</dt>
                        <Mail
                            class="size-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <dd class="truncate">{{ details.contact.email }}</dd>
                    </div>
                    <div
                        v-if="details.contact.first_interaction_at"
                        class="text-xs text-muted-foreground"
                    >
                        <dt class="inline">Cliente desde:</dt>
                        <dd class="ml-1 inline">
                            {{
                                formatDate(details.contact.first_interaction_at)
                            }}
                        </dd>
                    </div>
                </dl>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="contactUrl"
                        variant="outline"
                        size="sm"
                        as-child
                    >
                        <Link :href="contactUrl">
                            <ExternalLink aria-hidden="true" />
                            Ver contacto
                        </Link>
                    </Button>
                    <ExportMenu
                        v-if="details.canExportDossier"
                        report="conversation-dossier"
                        :filters="{ conversation: conversation.id }"
                        label="Exportar expediente"
                        heading="Expediente de esta conversación"
                    />
                </div>
                <div v-if="details.contact.tags.length > 0" class="grid gap-1">
                    <p class="text-xs text-muted-foreground">
                        Etiquetas del contacto
                    </p>
                    <div class="flex flex-wrap gap-1">
                        <TagBadge
                            v-for="tag in details.contact.tags"
                            :key="tag.id"
                            :name="tag.name"
                            :color="tag.color"
                            :deleted="!tag.is_active"
                        />
                    </div>
                </div>
            </section>

            <section class="grid gap-3 border-b px-4 py-4 text-sm">
                <h3
                    class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    Conversación
                </h3>
                <dl
                    class="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-x-3 gap-y-2"
                >
                    <dt class="text-xs text-muted-foreground">Estado</dt>
                    <dd>
                        <ConversationStatusBadge
                            :status="conversation.status"
                            :label="conversation.status_label"
                        />
                    </dd>
                    <dt class="text-xs text-muted-foreground">Departamento</dt>
                    <dd class="min-w-0">
                        <DepartmentTag
                            v-if="conversation.department"
                            :name="conversation.department.name"
                            :color="conversation.department.color"
                            :muted="!conversation.department.is_active"
                        />
                        <span v-else class="text-muted-foreground"
                            >Sin departamento</span
                        >
                    </dd>
                    <dt class="text-xs text-muted-foreground">Responsable</dt>
                    <dd class="truncate">
                        {{ conversation.assignee?.name ?? 'Sin agente' }}
                    </dd>
                    <dt class="text-xs text-muted-foreground">Conexión</dt>
                    <dd class="truncate">{{ conversation.connection.name }}</dd>
                    <dt class="text-xs text-muted-foreground">Inicio</dt>
                    <dd>{{ formatDateTime(conversation.created_at) }}</dd>
                </dl>
                <p
                    v-if="
                        conversation.status === 'on_hold' &&
                        conversation.on_hold_reason
                    "
                    class="rounded-md bg-primary-soft/50 px-3 py-2 text-xs text-primary-soft-foreground"
                >
                    En espera desde
                    {{ formatDateTime(conversation.on_hold_at) }}:
                    {{ conversation.on_hold_reason }}
                </p>
                <CustomerWindowIndicator
                    v-if="conversation.status !== 'closed'"
                    :window="window"
                />
                <div class="grid gap-1.5">
                    <p class="text-xs text-muted-foreground">
                        Etiquetas de la conversación
                    </p>
                    <TagPicker
                        :contact-id="details.contact.id"
                        :selected="conversation.tags ?? []"
                        :available="details.availableTags"
                        :disabled="!conversation.can.tag"
                        :sync-url="updateTags.url(conversation.id)"
                        label="Editar etiquetas de la conversación"
                    />
                </div>
            </section>

            <section class="grid gap-2 border-b px-4 py-4 text-sm">
                <h3
                    class="flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    <Megaphone class="size-3.5" aria-hidden="true" />
                    Procedencia
                </h3>
                <template v-if="details.origin.length > 0">
                    <div
                        v-for="attribution in details.origin"
                        :key="attribution.id"
                        class="grid gap-0.5"
                    >
                        <p class="font-medium">
                            {{ attribution.origin_label }}
                        </p>
                        <p class="text-xs text-success">
                            {{ attribution.reliability_label }}
                        </p>
                        <p v-if="attribution.headline" class="text-xs">
                            {{ attribution.headline }}
                        </p>
                        <a
                            v-if="safeUrl(attribution.source_url)"
                            :href="safeUrl(attribution.source_url) ?? undefined"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="truncate text-xs text-primary underline-offset-2 hover:underline"
                            >{{ attribution.source_url }}</a
                        >
                    </div>
                </template>
                <p v-else class="text-xs text-muted-foreground">
                    Origen no identificado: Meta no envió una referencia con
                    esta conversación.
                </p>
            </section>

            <section class="grid gap-2 border-b px-4 py-4 text-sm">
                <h3
                    class="flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    <History class="size-3.5" aria-hidden="true" />
                    Conversaciones anteriores
                </h3>
                <ul v-if="details.history.length > 0" class="grid gap-1">
                    <li v-for="previous in details.history" :key="previous.id">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between gap-2 rounded-md px-2 py-1.5 text-left hover:bg-accent"
                            @click="emit('select', previous.id)"
                        >
                            <span class="min-w-0">
                                <span class="block truncate text-xs">
                                    {{ formatDate(previous.created_at) }}
                                    <template v-if="previous.department">
                                        ·
                                        {{ previous.department.name }}</template
                                    >
                                </span>
                                <span
                                    class="block truncate text-[11px] text-muted-foreground"
                                >
                                    {{ previous.assignee ?? 'Sin agente' }}
                                </span>
                            </span>
                            <ConversationStatusBadge
                                :status="previous.status"
                                :label="previous.status_label"
                            />
                        </button>
                    </li>
                </ul>
                <p v-else class="text-xs text-muted-foreground">
                    No hay otras conversaciones de este cliente a tu alcance.
                </p>
            </section>

            <section
                v-if="details.contactNotes !== null"
                class="grid gap-2 px-4 py-4 text-sm"
            >
                <h3
                    class="flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    <NotebookPen class="size-3.5" aria-hidden="true" />
                    Notas del contacto
                </h3>
                <ul v-if="details.contactNotes.length > 0" class="grid gap-2">
                    <li
                        v-for="note in details.contactNotes"
                        :key="note.id"
                        class="rounded-md border border-warning/30 bg-warning/5 px-3 py-2 text-xs"
                    >
                        <p class="line-clamp-4 break-words whitespace-pre-line">
                            {{ note.body }}
                        </p>
                        <p class="mt-1 text-[11px] text-muted-foreground">
                            {{ note.author ?? 'Usuario eliminado' }} ·
                            {{ formatDate(note.created_at) }}
                        </p>
                    </li>
                </ul>
                <p v-else class="text-xs text-muted-foreground">
                    El contacto no tiene notas privadas.
                </p>
            </section>
        </div>
    </aside>
</template>
