<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Lock, StickyNote } from '@lucide/vue';
import DirectoryAvatar from '@/components/directory/DirectoryAvatar.vue';
import EmptyState from '@/components/EmptyState.vue';
import InputError from '@/components/InputError.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { useFormValidation } from '@/composables/useFormValidation';
import { formatDateTime, formatRelative } from '@/lib/format';
import { maxLength, required } from '@/lib/validation';
import { store } from '@/routes/contacts/notes';
import type { ContactNote } from '@/types';

const MAX_LENGTH = 2000;

const props = defineProps<{
    contactId: number;
    notes: ContactNote[];
}>();

const form = useForm({ body: '' });

const validation = useFormValidation(form, {
    rules: { body: [required('Escribe la nota.'), maxLength(MAX_LENGTH)] },
});

function submit(): void {
    if (!validation.validate()) {
        return;
    }

    form.submit(store(props.contactId), {
        preserveScroll: true,
        onSuccess: () => validation.load({ body: '' }),
    });
}
</script>

<template>
    <SurfaceCard
        title="Notas privadas"
        description="Solo las ven las personas con permiso para notas de este contacto. El cliente nunca las recibe."
        body-class="space-y-4 p-5"
    >
        <form class="grid gap-2" novalidate @submit.prevent="submit">
            <label for="contact-note" class="sr-only">Nueva nota</label>
            <Textarea
                id="contact-note"
                v-model="form.body"
                rows="3"
                :maxlength="MAX_LENGTH"
                placeholder="Escribe una nota interna sobre este contacto…"
                v-bind="validation.field('body', 'contact-note')"
            />
            <InputError
                id="contact-note-error"
                :message="validation.error('body')"
            />
            <div class="flex items-center justify-between gap-2">
                <span
                    class="flex items-center gap-1 text-xs text-muted-foreground"
                >
                    <Lock class="size-3" aria-hidden="true" />
                    Texto plano, {{ form.body.length }} / {{ MAX_LENGTH }}
                </span>
                <Button
                    type="submit"
                    size="sm"
                    class="rounded-full"
                    :disabled="form.body.trim() === ''"
                    :loading="form.processing"
                >
                    Agregar nota
                </Button>
            </div>
        </form>

        <ol v-if="notes.length > 0" class="dir-timeline" aria-label="Notas">
            <li
                v-for="(note, position) in notes"
                :key="note.id"
                class="dir-timeline-item enter-fade"
                :style="{ '--i': Math.min(position, 8) }"
            >
                <DirectoryAvatar
                    :name="note.author?.name ?? '?'"
                    :tone="note.author ? 'auto' : 'muted'"
                    size="sm"
                    class="absolute top-2.5 left-0 size-6 text-[0.6rem] ring-4 ring-card"
                />
                <article
                    class="rounded-xl border border-[color-mix(in_oklch,var(--border)_80%,transparent)] bg-[color-mix(in_oklch,var(--warning)_5%,var(--card))] p-3 shadow-(--shadow-control)"
                >
                    <p
                        class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 text-xs text-muted-foreground"
                    >
                        <span class="font-semibold text-foreground">{{
                            note.author?.name ?? 'Usuario eliminado'
                        }}</span>
                        <time
                            :datetime="note.created_at ?? undefined"
                            :title="formatDateTime(note.created_at)"
                            >{{ formatRelative(note.created_at) }}</time
                        >
                    </p>
                    <p
                        class="mt-1 text-sm leading-relaxed break-words whitespace-pre-line"
                    >
                        {{ note.body }}
                    </p>
                </article>
            </li>
        </ol>
        <EmptyState
            v-else
            class="border-none bg-transparent px-0 py-6 sm:px-0 sm:py-6"
            :icon="StickyNote"
            title="Sin notas"
            description="Agrega contexto útil para tu equipo: preferencias, acuerdos o seguimiento."
        />
    </SurfaceCard>
</template>
