<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    CalendarClock,
    History,
    Mail,
    MessagesSquare,
    Pencil,
    Phone,
    RotateCcw,
    Trash2,
    UserPlus,
    UserRoundSearch,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ClassifyOriginDialog from '@/components/contacts/ClassifyOriginDialog.vue';
import ContactFormDialog from '@/components/contacts/ContactFormDialog.vue';
import ContactNotesCard from '@/components/contacts/ContactNotesCard.vue';
import ContactOriginCard from '@/components/contacts/ContactOriginCard.vue';
import SaveContactDialog from '@/components/contacts/SaveContactDialog.vue';
import UnsavedContactBadge from '@/components/contacts/UnsavedContactBadge.vue';
import WhatsAppProfileCard from '@/components/contacts/WhatsAppProfileCard.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import ChannelIcon from '@/components/connections/ChannelIcon.vue';
import DeletedBadge from '@/components/DeletedBadge.vue';
import DeletedRecordNotice from '@/components/DeletedRecordNotice.vue';
import DepartmentTag from '@/components/DepartmentTag.vue';
import DirectoryAvatar from '@/components/directory/DirectoryAvatar.vue';
import DirectoryHero from '@/components/directory/DirectoryHero.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatusNote from '@/components/StatusNote.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import TagPicker from '@/components/tags/TagPicker.vue';
import { Button } from '@/components/ui/button';
import { useRecordDeletion } from '@/composables/useRecordDeletion';
import { contactDeletionCopy } from '@/lib/deletionCopy';
import { formatDateTime, formatRelative } from '@/lib/format';
import { formatPhone } from '@/lib/phone';
import {
    destroy as destroyContact,
    index as contactsIndex,
    restore as restoreContact,
    save as saveContact,
    show,
} from '@/routes/contacts';
import '@/components/filters/filters.css';
import type {
    ConnectionChannel,
    Contact,
    ContactAttribution,
    ContactConversation,
    ContactEnumOption,
    ContactNote,
    ContactOriginTypeValue,
    ContactReceivingConnection,
    ContactTag,
    WhatsAppProfile,
} from '@/types';

const props = defineProps<{
    contact: Contact;
    origin: {
        current: ContactAttribution | null;
        history: ContactAttribution[];
        connections: ContactReceivingConnection[];
    };
    notes: ContactNote[] | null;
    conversations: ContactConversation[];
    /** «Perfil de WhatsApp»; null when the contact has no WhatsApp identity. */
    whatsappProfile?: WhatsAppProfile | null;
    originTypes: ContactEnumOption<ContactOriginTypeValue>[];
    availableTags: ContactTag[];
    can: {
        update: boolean;
        delete: boolean;
        restore: boolean;
        manageNotes: boolean;
        classifyOrigin: boolean;
        assignTags: boolean;
    };
}>();

defineOptions({
    layout: (pageProps: { contact: Contact }) => ({
        breadcrumbs: [
            { title: 'Contactos', href: contactsIndex() },
            { title: pageProps.contact.name, href: show(pageProps.contact.id) },
        ],
    }),
});

const editOpen = ref(false);
const classifyOpen = ref(false);
const saveOpen = ref(false);

const deletion = useRecordDeletion<Contact>({
    destroy: (contact) => destroyContact(contact.id),
    restore: (contact) => restoreContact(contact.id),
    copy: contactDeletionCopy,
});

const channels = computed(() => [
    ...new Set((props.contact.identities ?? []).map((i) => i.channel_label)),
]);

const KNOWN_CHANNELS: ConnectionChannel[] = [
    'whatsapp',
    'messenger',
    'instagram',
    'web_chat',
];

/* One chip per channel the person used, with its brand color. */
const channelChips = computed(() => {
    const seen = new Map<
        string,
        { channel: ConnectionChannel | null; label: string }
    >();

    for (const identity of props.contact.identities ?? []) {
        if (!seen.has(identity.channel)) {
            seen.set(identity.channel, {
                channel: KNOWN_CHANNELS.includes(
                    identity.channel as ConnectionChannel,
                )
                    ? (identity.channel as ConnectionChannel)
                    : null,
                label: identity.channel_label,
            });
        }
    }

    return [...seen.values()];
});

const customFields = computed(() =>
    Object.entries(props.contact.custom_fields ?? {}),
);

const hasConfirmedOrigin = computed(() =>
    props.origin.history.some(
        (item) => item.reliability === 'provider_confirmed',
    ),
);

const conversationTone: Record<ContactConversation['status'], string> = {
    pending: 'bg-warning-soft text-warning-soft-foreground',
    open: 'bg-success-soft text-success-soft-foreground',
    closed: 'bg-muted text-muted-foreground',
};
</script>

<template>
    <Head :title="contact.name" />

    <div class="page-shell">
        <DirectoryHero>
            <template #avatar>
                <DirectoryAvatar
                    :name="contact.name"
                    :src="contact.is_deleted ? null : contact.avatar_url"
                    size="xl"
                    :tone="contact.is_deleted ? 'muted' : 'auto'"
                    class="dir-hero-avatar"
                />
            </template>

            <PageHeader help="contactos" show-title :title="contact.name">
                <template #eyebrow>
                    <div class="flex flex-wrap items-center gap-2">
                        <DeletedBadge v-if="contact.is_deleted" />
                        <UnsavedContactBadge v-else-if="!contact.is_saved" />
                        <span
                            class="inline-flex items-center rounded-full bg-muted/70 px-2 py-0.5 text-xs font-medium text-muted-foreground"
                        >
                            {{ contact.source_label }}
                        </span>
                    </div>
                </template>
                <template #actions>
                    <Button v-if="contact.can?.save" @click="saveOpen = true">
                        <UserPlus />
                        Guardar contacto
                    </Button>
                    <Button
                        v-if="can.update"
                        variant="outline"
                        @click="editOpen = true"
                    >
                        <Pencil />
                        Editar
                    </Button>
                    <Button
                        v-if="can.delete"
                        variant="outline"
                        class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                        @click="deletion.ask('delete', contact)"
                    >
                        <Trash2 />
                        Eliminar
                    </Button>
                    <Button
                        v-if="can.restore"
                        variant="outline"
                        @click="deletion.ask('restore', contact)"
                    >
                        <RotateCcw />
                        Restaurar
                    </Button>
                </template>
            </PageHeader>

            <div
                class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-sm text-muted-foreground"
            >
                <span
                    v-if="contact.phone_e164"
                    class="inline-flex items-center gap-1.5 font-mono text-foreground/90 tabular-nums"
                >
                    <Phone class="size-3.5" aria-hidden="true" />
                    {{ formatPhone(contact.phone_e164) }}
                </span>
                <span
                    v-if="contact.email"
                    class="inline-flex min-w-0 items-center gap-1.5 break-all"
                >
                    <Mail class="size-3.5 shrink-0" aria-hidden="true" />
                    {{ contact.email }}
                </span>
                <span
                    v-if="contact.last_interaction_at"
                    class="inline-flex items-center gap-1.5"
                    :title="formatDateTime(contact.last_interaction_at)"
                >
                    <CalendarClock class="size-3.5" aria-hidden="true" />
                    Última interacción
                    {{ formatRelative(contact.last_interaction_at) }}
                </span>
            </div>

            <template #footer>
                <div
                    class="flex min-w-0 flex-1 flex-wrap items-center gap-x-5 gap-y-2.5"
                >
                    <div
                        v-if="channelChips.length > 0"
                        class="flex flex-wrap items-center gap-1.5"
                        aria-label="Canales"
                        role="group"
                    >
                        <span
                            v-for="chip in channelChips"
                            :key="chip.label"
                            class="inline-flex items-center gap-1.5 rounded-full border border-border/80 bg-card py-0.5 pr-2.5 pl-0.5 text-xs font-medium shadow-(--shadow-control)"
                        >
                            <span
                                class="flex size-5 items-center justify-center rounded-full text-white"
                                :class="
                                    chip.channel
                                        ? `list-channel-${chip.channel}`
                                        : 'bg-muted-foreground'
                                "
                                aria-hidden="true"
                            >
                                <ChannelIcon
                                    v-if="chip.channel"
                                    :channel="chip.channel"
                                    class="size-3"
                                />
                            </span>
                            {{ chip.label }}
                        </span>
                    </div>
                    <span
                        v-if="channelChips.length > 0"
                        class="hidden h-5 w-px bg-border sm:block"
                        aria-hidden="true"
                    />
                    <div
                        class="min-w-0 flex-1"
                        data-tour="contact.tags"
                        aria-label="Etiquetas"
                        role="group"
                    >
                        <TagPicker
                            :contact-id="contact.id"
                            :selected="contact.tags ?? []"
                            :available="availableTags"
                            :disabled="!can.assignTags"
                        />
                    </div>
                </div>
            </template>
        </DirectoryHero>

        <DeletedRecordNotice
            v-if="contact.is_deleted"
            title="Este contacto está eliminado"
            :deleted-at="contact.deleted_at"
        >
            No aparece en las listas, pero conserva todo su historial. Si vuelve
            a escribir, se sigue usando este mismo contacto.
        </DeletedRecordNotice>

        <StatusNote
            v-else-if="!contact.is_saved"
            tone="warning"
            title="Contacto no guardado"
            :icon="UserRoundSearch"
        >
            Se registró automáticamente cuando escribió. Sus conversaciones y
            respuestas funcionan con normalidad, pero no aparece en el
            directorio de Contactos, en sus reportes ni en los conteos de
            etiquetas hasta que alguien lo guarde.
        </StatusNote>

        <!--
          Ficha: who the person is on the left rail, what happened with them
          in the middle, where they came from on the right. Two columns on
          laptops (the origin drops under the rail), three on wide screens.
        -->
        <div
            class="grid reveal-stagger items-start gap-6 lg:grid-cols-[21rem_minmax(0,1fr)] 2xl:grid-cols-[22rem_minmax(0,1fr)_25rem]"
        >
            <div class="flex flex-col gap-6">
                <SurfaceCard title="Datos de contacto" body-class="p-5">
                    <dl class="grid gap-3.5 text-sm">
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Teléfono
                            </dt>
                            <dd class="font-mono tabular-nums">
                                {{ formatPhone(contact.phone_e164) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Correo electrónico
                            </dt>
                            <dd class="break-all">
                                {{ contact.email ?? '—' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Nombre de perfil de WhatsApp
                            </dt>
                            <dd>{{ contact.profile_name ?? '—' }}</dd>
                        </div>
                        <div
                            class="grid grid-cols-2 gap-3 rounded-xl bg-surface-sunken/60 p-3"
                        >
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Primera interacción
                                </dt>
                                <dd>
                                    {{
                                        formatDateTime(
                                            contact.first_interaction_at,
                                        )
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Última interacción
                                </dt>
                                <dd>
                                    {{
                                        formatDateTime(
                                            contact.last_interaction_at,
                                        )
                                    }}
                                </dd>
                            </div>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Registro
                            </dt>
                            <dd>
                                {{ contact.source_label }}
                                <template v-if="contact.creator">
                                    por {{ contact.creator.name }}</template
                                >,
                                {{ formatDateTime(contact.created_at) }}
                            </dd>
                        </div>
                    </dl>
                </SurfaceCard>

                <SurfaceCard
                    title="Números y cuentas vinculados"
                    body-class="p-3"
                >
                    <ul
                        v-if="(contact.identities ?? []).length > 0"
                        class="grid gap-1"
                    >
                        <li
                            v-for="identity in contact.identities"
                            :key="identity.id"
                            class="flex items-start gap-3 rounded-xl p-2 text-sm transition-colors hover:bg-accent/60"
                        >
                            <span
                                class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full text-white shadow-(--shadow-control)"
                                :class="
                                    KNOWN_CHANNELS.includes(
                                        identity.channel as ConnectionChannel,
                                    )
                                        ? `list-channel-${identity.channel}`
                                        : 'bg-muted-foreground'
                                "
                                aria-hidden="true"
                            >
                                <ChannelIcon
                                    v-if="
                                        KNOWN_CHANNELS.includes(
                                            identity.channel as ConnectionChannel,
                                        )
                                    "
                                    :channel="
                                        identity.channel as ConnectionChannel
                                    "
                                    class="size-4"
                                />
                            </span>
                            <div class="min-w-0">
                                <p class="font-medium">
                                    {{ identity.channel_label }}
                                </p>
                                <p
                                    class="truncate font-mono text-xs text-muted-foreground"
                                    :title="identity.external_id"
                                >
                                    {{ identity.external_id }}
                                </p>
                                <p
                                    v-if="identity.display_name"
                                    class="text-xs text-muted-foreground"
                                >
                                    Perfil: {{ identity.display_name }}
                                </p>
                            </div>
                        </li>
                    </ul>
                    <p v-else class="p-2 text-sm text-muted-foreground">
                        Aún no se ha comunicado por ningún canal.
                    </p>
                </SurfaceCard>
            </div>

            <div class="flex flex-col gap-6 lg:row-span-2 2xl:row-span-1">
                <SurfaceCard
                    data-tour="contact.history"
                    title="Historial de conversaciones"
                    description="Solo se muestran las conversaciones que tienes permitido ver."
                    body-class="p-4 sm:p-5"
                >
                    <template v-if="conversations.length > 0" #actions>
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full bg-primary-soft px-2.5 py-1 text-xs font-semibold text-primary-soft-foreground tabular-nums"
                        >
                            <History class="size-3.5" aria-hidden="true" />
                            {{ conversations.length }}
                            {{
                                conversations.length === 1
                                    ? 'conversación'
                                    : 'conversaciones'
                            }}
                        </span>
                    </template>
                    <ol
                        v-if="conversations.length > 0"
                        class="dir-timeline"
                        aria-label="Conversaciones"
                    >
                        <li
                            v-for="(conversation, position) in conversations"
                            :key="conversation.id"
                            class="dir-timeline-item enter-fade"
                            :style="{ '--i': Math.min(position, 8) }"
                        >
                            <span
                                class="dir-timeline-dot"
                                :data-tone="conversation.status"
                                aria-hidden="true"
                            />
                            <article
                                class="flex hover-lift flex-col gap-2 rounded-xl border border-[color-mix(in_oklch,var(--border)_80%,transparent)] bg-card p-3.5 shadow-(--shadow-control) sm:flex-row sm:items-start sm:justify-between"
                            >
                                <div class="min-w-0 space-y-1.5">
                                    <div
                                        class="flex flex-wrap items-center gap-1.5"
                                    >
                                        <span
                                            class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                            :class="
                                                conversationTone[
                                                    conversation.status
                                                ]
                                            "
                                            >{{
                                                conversation.status_label
                                            }}</span
                                        >
                                        <DepartmentTag
                                            v-if="conversation.department"
                                            :name="conversation.department.name"
                                            :color="
                                                conversation.department.color
                                            "
                                            :muted="
                                                !conversation.department
                                                    .is_active
                                            "
                                        />
                                        <span
                                            v-if="
                                                conversation.origin !== 'live'
                                            "
                                            class="rounded-full border px-2 py-0.5 text-xs text-muted-foreground"
                                            >{{
                                                conversation.origin_label
                                            }}</span
                                        >
                                    </div>
                                    <p class="text-sm text-foreground/90">
                                        {{
                                            conversation.assignee
                                                ? `Atendida por ${conversation.assignee.name}`
                                                : 'Sin asignar'
                                        }}
                                        <span
                                            v-if="conversation.connection"
                                            class="text-muted-foreground"
                                        >
                                            vía
                                            {{
                                                conversation.connection.name
                                            }}</span
                                        >
                                    </p>
                                    <Link
                                        v-if="conversation.history_url"
                                        :href="conversation.history_url"
                                        class="inline-flex items-center rounded-sm text-xs font-medium text-primary underline-offset-4 outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring pointer-coarse:min-h-11"
                                        >Ver en el historial importado</Link
                                    >
                                </div>
                                <div
                                    class="shrink-0 text-xs text-muted-foreground tabular-nums sm:text-right"
                                >
                                    <p>
                                        Inicio:
                                        {{
                                            formatDateTime(
                                                conversation.created_at,
                                            )
                                        }}
                                    </p>
                                    <p v-if="conversation.closed_at">
                                        Cierre:
                                        {{
                                            formatDateTime(
                                                conversation.closed_at,
                                            )
                                        }}
                                    </p>
                                    <p v-else-if="conversation.last_message_at">
                                        Último mensaje
                                        {{
                                            formatRelative(
                                                conversation.last_message_at,
                                            )
                                        }}
                                    </p>
                                </div>
                            </article>
                        </li>
                    </ol>
                    <EmptyState
                        v-else
                        class="border-none bg-transparent px-1 py-8 sm:px-2 sm:py-10"
                        :icon="MessagesSquare"
                        title="Sin conversaciones"
                        description="Este contacto aún no tiene conversaciones que puedas ver. Aparecerán aquí cuando escriba o le escribas."
                    />
                </SurfaceCard>

                <ContactNotesCard
                    v-if="can.manageNotes && notes"
                    :contact-id="contact.id"
                    :notes="notes"
                />
            </div>

            <div class="flex flex-col gap-6 2xl:col-start-3 2xl:row-start-1">
                <WhatsAppProfileCard
                    v-if="whatsappProfile"
                    :profile="whatsappProfile"
                    :reload="['contact', 'whatsappProfile']"
                    variant="page"
                />

                <ContactOriginCard
                    :current="origin.current"
                    :history="origin.history"
                    :channels="channels"
                    :connections="origin.connections"
                    :first-interaction-at="contact.first_interaction_at"
                    :can-classify="can.classifyOrigin"
                    @classify="classifyOpen = true"
                />

                <SurfaceCard title="Campos personalizados" body-class="p-5">
                    <dl
                        v-if="customFields.length > 0"
                        class="grid gap-2 text-sm"
                    >
                        <div
                            v-for="[label, value] in customFields"
                            :key="label"
                            class="rounded-lg bg-surface-sunken/60 px-3 py-2"
                        >
                            <dt class="text-xs text-muted-foreground">
                                {{ label }}
                            </dt>
                            <dd class="break-words">{{ value }}</dd>
                        </div>
                    </dl>
                    <p v-else class="text-sm text-muted-foreground">
                        Sin campos personalizados.
                    </p>
                </SurfaceCard>
            </div>
        </div>

        <ContactFormDialog
            v-if="can.update"
            v-model:open="editOpen"
            :contact="contact"
        />

        <SaveContactDialog
            v-if="contact.can?.save"
            v-model:open="saveOpen"
            :contact="contact"
            :action="saveContact(contact.id)"
            :can-add-note="can.manageNotes"
        />

        <ClassifyOriginDialog
            v-if="can.classifyOrigin"
            v-model:open="classifyOpen"
            :contact-id="contact.id"
            :origin-types="originTypes"
            :has-confirmed-origin="hasConfirmedOrigin"
        />

        <ConfirmDialog
            v-model:open="deletion.open"
            v-bind="deletion.dialog"
            @confirm="deletion.confirm"
        />
    </div>
</template>
