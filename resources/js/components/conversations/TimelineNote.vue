<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Lock, Pencil } from '@lucide/vue';
import { computed, ref } from 'vue';
import LinkPreviewCard from '@/components/chat/LinkPreviewCard.vue';
import { formatTime } from '@/components/conversations/messageFormat';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import WhatsAppText from '@/components/WhatsAppText.vue';
import { useFormValidation } from '@/composables/useFormValidation';
import { formatDateTime } from '@/lib/format';
import { firstUrl } from '@/lib/links';
import '@/components/chat/chat.css';
import { maxLength, required } from '@/lib/validation';
import { update as updateNote } from '@/routes/conversations/notes';
import type { ConversationNote } from '@/types';

/**
 * An internal note inside the chat. It is laid out as a card across the
 * timeline (not as a bubble), in the note color, with a hatched edge and an
 * explicit label, so it can never be mistaken for something the customer
 * received.
 */
const props = defineProps<{
    note: ConversationNote;
    conversationId: number;
    maxLength: number;
}>();

const emit = defineEmits<{
    saved: [noteId: number];
}>();

const editing = ref(false);
/* The first link of the note gets a preview card under it. */
const link = computed(() => firstUrl(props.note.body));
const form = useForm({ body: '' });
const validation = useFormValidation(form, {
    rules: {
        body: [required('Escribe la nota.'), maxLength(props.maxLength)],
    },
    requireChanges: true,
});

function startEditing(): void {
    validation.load({ body: props.note.body });
    editing.value = true;
}

function save(): void {
    if (!validation.validate()) {
        return;
    }

    form.put(
        updateNote.url({
            conversation: props.conversationId,
            note: props.note.id,
        }),
        {
            preserveScroll: true,
            preserveState: true,
            only: ['flash'],
            onSuccess: () => {
                editing.value = false;
                emit('saved', props.note.id);
            },
        },
    );
}
</script>

<template>
    <article
        data-layout-box="note"
        class="chat-note ml-auto w-full max-w-[min(85%,44rem)] rounded-2xl border border-warning/45 bg-warning-soft py-2.5 pr-3 pl-4.5 text-sm text-warning-soft-foreground"
        :aria-label="`Nota interna de ${note.author?.name ?? 'usuario eliminado'}`"
    >
        <header
            class="mb-1.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs font-semibold"
        >
            <span
                class="inline-flex items-center gap-1 rounded-full bg-warning px-2 py-0.5 text-background shadow-(--shadow-control)"
            >
                <Lock class="size-3" aria-hidden="true" />
                Nota interna
            </span>
            <span class="text-warning-soft-foreground/80"
                >— no se envía al cliente</span
            >
        </header>
        <form
            v-if="editing"
            class="grid gap-2"
            novalidate
            @submit.prevent="save"
        >
            <label :for="`timeline-note-${note.id}`" class="sr-only"
                >Editar nota interna</label
            >
            <Textarea
                :id="`timeline-note-${note.id}`"
                v-model="form.body"
                rows="3"
                :maxlength="maxLength"
                v-bind="validation.field('body', `timeline-note-${note.id}`)"
            />
            <InputError
                :id="`timeline-note-${note.id}-error`"
                :message="validation.error('body')"
            />
            <div class="flex justify-end gap-2">
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    @click="editing = false"
                    >Cancelar</Button
                >
                <Button
                    type="submit"
                    size="sm"
                    :disabled="form.processing || !validation.hasChanges"
                    :loading="form.processing"
                >
                    Guardar
                </Button>
            </div>
        </form>
        <template v-else>
            <WhatsAppText
                :text="note.body"
                links
                plain
                class="block wrap-anywhere whitespace-pre-line text-foreground"
            />
            <LinkPreviewCard
                v-if="link"
                :url="link"
                :endpoint="note.link_preview_url ?? null"
                class="mt-1.5 text-foreground"
            />
            <footer
                class="mt-1 flex items-center justify-between gap-2 text-xs text-muted-foreground"
            >
                <span class="min-w-0 wrap-anywhere">
                    {{ note.author?.name ?? 'Usuario eliminado' }} ·
                    <time
                        :datetime="note.created_at ?? undefined"
                        :title="formatDateTime(note.created_at)"
                        >{{ formatTime(note.created_at) }}</time
                    >
                    <template v-if="note.edited_at"> · editada</template>
                </span>
                <Button
                    v-if="note.can.update"
                    variant="ghost"
                    size="icon-sm"
                    class="size-7 shrink-0 pointer-coarse:size-10"
                    aria-label="Editar nota interna"
                    title="Editar nota interna"
                    @click="startEditing"
                >
                    <Pencil aria-hidden="true" />
                </Button>
            </footer>
        </template>
    </article>
</template>
