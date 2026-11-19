<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Lock, Pencil, StickyNote, Tags, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import ExportMenu from '@/components/ExportMenu.vue';
import InputError from '@/components/InputError.vue';
import TagPicker from '@/components/tags/TagPicker.vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime } from '@/lib/format';
import {
    destroy as destroyNote,
    store as storeNote,
    update as updateNote,
} from '@/routes/team-chat/threads/desk/notes';
import { update as updateTags } from '@/routes/team-chat/threads/desk/tags';
import type { TeamDeskDetails, TeamDeskNote } from '@/types/team-chat';

/**
 * Follow-up of a «Mesa de control» request for whoever attends the desk,
 * like the details panel of a customer conversation: tags of the catalog,
 * internal notes of the team (the requester never sees them) and the
 * request's dossier to download (PDF or Excel).
 */
const props = defineProps<{
    threadId: number;
    title: string;
    details: TeamDeskDetails;
}>();

const open = defineModel<boolean>('open', { required: true });

const NOTE_MAX = 2000;

const form = useForm({ body: '' });

function addNote(): void {
    if (form.body.trim() === '') {
        return;
    }

    form.post(storeNote.url(props.threadId), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => form.reset(),
    });
}

const editingId = ref<number | null>(null);
const editForm = useForm({ body: '' });

function startEdit(note: TeamDeskNote): void {
    editingId.value = note.id;
    editForm.body = note.body;
    editForm.clearErrors();
}

function saveEdit(note: TeamDeskNote): void {
    editForm.put(updateNote.url({ thread: props.threadId, note: note.id }), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => (editingId.value = null),
    });
}

const deleting = ref<TeamDeskNote | null>(null);
const deletingBusy = ref(false);

function confirmDelete(): void {
    const note = deleting.value;

    if (!note) {
        return;
    }

    router.delete(destroyNote.url({ thread: props.threadId, note: note.id }), {
        preserveScroll: true,
        preserveState: true,
        onStart: () => (deletingBusy.value = true),
        onFinish: () => {
            deletingBusy.value = false;
            deleting.value = null;
        },
    });
}

function submitOnCtrlEnter(event: KeyboardEvent, action: () => void): void {
    if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
        event.preventDefault();
        action();
    }
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent
            class="flex w-full flex-col gap-0 overflow-y-auto p-0 pb-[env(safe-area-inset-bottom)] sm:max-w-md"
            data-desk-follow-up
        >
            <SheetHeader class="border-b p-4">
                <SheetTitle>Seguimiento de la solicitud</SheetTitle>
                <SheetDescription>
                    {{ title }} · Solo lo ve la mesa de control.
                </SheetDescription>
            </SheetHeader>

            <div class="grid gap-6 p-4">
                <section class="grid gap-2" aria-labelledby="desk-tags-title">
                    <h3
                        id="desk-tags-title"
                        class="flex items-center gap-2 text-sm font-semibold"
                    >
                        <Tags
                            class="size-4 text-muted-foreground"
                            aria-hidden="true"
                        />
                        Etiquetas
                    </h3>
                    <TagPicker
                        :contact-id="0"
                        :selected="details.tags"
                        :available="details.available_tags"
                        :disabled="!details.can.tag"
                        :sync-url="updateTags.url(threadId)"
                        label="Editar etiquetas de la solicitud"
                    />
                    <p
                        v-if="!details.can.tag"
                        class="text-xs text-muted-foreground"
                    >
                        Tu rol no puede asignar etiquetas.
                    </p>
                </section>

                <section class="grid gap-3" aria-labelledby="desk-notes-title">
                    <h3
                        id="desk-notes-title"
                        class="flex items-center gap-2 text-sm font-semibold"
                    >
                        <StickyNote
                            class="size-4 text-muted-foreground"
                            aria-hidden="true"
                        />
                        Notas internas
                        <span
                            class="ml-auto inline-flex items-center gap-1 text-xs font-normal text-muted-foreground"
                        >
                            <Lock class="size-3" aria-hidden="true" />
                            La solicitante no las ve
                        </span>
                    </h3>

                    <form class="grid gap-2" @submit.prevent="addNote">
                        <label for="desk-note-new" class="sr-only"
                            >Nueva nota interna</label
                        >
                        <Textarea
                            id="desk-note-new"
                            v-model="form.body"
                            rows="3"
                            :maxlength="NOTE_MAX"
                            placeholder="Escribe una nota para el equipo…"
                            @keydown="submitOnCtrlEnter($event, addNote)"
                        />
                        <InputError :message="form.errors.body" />
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-xs text-muted-foreground">
                                Ctrl + Enter para guardar
                            </span>
                            <Button
                                type="submit"
                                size="sm"
                                :disabled="
                                    form.processing || form.body.trim() === ''
                                "
                            >
                                Guardar nota
                            </Button>
                        </div>
                    </form>

                    <p
                        v-if="details.notes.length === 0"
                        class="rounded-md border border-dashed p-3 text-center text-sm text-muted-foreground"
                    >
                        Aún no hay notas en esta solicitud.
                    </p>

                    <ul v-else class="grid gap-2" data-desk-notes>
                        <li
                            v-for="note in details.notes"
                            :key="note.id"
                            class="grid gap-1.5 rounded-md border border-warning/25 bg-warning-soft/40 p-3 text-sm"
                        >
                            <div
                                class="flex items-center gap-2 text-xs text-muted-foreground"
                            >
                                <span class="font-medium text-foreground">{{
                                    note.author
                                }}</span>
                                <span v-if="note.created_at">{{
                                    formatDateTime(note.created_at)
                                }}</span>
                                <span v-if="note.edited_at">· editada</span>
                                <span
                                    v-if="
                                        note.can_edit && editingId !== note.id
                                    "
                                    class="ml-auto flex gap-1"
                                >
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="size-7"
                                        aria-label="Editar nota"
                                        @click="startEdit(note)"
                                    >
                                        <Pencil class="size-3.5" />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="size-7 text-destructive"
                                        aria-label="Eliminar nota"
                                        @click="deleting = note"
                                    >
                                        <Trash2 class="size-3.5" />
                                    </Button>
                                </span>
                            </div>

                            <form
                                v-if="editingId === note.id"
                                class="grid gap-2"
                                @submit.prevent="saveEdit(note)"
                            >
                                <label
                                    :for="`desk-note-${note.id}`"
                                    class="sr-only"
                                    >Editar nota</label
                                >
                                <Textarea
                                    :id="`desk-note-${note.id}`"
                                    v-model="editForm.body"
                                    rows="3"
                                    :maxlength="NOTE_MAX"
                                    @keydown="
                                        submitOnCtrlEnter($event, () =>
                                            saveEdit(note),
                                        )
                                    "
                                />
                                <InputError :message="editForm.errors.body" />
                                <div class="flex justify-end gap-2">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        @click="editingId = null"
                                    >
                                        Cancelar
                                    </Button>
                                    <Button
                                        type="submit"
                                        size="sm"
                                        :disabled="
                                            editForm.processing ||
                                            editForm.body.trim() === ''
                                        "
                                    >
                                        Guardar
                                    </Button>
                                </div>
                            </form>
                            <p v-else class="break-words whitespace-pre-wrap">
                                {{ note.body }}
                            </p>
                        </li>
                    </ul>
                </section>

                <section
                    v-if="details.can.export"
                    class="grid gap-2 border-t pt-4"
                >
                    <h3 class="text-sm font-semibold">Reporte</h3>
                    <p class="text-xs text-muted-foreground">
                        Descarga el expediente: datos de la solicitud, notas
                        internas y mensajes.
                    </p>
                    <ExportMenu
                        report="desk-request-dossier"
                        :filters="{ thread: threadId }"
                        label="Descargar expediente"
                        heading="Expediente de la solicitud"
                    />
                </section>
            </div>
        </SheetContent>
    </Sheet>

    <ConfirmDialog
        :open="deleting !== null"
        title="¿Eliminar la nota?"
        description="Se borra para todo el equipo. Esta acción no se puede deshacer."
        confirm-label="Eliminar"
        destructive
        :processing="deletingBusy"
        @update:open="(value) => !value && (deleting = null)"
        @confirm="confirmDelete"
    />
</template>
