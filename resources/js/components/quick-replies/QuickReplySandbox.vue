<script setup lang="ts">
import { FlaskConical, TriangleAlert } from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import { Button } from '@/components/ui/button';
import type { AvailableQuickReply, QuickReplyVariableOption } from '@/types';
import QuickReplyPicker from './QuickReplyPicker.vue';

/**
 * Practice composer: typing "/" opens the quick reply picker exactly as the
 * inbox composer will, and the chosen reply is inserted as editable text.
 * Nothing is ever sent from here.
 */
const props = defineProps<{
    variables: QuickReplyVariableOption[];
}>();

const text = ref('');
const textarea = ref<HTMLTextAreaElement | null>(null);
const picker = ref<InstanceType<typeof QuickReplyPicker> | null>(null);
const trigger = ref<{ start: number; query: string } | null>(null);
const dismissedAt = ref<number | null>(null);
const lastInserted = ref<AvailableQuickReply | null>(null);

/**
 * Open the picker when the word under the caret starts with "/".
 */
function updateTrigger(): void {
    const element = textarea.value;

    if (!element || element.selectionStart !== element.selectionEnd) {
        trigger.value = null;

        return;
    }

    const caret = element.selectionStart;
    const match = /(^|\s)\/([a-z0-9_-]*)$/i.exec(text.value.slice(0, caret));

    if (!match) {
        trigger.value = null;
        dismissedAt.value = null;

        return;
    }

    const start = caret - match[2].length - 1;

    trigger.value =
        dismissedAt.value === start
            ? null
            : { start, query: match[2].toLowerCase() };
}

function onKeydown(event: KeyboardEvent): void {
    if (trigger.value && picker.value?.handleKeydown(event)) {
        event.preventDefault();
    }
}

function close(): void {
    dismissedAt.value = trigger.value?.start ?? null;
    trigger.value = null;
}

async function insert(reply: AvailableQuickReply): Promise<void> {
    const element = textarea.value;
    const current = trigger.value;

    if (!element || !current) {
        return;
    }

    const caret = element.selectionStart;

    text.value =
        text.value.slice(0, current.start) +
        reply.text +
        text.value.slice(caret);
    trigger.value = null;
    lastInserted.value = reply;

    await nextTick();

    const position = current.start + reply.text.length;
    element.focus();
    element.setSelectionRange(position, position);
}

const missingLabels = computed(() =>
    (lastInserted.value?.missing ?? []).map(
        (name) =>
            props.variables.find((variable) => variable.value === name)
                ?.label ?? name,
    ),
);

function reset(): void {
    text.value = '';
    trigger.value = null;
    lastInserted.value = null;
    textarea.value?.focus();
}
</script>

<template>
    <div class="grid gap-3">
        <div class="relative">
            <textarea
                ref="textarea"
                v-model="text"
                rows="4"
                role="combobox"
                aria-autocomplete="list"
                aria-label="Mensaje de prueba"
                :aria-expanded="trigger !== null"
                :aria-controls="trigger !== null ? picker?.listId : undefined"
                :aria-activedescendant="
                    trigger !== null ? picker?.activeOptionId : undefined
                "
                placeholder="Escribe «/» para buscar una respuesta rápida…"
                class="flex min-h-24 w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30"
                @input="updateTrigger"
                @click="updateTrigger"
                @keyup="
                    (event) =>
                        ['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(
                            event.key,
                        ) && updateTrigger()
                "
                @keydown="onKeydown"
                @blur="trigger = null"
            />
            <div v-if="trigger" class="absolute inset-x-0 top-full z-20 mt-1">
                <QuickReplyPicker
                    ref="picker"
                    :query="trigger.query"
                    @select="insert"
                    @close="close"
                />
            </div>
        </div>

        <div
            v-if="missingLabels.length > 0"
            class="flex gap-2 rounded-lg bg-warning/10 p-2 text-xs text-foreground"
            role="status"
        >
            <TriangleAlert
                class="mt-0.5 size-3.5 shrink-0 text-warning"
                aria-hidden="true"
            />
            <p>
                La respuesta /{{ lastInserted?.shortcut }} se insertó sin valor
                para: <strong>{{ missingLabels.join(', ') }}</strong
                >. En una conversación real se completarán con sus datos.
            </p>
        </div>

        <div
            class="flex items-center justify-between gap-2 text-xs text-muted-foreground"
        >
            <span class="inline-flex items-center gap-1.5">
                <FlaskConical class="size-3.5" aria-hidden="true" />
                Solo es una prueba: desde aquí no se envía ningún mensaje.
            </span>
            <Button
                v-if="text !== ''"
                variant="ghost"
                size="sm"
                class="h-7"
                @click="reset"
            >
                Limpiar
            </Button>
        </div>
    </div>
</template>
