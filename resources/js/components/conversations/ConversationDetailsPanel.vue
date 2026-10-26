<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    ExternalLink,
    History,
    Mail,
    Megaphone,
    NotebookPen,
    PenLine,
    Phone,
    UserPlus,
    UserRound,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ChannelIcon from '@/components/connections/ChannelIcon.vue';
import SaveContactDialog from '@/components/contacts/SaveContactDialog.vue';
import UnsavedContactBadge from '@/components/contacts/UnsavedContactBadge.vue';
import ConversationStatusBadge from '@/components/conversations/ConversationStatusBadge.vue';
import CustomerWindowIndicator from '@/components/conversations/CustomerWindowIndicator.vue';
import DepartmentTag from '@/components/DepartmentTag.vue';
import ExportMenu from '@/components/ExportMenu.vue';
import AliasDialog from '@/components/personalization/AliasDialog.vue';
import TagBadge from '@/components/tags/TagBadge.vue';
import TagPicker from '@/components/tags/TagPicker.vue';
import { Button } from '@/components/ui/button';
import { formatDate, formatDateTime } from '@/lib/format';
import { formatPhone } from '@/lib/phone';
import { save as saveContact } from '@/routes/conversations/contact';
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
const props = defineProps<{
    conversation: ConversationSummary;
    window: CustomerWindow;
    details: ConversationDetails;
    contactUrl: string | null;
}>();

const isWebChat = computed(
    () => props.conversation.connection.channel === 'web_chat',
);
const windowApplies = computed(() => props.window.status !== 'not_applicable');
const differentProfileName = computed(
    () =>
        props.details.contact.profile_name !== null &&
        props.details.contact.profile_name !== '' &&
        props.details.contact.profile_name !== props.details.contact.name,
);

const emit = defineEmits<{
    close: [];
    select: [conversationId: number];
}>();

/* "Guardar contacto": a provisional customer enters the directory. */
const saveContactOpen = ref(false);
const panel = ref<HTMLElement | null>(null);

/**
 * Once saved, the "Guardar contacto" button is gone: focus moves to the
 * contact's link (or the panel) instead of falling back to the page body.
 * While the contact is still unsaved the dialog returns to its trigger.
 */
function contactLinkElement(): HTMLElement | null {
    if (props.details.contact.can_save) {
        return null;
    }

    return (
        panel.value?.querySelector<HTMLElement>('[data-contact-link]') ??
        panel.value
    );
}

/*
 * The viewer's private alias (PERSONALIZATION): only changes how the
 * contact is shown to them; the header, the list and this panel reload.
 */
const aliasOpen = ref(false);

function onAliasSaved(): void {
    router.reload({ only: ['conversation', 'details', 'conversations'] });
}

/**
 * Origin links come from the provider; only web links are rendered.
 */
function safeUrl(url: string | null): string | null {
    return url !== null && /^https?:\/\//i.test(url) ? url : null;
}
</script>

<template>
    <aside
        ref="panel"
        tabindex="-1"
        class="flex h-full min-h-0 flex-col border-l bg-background outline-none"
        aria-label="Información del cliente y de la conversación"
    >
        <header
            class="flex items-center justify-between gap-2 border-b px-4 py-3"
        >
            <h2 class="text-sm font-semibold">Detalles</h2>
            <Button
                variant="ghost"
                size="icon-sm"
                class="pointer-coarse:size-10"
                title="Cerrar el panel de detalles"
                aria-label="Cerrar el panel de detalles"
                @click="emit('close')"
            >
                <X aria-hidden="true" />
            </Button>
        </header>

        <div class="min-h-0 flex-1 overflow-y-auto">
            <section class="grid grid-cols-1 gap-3 border-b px-4 py-4">
                <div class="flex items-start gap-3">
                    <span
                        class="flex size-11 shrink-0 items-center justify-center rounded-full bg-primary-soft text-primary-soft-foreground"
                    >
                        <UserRound class="size-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p
                            class="truncate font-semibold"
                            :title="details.contact.display_name"
                        >
                            {{ details.contact.display_name }}
                        </p>
                        <p
                            v-if="details.contact.alias"
                            class="flex min-w-0 items-center gap-1.5 text-xs text-muted-foreground"
                            data-alias-official-name
                        >
                            <span
                                class="shrink-0 rounded-full border px-1.5 py-px font-medium"
                                title="Solo tú ves este alias"
                                >Alias personal</span
                            >
                            <span class="truncate" :title="details.contact.name"
                                >Nombre oficial:
                                {{ details.contact.name }}</span
                            >
                        </p>
                        <UnsavedContactBadge
                            v-if="!details.contact.is_saved"
                            class="mt-1"
                            label="Contacto no guardado"
                        />
                        <p
                            v-if="isWebChat || differentProfileName"
                            class="truncate text-xs text-muted-foreground"
                        >
                            <template v-if="isWebChat"
                                >Visitante web<template
                                    v-if="differentProfileName"
                                >
                                    · se presentó como
                                    {{ details.contact.profile_name }}</template
                                ></template
                            >
                            <template v-else
                                >En {{ conversation.connection.channel_label }}:
                                {{ details.contact.profile_name }}</template
                            >
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
                        <dd class="truncate" :title="details.contact.email">
                            {{ details.contact.email }}
                        </dd>
                    </div>
                    <div
                        v-if="details.contact.first_interaction_at"
                        class="text-sm text-muted-foreground"
                    >
                        <dt class="inline">Cliente desde:</dt>
                        <dd class="ml-1 inline">
                            {{
                                formatDate(details.contact.first_interaction_at)
                            }}
                        </dd>
                    </div>
                </dl>
                <p
                    v-if="!details.contact.is_saved"
                    class="text-sm text-muted-foreground"
                >
                    Los datos técnicos de esta conversación (teléfono o
                    identificador del canal) se conservan aunque no lo guardes.
                </p>
                <div class="flex flex-wrap gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        class="pointer-coarse:h-10"
                        :title="
                            details.contact.alias
                                ? 'Cambiar o quitar tu alias personal'
                                : 'Ponerle un nombre que solo tú verás'
                        "
                        @click="aliasOpen = true"
                    >
                        <PenLine aria-hidden="true" />
                        {{
                            details.contact.alias
                                ? 'Editar alias'
                                : 'Poner alias'
                        }}
                    </Button>
                    <Button
                        v-if="details.contact.can_save"
                        size="sm"
                        class="pointer-coarse:h-10"
                        @click="saveContactOpen = true"
                    >
                        <UserPlus aria-hidden="true" />
                        Guardar contacto
                    </Button>
                    <Button
                        v-if="contactUrl"
                        variant="outline"
                        size="sm"
                        class="pointer-coarse:h-10"
                        as-child
                    >
                        <Link :href="contactUrl" data-contact-link>
                            <ExternalLink aria-hidden="true" />
                            {{
                                details.contact.is_saved
                                    ? 'Ver en Contactos'
                                    : 'Ver ficha del contacto'
                            }}
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
                <h3 class="section-title">Conversación</h3>
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
                    <dd class="truncate" :title="conversation.connection.name">
                        {{ conversation.connection.name }}
                    </dd>
                    <dt class="text-xs text-muted-foreground">Canal</dt>
                    <dd class="flex min-w-0 items-center gap-1.5">
                        <ChannelIcon
                            :channel="conversation.connection.channel"
                            class="size-3.5 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <span class="truncate">{{
                            conversation.connection.channel_label
                        }}</span>
                    </dd>
                    <template v-if="!windowApplies">
                        <dt class="text-xs text-muted-foreground">
                            Ventana de atención
                        </dt>
                        <dd class="text-muted-foreground">
                            {{ window.label || 'No aplica' }}
                        </dd>
                    </template>
                    <dt class="text-xs text-muted-foreground">Inicio</dt>
                    <dd>{{ formatDateTime(conversation.created_at) }}</dd>
                </dl>
                <CustomerWindowIndicator
                    v-if="conversation.status !== 'closed' && windowApplies"
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
                <h3 class="flex items-center gap-1.5 section-title">
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
                        <p class="text-sm text-success">
                            {{ attribution.reliability_label }}
                        </p>
                        <p v-if="attribution.headline" class="text-sm">
                            {{ attribution.headline }}
                        </p>
                        <a
                            v-if="safeUrl(attribution.source_url)"
                            :href="safeUrl(attribution.source_url) ?? undefined"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="truncate text-sm text-primary underline-offset-2 hover:underline"
                            >{{ attribution.source_url }}</a
                        >
                    </div>
                </template>
                <p v-else class="text-sm text-muted-foreground">
                    {{
                        isWebChat
                            ? 'Origen no identificado.'
                            : 'Origen no identificado: Meta no envió una referencia con esta conversación.'
                    }}
                </p>
            </section>

            <section class="grid gap-2 border-b px-4 py-4 text-sm">
                <h3 class="flex items-center gap-1.5 section-title">
                    <History class="size-3.5" aria-hidden="true" />
                    Conversaciones anteriores
                </h3>
                <ul v-if="details.history.length > 0" class="grid gap-1">
                    <li v-for="previous in details.history" :key="previous.id">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between gap-2 rounded-md px-2 py-1.5 text-left hover:bg-accent pointer-coarse:min-h-11"
                            @click="emit('select', previous.id)"
                        >
                            <span class="min-w-0">
                                <span class="block truncate text-sm">
                                    {{ formatDate(previous.created_at) }}
                                    <template v-if="previous.department">
                                        ·
                                        {{ previous.department.name }}</template
                                    >
                                </span>
                                <span
                                    class="block truncate text-xs text-muted-foreground"
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
                <p v-else class="text-sm text-muted-foreground">
                    No hay otras conversaciones de este cliente que puedas ver.
                </p>
            </section>

            <section
                v-if="details.contactNotes !== null"
                class="grid gap-2 px-4 py-4 text-sm"
            >
                <h3 class="flex items-center gap-1.5 section-title">
                    <NotebookPen class="size-3.5" aria-hidden="true" />
                    Notas del contacto
                </h3>
                <ul v-if="details.contactNotes.length > 0" class="grid gap-2">
                    <li
                        v-for="note in details.contactNotes"
                        :key="note.id"
                        class="rounded-md border border-warning/30 bg-warning-soft/60 px-3 py-2 text-sm"
                    >
                        <p class="line-clamp-4 break-words whitespace-pre-line">
                            {{ note.body }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ note.author ?? 'Usuario eliminado' }} ·
                            {{ formatDate(note.created_at) }}
                        </p>
                    </li>
                </ul>
                <p v-else class="text-sm text-muted-foreground">
                    El contacto no tiene notas privadas.
                </p>
            </section>
        </div>

        <AliasDialog
            v-model:open="aliasOpen"
            :conversation-id="conversation.id"
            :contact-name="details.contact.name"
            :alias="details.contact.alias"
            @saved="onAliasSaved"
        />
        <!-- Kept mounted after saving (the trigger disappears): the dialog
             animates out and focus moves to the contact's link. -->
        <SaveContactDialog
            v-model:open="saveContactOpen"
            :return-focus="contactLinkElement"
            :contact="details.contact"
            :action="saveContact(conversation.id)"
            :can-add-note="details.contactNotes !== null"
        />
    </aside>
</template>
