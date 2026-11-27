<script setup lang="ts">
import { router, useHttp } from '@inertiajs/vue3';
import { ClipboardList, Plus, Trash2, TriangleAlert } from '@lucide/vue';
import { computed, ref, useId, watch } from 'vue';
import { toast } from 'vue-sonner';
import {
    REQUEST_FIELD_LABELS,
    REQUEST_FIELD_LABEL_MAX,
    REQUEST_FIELD_VALUE_MAX,
    REQUEST_FIELDS_MAX,
} from '@/components/conversations/requestFields';
import type { RequestFieldRow } from '@/components/conversations/requestFields';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import {
    parse as parseRoute,
    update as updateRoute,
} from '@/routes/conversations/request-fields';
import type {
    ConversationMessage,
    ConversationRequestField,
    ConversationSummary,
} from '@/types';

/**
 * «Datos de la solicitud» dialog, in two modes:
 *
 * - capture («Tomar datos de la solicitud»): the server reads the lines of
 *   one message and every line becomes a row the analyst reviews: keep it
 *   or not (checkbox), correct its value, and say what it is («Marcar
 *   como…»: a known label or any other). Saving adds the kept rows to the
 *   conversation; a field with the same label replaces the stored one.
 * - edit: the stored fields, to correct a value, remove a field (unchecked),
 *   add one by hand or «Quitar todo». Saving replaces the whole list.
 *
 * Nothing here is ever sent to the customer.
 */
const props = defineProps<{
    conversation: ConversationSummary;
    mode: 'capture' | 'edit';
    /** The message to read (capture mode). */
    message?: ConversationMessage | null;
}>();

const open = defineModel<boolean>('open', { required: true });

const listId = useId();
const http = useHttp<
    Record<string, never>,
    { fields: { label: string; key: string; value: string }[] }
>({});
const rows = ref<RequestFieldRow[]>([]);
const loading = ref(false);
const failed = ref(false);
const saving = ref(false);
const confirmClear = ref(false);
let nextId = 1;

const stored = computed<ConversationRequestField[]>(
    () => props.conversation.request_fields ?? [],
);

function normalize(label: string): string {
    return label
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[^\p{L}\p{N}]+/gu, ' ')
        .trim()
        .toLowerCase();
}

/** The stored value a row would replace (capture mode), if different. */
function replaces(row: RequestFieldRow): string | null {
    if (props.mode !== 'capture') {
        return null;
    }

    const current = stored.value.find(
        (field) => normalize(field.label) === normalize(row.label),
    );

    return current && current.value !== row.value.trim() ? current.value : null;
}

function row(
    label: string,
    value: string,
    messageId: number | null,
): RequestFieldRow {
    return {
        id: nextId++,
        selected: value.trim() !== '',
        label,
        value,
        message_id: messageId,
    };
}

async function load(): Promise<void> {
    confirmClear.value = false;
    failed.value = false;

    if (props.mode === 'edit') {
        rows.value = stored.value.map((field) => ({
            ...row(field.label, field.value, field.message_id),
            selected: true,
        }));

        return;
    }

    rows.value = [];

    if (!props.message) {
        return;
    }

    loading.value = true;

    try {
        const response = await http.get(
            parseRoute.url([props.conversation.id, props.message.id]),
        );

        rows.value = response.fields.map((field) =>
            row(field.label, field.value, props.message?.id ?? null),
        );
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
}

watch(open, (value) => {
    if (value) {
        void load();
    } else {
        http.cancel();
    }
});

function addRow(): void {
    rows.value.push({ ...row('', '', null), selected: true });
}

const kept = computed(() =>
    rows.value.filter(
        (item) =>
            item.selected &&
            item.value.trim() !== '' &&
            item.label.trim() !== '',
    ),
);

const tooMany = computed(() => kept.value.length > REQUEST_FIELDS_MAX);

const canSave = computed(
    () =>
        !saving.value &&
        !tooMany.value &&
        (props.mode === 'edit' || kept.value.length > 0),
);

function firstError(errors: Record<string, string>): string {
    return (
        Object.values(errors)[0] ??
        'No se pudieron guardar los datos. Inténtalo de nuevo.'
    );
}

function submit(fields: RequestFieldRow[]): void {
    router.put(
        updateRoute.url(props.conversation.id),
        {
            mode: props.mode === 'capture' ? 'merge' : 'replace',
            fields: fields.map((item) => ({
                label: item.label.trim(),
                value: item.value.trim(),
                message_id: item.message_id,
            })),
        },
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => (saving.value = true),
            onSuccess: () => (open.value = false),
            onError: (errors) => toast.error(firstError(errors)),
            onFinish: () => (saving.value = false),
        },
    );
}

function save(): void {
    if (canSave.value) {
        submit(kept.value);
    }
}

function clearAll(): void {
    if (!confirmClear.value) {
        confirmClear.value = true;

        return;
    }

    submit([]);
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="gap-0 p-0 sm:max-w-xl">
            <DialogHeader class="border-b p-4 text-left">
                <DialogTitle class="flex items-center gap-2 text-base">
                    <ClipboardList class="size-4" aria-hidden="true" />
                    {{
                        mode === 'capture'
                            ? 'Tomar datos de la solicitud'
                            : 'Datos de la solicitud'
                    }}
                </DialogTitle>
                <DialogDescription class="text-sm">
                    {{
                        mode === 'capture'
                            ? 'Marca los datos que quieres guardar, corrige el valor si hace falta y elige qué es cada uno. Se usan para completar las respuestas rápidas.'
                            : 'Corrige un valor, desmarca los que quieras quitar o agrega uno a mano.'
                    }}
                </DialogDescription>
            </DialogHeader>

            <div class="max-h-[60vh] overflow-y-auto p-4">
                <div
                    v-if="loading"
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <Spinner class="size-4" /> Leyendo el mensaje…
                </div>
                <p v-else-if="failed" class="text-sm text-destructive">
                    No se pudo leer el mensaje. Cierra y vuelve a intentarlo.
                </p>
                <p
                    v-else-if="rows.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    {{
                        mode === 'capture'
                            ? 'No se encontraron líneas «Etiqueta: valor» en este mensaje. Puedes agregar un dato a mano.'
                            : 'Esta conversación no tiene datos de la solicitud.'
                    }}
                </p>

                <ul v-else class="grid gap-2" aria-label="Datos encontrados">
                    <li
                        v-for="item in rows"
                        :key="item.id"
                        class="grid grid-cols-[auto_minmax(0,9rem)_minmax(0,1fr)] items-start gap-2 rounded-lg border p-2 has-[[data-state=unchecked]]:opacity-70"
                    >
                        <Checkbox
                            class="mt-2"
                            :model-value="item.selected"
                            :aria-label="`Guardar ${item.label || 'este dato'}`"
                            @update:model-value="
                                (checked) => (item.selected = checked === true)
                            "
                        />
                        <Input
                            v-model="item.label"
                            :list="listId"
                            :maxlength="REQUEST_FIELD_LABEL_MAX"
                            placeholder="Marcar como…"
                            aria-label="Qué dato es (Marcar como…)"
                            class="h-9 text-sm font-medium"
                        />
                        <div class="grid gap-1">
                            <Input
                                :model-value="item.value"
                                :maxlength="REQUEST_FIELD_VALUE_MAX"
                                placeholder="Valor"
                                :aria-label="`Valor de ${item.label || 'este dato'}`"
                                class="h-9 text-sm"
                                @update:model-value="
                                    (value) => {
                                        item.value = String(value);
                                        item.selected =
                                            item.value.trim() !== '';
                                    }
                                "
                            />
                            <p
                                v-if="item.selected && replaces(item)"
                                class="text-xs text-muted-foreground"
                            >
                                Reemplaza «{{ replaces(item) }}»
                            </p>
                        </div>
                    </li>
                </ul>

                <datalist :id="listId">
                    <option
                        v-for="label in REQUEST_FIELD_LABELS"
                        :key="label"
                        :value="label"
                    />
                </datalist>

                <Button
                    v-if="!loading"
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="mt-2"
                    @click="addRow"
                >
                    <Plus aria-hidden="true" /> Agregar un dato
                </Button>

                <p
                    v-if="tooMany"
                    class="mt-2 flex items-center gap-1.5 text-sm text-destructive"
                >
                    <TriangleAlert class="size-4" aria-hidden="true" />
                    Se guardan como máximo {{ REQUEST_FIELDS_MAX }} datos.
                </p>
            </div>

            <DialogFooter class="items-center border-t p-4">
                <Button
                    v-if="mode === 'edit' && stored.length > 0"
                    type="button"
                    variant="ghost"
                    class="text-destructive sm:mr-auto"
                    :disabled="saving"
                    @click="clearAll"
                >
                    <Trash2 aria-hidden="true" />
                    {{
                        confirmClear
                            ? '¿Quitar todos? Pulsa otra vez'
                            : 'Quitar todo'
                    }}
                </Button>
                <Button type="button" variant="outline" @click="open = false">
                    Cancelar
                </Button>
                <Button type="button" :disabled="!canSave" @click="save">
                    <Spinner v-if="saving" class="size-4" />
                    {{
                        mode === 'capture'
                            ? `Guardar ${kept.length === 1 ? '1 dato' : `${kept.length} datos`}`
                            : 'Guardar cambios'
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
