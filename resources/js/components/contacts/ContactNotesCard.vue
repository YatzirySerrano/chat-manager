<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Lock, StickyNote } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import InputError from '@/components/InputError.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime, formatRelative } from '@/lib/format';
import { store } from '@/routes/contacts/notes';
import type { ContactNote } from '@/types';

const MAX_LENGTH = 2000;

const props = defineProps<{
    contactId: number;
    notes: ContactNote[];
}>();

const form = useForm({ body: '' });

function submit(): void {
    form.submit(store(props.contactId), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
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
                :aria-invalid="!!form.errors.body"
            />
            <InputError :message="form.errors.body" />
            <div class="flex items-center justify-between gap-2">
                <span
                    class="flex items-center gap-1 text-xs text-muted-foreground"
                >
                    <Lock class="size-3" aria-hidden="true" />
                    Texto plano · {{ form.body.length }} / {{ MAX_LENGTH }}
                </span>
                <Button
                    type="submit"
                    size="sm"
                    :disabled="form.processing || form.body.trim() === ''"
                >
                    <Spinner v-if="form.processing" />
                    Agregar nota
                </Button>
            </div>
        </form>

        <ol v-if="notes.length > 0" class="divide-y rounded-lg border">
            <li v-for="note in notes" :key="note.id" class="space-y-1 p-3">
                <p class="text-xs text-muted-foreground">
                    <span class="font-medium text-foreground">{{
                        note.author?.name ?? 'Usuario eliminado'
                    }}</span>
                    ·
                    <time
                        :datetime="note.created_at ?? undefined"
                        :title="formatDateTime(note.created_at)"
                        >{{ formatRelative(note.created_at) }}</time
                    >
                </p>
                <p class="text-sm break-words whitespace-pre-line">
                    {{ note.body }}
                </p>
            </li>
        </ol>
        <EmptyState
            v-else
            class="py-8"
            :icon="StickyNote"
            title="Sin notas"
            description="Agrega contexto útil para tu equipo: preferencias, acuerdos o seguimiento."
        />
    </SurfaceCard>
</template>
