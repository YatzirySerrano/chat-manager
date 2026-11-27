<script setup lang="ts">
import { ChevronUp, ClipboardList, Pencil } from '@lucide/vue';
import { useElementSize } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import RequestFieldsDialog from '@/components/conversations/RequestFieldsDialog.vue';
import { copyRequestField } from '@/components/conversations/requestFields';
import { Button } from '@/components/ui/button';
import type { ConversationMessage, ConversationSummary } from '@/types';

/**
 * «Datos de la solicitud» under the chat header: one line of chips
 * («Producto: S12», «Cliente: …») with a «+N» that unfolds the rest, so the
 * data is at hand without taking over the chat. A chip copies its value.
 * Nothing is shown while the conversation has no data.
 *
 * It also owns the capture dialog: the message bubbles reach it through
 * ConversationChat (provideRequestCapture) with `capture(message)`.
 */
const props = defineProps<{
    conversation: ConversationSummary;
}>();

const fields = computed(() => props.conversation.request_fields ?? []);
const canEdit = computed(() => props.conversation.can.categorize === true);

const expanded = ref(false);
const strip = ref<HTMLElement | null>(null);
const { width } = useElementSize(strip);

/* Chips that fit on one line, roughly (a chip is about 11rem wide). */
const visibleCount = computed(() =>
    expanded.value
        ? fields.value.length
        : Math.max(1, Math.min(8, Math.floor((width.value - 160) / 176))),
);
const hidden = computed(() =>
    Math.max(0, fields.value.length - visibleCount.value),
);

watch(
    () => props.conversation.id,
    () => (expanded.value = false),
);

const dialogOpen = ref(false);
const dialogMode = ref<'capture' | 'edit'>('edit');
const dialogMessage = ref<ConversationMessage | null>(null);

function capture(message: ConversationMessage): void {
    dialogMode.value = 'capture';
    dialogMessage.value = message;
    dialogOpen.value = true;
}

function edit(): void {
    dialogMode.value = 'edit';
    dialogMessage.value = null;
    dialogOpen.value = true;
}

defineExpose({ capture });
</script>

<template>
    <div
        v-if="fields.length > 0"
        ref="strip"
        class="flex items-start gap-2 border-t border-border/60 px-3 py-1.5 sm:px-4"
        data-request-fields
    >
        <span
            class="mt-1 inline-flex shrink-0 items-center gap-1 text-xs font-medium text-muted-foreground"
            title="Datos de la solicitud"
        >
            <ClipboardList class="size-3.5" aria-hidden="true" />
            <span class="hidden sm:inline">Solicitud</span>
        </span>
        <ul
            class="flex min-w-0 flex-1 gap-1"
            :class="expanded ? 'flex-wrap' : 'flex-nowrap overflow-hidden'"
            aria-label="Datos de la solicitud"
        >
            <li
                v-for="field in fields.slice(0, visibleCount)"
                :key="field.key"
                class="min-w-0 shrink"
            >
                <button
                    type="button"
                    class="inline-flex max-w-44 items-center gap-1 rounded-full border bg-muted/50 px-2 py-0.5 text-xs transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:max-w-64"
                    :title="`${field.label}: ${field.value} (clic para copiar)`"
                    @click="copyRequestField(field)"
                >
                    <span class="shrink-0 text-muted-foreground"
                        >{{ field.label }}:</span
                    >
                    <span class="truncate font-medium">{{ field.value }}</span>
                </button>
            </li>
            <li v-if="hidden > 0" class="shrink-0">
                <button
                    type="button"
                    class="rounded-full border px-2 py-0.5 text-xs font-medium text-muted-foreground hover:bg-accent"
                    :aria-label="`Ver ${hidden} datos más`"
                    @click="expanded = true"
                >
                    +{{ hidden }}
                </button>
            </li>
        </ul>
        <Button
            v-if="expanded"
            type="button"
            variant="ghost"
            size="icon"
            class="size-6 shrink-0"
            title="Mostrar en una línea"
            @click="expanded = false"
        >
            <ChevronUp class="size-3.5" aria-hidden="true" />
            <span class="sr-only">Mostrar en una línea</span>
        </Button>
        <Button
            v-if="canEdit"
            type="button"
            variant="ghost"
            size="icon"
            class="size-6 shrink-0"
            title="Editar los datos de la solicitud"
            @click="edit"
        >
            <Pencil class="size-3.5" aria-hidden="true" />
            <span class="sr-only">Editar los datos de la solicitud</span>
        </Button>
    </div>

    <RequestFieldsDialog
        v-model:open="dialogOpen"
        :conversation="conversation"
        :mode="dialogMode"
        :message="dialogMessage"
    />
</template>
