<script setup lang="ts">
import { ClipboardList, Copy, Pencil } from '@lucide/vue';
import { computed, ref } from 'vue';
import RequestFieldsDialog from '@/components/conversations/RequestFieldsDialog.vue';
import {
    copyRequestField,
    displayRequestFieldValue,
    RESOLUTION_KEYS,
} from '@/components/conversations/requestFields';
import RequestResolutionFields from '@/components/conversations/RequestResolutionFields.vue';
import { Button } from '@/components/ui/button';
import type { ConversationSummary } from '@/types';

/**
 * «Datos de la solicitud» in the details panel: every field kept on the
 * conversation, each one copyable, and «Editar» (correct, remove, add,
 * «Quitar todo») for whoever may keep request data (can.categorize). The
 * amounts of the resolution are typed inline in «Resolución» below
 * (RequestResolutionFields), so they are not repeated in the list.
 */
const props = defineProps<{
    conversation: ConversationSummary;
}>();

const allFields = computed(() => props.conversation.request_fields ?? []);
const fields = computed(() =>
    allFields.value.filter((field) => !RESOLUTION_KEYS.has(field.key)),
);
const canEdit = computed(() => props.conversation.can.categorize === true);
const editing = ref(false);
</script>

<template>
    <section
        class="grid min-w-0 grid-cols-1 gap-2.5 rounded-xl border bg-card px-4 pt-4 pb-5 text-sm"
        data-request-fields-details
    >
        <div class="flex items-center justify-between gap-2">
            <h3 class="flex items-center gap-1.5 section-title">
                <ClipboardList class="size-3.5" aria-hidden="true" />
                Datos de la solicitud
            </h3>
            <Button
                v-if="canEdit"
                type="button"
                variant="ghost"
                size="sm"
                class="h-7 shrink-0 px-2 text-xs"
                @click="editing = true"
            >
                <Pencil class="size-3.5" aria-hidden="true" />
                {{ allFields.length > 0 ? 'Editar' : 'Agregar' }}
            </Button>
        </div>

        <dl
            v-if="fields.length > 0"
            class="grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1.5"
        >
            <template v-for="field in fields" :key="field.key">
                <dt class="text-xs text-muted-foreground">{{ field.label }}</dt>
                <dd class="min-w-0 font-medium break-words">
                    {{ displayRequestFieldValue(field) }}
                </dd>
                <dd class="shrink-0">
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="size-6"
                        :title="`Copiar ${field.label}`"
                        @click="copyRequestField(field)"
                    >
                        <Copy class="size-3.5" aria-hidden="true" />
                        <span class="sr-only">Copiar {{ field.label }}</span>
                    </Button>
                </dd>
            </template>
        </dl>
        <p v-else class="text-sm text-muted-foreground">
            Sin datos. En un mensaje del gestor usa «Tomar datos de la
            solicitud» para guardarlos y completar las respuestas rápidas.
        </p>

        <RequestResolutionFields :conversation="conversation" />

        <RequestFieldsDialog
            v-if="canEdit"
            v-model:open="editing"
            :conversation="conversation"
            mode="edit"
        />
    </section>
</template>
