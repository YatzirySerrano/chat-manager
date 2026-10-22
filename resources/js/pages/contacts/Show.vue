<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    Fingerprint,
    MessagesSquare,
    Pencil,
    RotateCcw,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ClassifyOriginDialog from '@/components/contacts/ClassifyOriginDialog.vue';
import ContactFormDialog from '@/components/contacts/ContactFormDialog.vue';
import ContactNotesCard from '@/components/contacts/ContactNotesCard.vue';
import ContactOriginCard from '@/components/contacts/ContactOriginCard.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DeletedBadge from '@/components/DeletedBadge.vue';
import DeletedRecordNotice from '@/components/DeletedRecordNotice.vue';
import DepartmentTag from '@/components/DepartmentTag.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import TagPicker from '@/components/tags/TagPicker.vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { getInitials } from '@/composables/useInitials';
import { useRecordDeletion } from '@/composables/useRecordDeletion';
import { contactDeletionCopy } from '@/lib/deletionCopy';
import { formatDateTime, formatRelative } from '@/lib/format';
import { formatPhone } from '@/lib/phone';
import {
    destroy as destroyContact,
    index as contactsIndex,
    restore as restoreContact,
    show,
} from '@/routes/contacts';
import type {
    Contact,
    ContactAttribution,
    ContactConversation,
    ContactEnumOption,
    ContactNote,
    ContactOriginTypeValue,
    ContactReceivingConnection,
    ContactTag,
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

const deletion = useRecordDeletion<Contact>({
    destroy: (contact) => destroyContact(contact.id),
    restore: (contact) => restoreContact(contact.id),
    copy: contactDeletionCopy,
});

const channels = computed(() => [
    ...new Set((props.contact.identities ?? []).map((i) => i.channel_label)),
]);

const customFields = computed(() =>
    Object.entries(props.contact.custom_fields ?? {}),
);

const hasConfirmedOrigin = computed(() =>
    props.origin.history.some(
        (item) => item.reliability === 'provider_confirmed',
    ),
);

const conversationTone: Record<ContactConversation['status'], string> = {
    pending: 'bg-warning/10 text-warning',
    open: 'bg-success/10 text-success',
    closed: 'bg-muted text-muted-foreground',
};
</script>

<template>
    <Head :title="contact.name" />

    <div
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8"
    >
        <PageHeader :title="contact.name">
            <template #eyebrow>
                <div class="flex flex-wrap items-center gap-2">
                    <Avatar class="size-8 rounded-lg">
                        <AvatarFallback
                            class="rounded-lg bg-primary-soft text-xs font-semibold text-primary-soft-foreground"
                        >
                            {{ getInitials(contact.name) }}
                        </AvatarFallback>
                    </Avatar>
                    <DeletedBadge v-if="contact.is_deleted" />
                    <span class="text-xs text-muted-foreground">
                        {{ contact.source_label }}
                    </span>
                </div>
            </template>
            <template #actions>
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
                    class="text-destructive hover:text-destructive"
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

        <DeletedRecordNotice
            v-if="contact.is_deleted"
            title="Este contacto está eliminado"
            :deleted-at="contact.deleted_at"
        >
            No aparece en las listas, pero conserva todo su historial. Si vuelve
            a escribir, se sigue usando este mismo contacto.
        </DeletedRecordNotice>

        <div class="grid items-start gap-6 lg:grid-cols-3">
            <div class="flex flex-col gap-6 lg:col-span-2">
                <SurfaceCard
                    title="Historial de conversaciones"
                    description="Solo se muestran las conversaciones que tienes permitido ver."
                >
                    <ul v-if="conversations.length > 0" class="divide-y">
                        <li
                            v-for="conversation in conversations"
                            :key="conversation.id"
                            class="flex flex-col gap-2 px-5 py-3 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <div class="min-w-0 space-y-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span
                                        class="rounded-full px-2 py-0.5 text-xs font-medium"
                                        :class="
                                            conversationTone[
                                                conversation.status
                                            ]
                                        "
                                        >{{ conversation.status_label }}</span
                                    >
                                    <DepartmentTag
                                        v-if="conversation.department"
                                        :name="conversation.department.name"
                                        :color="conversation.department.color"
                                        :muted="
                                            !conversation.department.is_active
                                        "
                                    />
                                    <span
                                        v-if="conversation.connection"
                                        class="truncate text-xs text-muted-foreground"
                                        >vía
                                        {{ conversation.connection.name }}</span
                                    >
                                </div>
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        conversation.assignee
                                            ? `Atendida por ${conversation.assignee.name}`
                                            : 'Sin asignar'
                                    }}
                                </p>
                            </div>
                            <div
                                class="shrink-0 text-xs text-muted-foreground sm:text-right"
                            >
                                <p>
                                    Inicio:
                                    {{
                                        formatDateTime(conversation.created_at)
                                    }}
                                </p>
                                <p v-if="conversation.closed_at">
                                    Cierre:
                                    {{ formatDateTime(conversation.closed_at) }}
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
                        </li>
                    </ul>
                    <EmptyState
                        v-else
                        class="m-4 border-none py-10"
                        :icon="MessagesSquare"
                        title="Sin conversaciones"
                        description="Aquí aparecerán las conversaciones de WhatsApp con este contacto cuando la bandeja de atención esté disponible."
                    />
                </SurfaceCard>

                <ContactNotesCard
                    v-if="can.manageNotes && notes"
                    :contact-id="contact.id"
                    :notes="notes"
                />
            </div>

            <div class="flex flex-col gap-6">
                <SurfaceCard title="Datos de contacto" body-class="p-5">
                    <dl class="grid gap-3 text-sm">
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
                        <div class="grid grid-cols-2 gap-3">
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
                                >
                                · {{ formatDateTime(contact.created_at) }}
                            </dd>
                        </div>
                    </dl>
                </SurfaceCard>

                <SurfaceCard title="Identidades" body-class="p-5">
                    <ul
                        v-if="(contact.identities ?? []).length > 0"
                        class="space-y-2"
                    >
                        <li
                            v-for="identity in contact.identities"
                            :key="identity.id"
                            class="flex items-start gap-2 text-sm"
                        >
                            <Fingerprint
                                class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <div class="min-w-0">
                                <p class="font-medium">
                                    {{ identity.channel_label }}
                                </p>
                                <p
                                    class="truncate font-mono text-xs text-muted-foreground"
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
                    <p v-else class="text-sm text-muted-foreground">
                        Aún no se ha comunicado por ningún canal.
                    </p>
                </SurfaceCard>

                <SurfaceCard title="Etiquetas" body-class="p-5">
                    <TagPicker
                        :contact-id="contact.id"
                        :selected="contact.tags ?? []"
                        :available="availableTags"
                        :disabled="!can.assignTags"
                    />
                </SurfaceCard>

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
                        class="grid gap-3 text-sm"
                    >
                        <div
                            v-for="[label, value] in customFields"
                            :key="label"
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
