<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { LayoutTemplate, Search, TriangleAlert } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { newClientReference } from '@/components/conversations/messageFormat';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import WhatsAppText from '@/components/WhatsAppText.vue';
import {
    index as templatesIndex,
    store as storeTemplate,
} from '@/routes/conversations/templates';
import type { ConversationSummary } from '@/types';

/**
 * Sends an approved WhatsApp template, the only way to write to the customer
 * outside the 24 hour window. The list comes from the messaging module
 * (approved templates of the conversation's connection, only for whoever can
 * reply); each variable has its own field and the preview updates as it is
 * filled. Meta validates the template again; nothing here bypasses its rules.
 */
const props = defineProps<{
    conversation: ConversationSummary;
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    sent: [messageId: number];
}>();

type TemplateParameter = {
    key: string;
    component: 'header' | 'body' | 'button';
    label: string;
    kind: 'text' | 'media_url';
    example: string | null;
    max: number;
};

type TemplateOption = {
    id: number;
    name: string;
    language: string;
    category: { value: string | null; label: string | null } | null;
    structure: {
        header: { format: string; text: string | null } | null;
        body: string | null;
        footer: string | null;
        buttons: { type: string; text: string }[];
    };
    parameters: TemplateParameter[];
    sendable: boolean;
    unsupported_reason: string | null;
};

const listHttp = useHttp<Record<string, never>, { data: TemplateOption[] }>({});
const sendHttp = useHttp<
    {
        template_id: number | null;
        client_reference: string;
        parameters: Record<string, string>;
    },
    { data: { id: number } }
>({
    template_id: null,
    client_reference: '',
    parameters: {},
});

const templates = ref<TemplateOption[]>([]);
const loading = ref(false);
const loadError = ref<string | null>(null);
const search = ref('');
const selectedId = ref<number | null>(null);
const values = ref<Record<string, string>>({});
const fieldErrors = ref<Record<string, string>>({});
const sendError = ref<string | null>(null);
let clientReference = newClientReference();

const selected = computed(
    () =>
        templates.value.find((template) => template.id === selectedId.value) ??
        null,
);

watch(selectedId, () => {
    values.value = Object.fromEntries(
        (selected.value?.parameters ?? []).map((parameter) => [
            parameter.key,
            '',
        ]),
    );
    fieldErrors.value = {};
    sendError.value = null;
    clientReference = newClientReference();
});

/** Replaces {{name}} of one component with the typed values. */
function fill(text: string | null, component: string): string {
    if (!text) {
        return '';
    }

    return text.replace(/\{\{\s*([\w.]+)\s*\}\}/g, (whole, name: string) => {
        const value = values.value[`${component}.${name}`]?.trim();

        return value ? value : whole;
    });
}

const preview = computed(() => {
    const template = selected.value;

    if (!template) {
        return null;
    }

    const header = template.structure.header;

    return {
        header:
            header === null
                ? null
                : header.format === 'TEXT'
                  ? fill(header.text, 'header')
                  : `[${header.format === 'IMAGE' ? 'Imagen' : header.format === 'VIDEO' ? 'Video' : 'Documento'}]`,
        body: fill(template.structure.body, 'body'),
        footer: template.structure.footer,
        buttons: template.structure.buttons.map((button) => button.text),
    };
});

const missing = computed(() =>
    (selected.value?.parameters ?? []).some(
        (parameter) => (values.value[parameter.key] ?? '').trim() === '',
    ),
);

const canSend = computed(
    () =>
        selected.value !== null &&
        selected.value.sendable &&
        !missing.value &&
        !sendHttp.processing,
);

const filtered = computed(() => {
    const term = search.value.trim().toLocaleLowerCase('es');

    return term === ''
        ? templates.value
        : templates.value.filter(
              (template) =>
                  template.name.toLocaleLowerCase('es').includes(term) ||
                  (template.structure.body ?? '')
                      .toLocaleLowerCase('es')
                      .includes(term),
          );
});

async function load(): Promise<void> {
    loading.value = true;
    loadError.value = null;

    try {
        const response = await listHttp.get(
            templatesIndex.url(props.conversation.id),
        );
        templates.value = response.data;
        selectedId.value =
            templates.value.find((template) => template.sendable)?.id ??
            templates.value[0]?.id ??
            null;
    } catch {
        loadError.value =
            'No se pudieron cargar las plantillas aprobadas. Inténtalo de nuevo.';
    } finally {
        loading.value = false;
    }
}

watch(open, (isOpen) => {
    if (isOpen) {
        search.value = '';
        sendError.value = null;
        void load();
    }
});

function responseDetail(failure: unknown): {
    status: number | null;
    message: string | null;
} {
    if (
        typeof failure !== 'object' ||
        failure === null ||
        !('response' in failure)
    ) {
        return { status: null, message: null };
    }

    const response = (failure as { response: { status: number; data: string } })
        .response;

    try {
        return {
            status: response.status,
            message:
                (JSON.parse(response.data) as { message?: string }).message ??
                null,
        };
    } catch {
        return { status: response.status, message: null };
    }
}

async function send(): Promise<void> {
    if (!selected.value || !canSend.value) {
        return;
    }

    sendError.value = null;
    fieldErrors.value = {};
    sendHttp.template_id = selected.value.id;
    sendHttp.client_reference = clientReference;
    sendHttp.parameters = Object.fromEntries(
        Object.entries(values.value).map(([key, value]) => [key, value.trim()]),
    );

    try {
        let rejected = false;
        const response = await sendHttp.post(
            storeTemplate.url(props.conversation.id),
            {
                onError: (errors) => {
                    rejected = true;
                    const bag = errors as Record<string, string>;

                    fieldErrors.value = Object.fromEntries(
                        Object.entries(bag)
                            .filter(([key]) => key.startsWith('parameters.'))
                            .map(([key, value]) => [
                                key.slice('parameters.'.length),
                                value,
                            ]),
                    );
                    sendError.value =
                        Object.values(bag)[0] ??
                        'Revisa los datos de la plantilla.';
                },
            },
        );

        if (rejected) {
            clientReference = newClientReference();

            return;
        }

        if (response) {
            emit('sent', response.data.id);
            open.value = false;
            clientReference = newClientReference();
        }
    } catch (failure) {
        const detail = responseDetail(failure);

        sendError.value =
            detail.message ??
            (detail.status !== null
                ? 'No se pudo enviar la plantilla.'
                : 'No hay conexión con el servidor. Inténtalo de nuevo: la plantilla no se duplicará.');

        if (
            detail.status !== null &&
            detail.status >= 400 &&
            detail.status < 500
        ) {
            clientReference = newClientReference();
        }
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-3xl">
            <DialogHeader>
                <DialogTitle>Enviar una plantilla aprobada</DialogTitle>
                <DialogDescription>
                    Fuera de la ventana de 24 horas WhatsApp solo permite
                    plantillas aprobadas por Meta. Meta puede cobrar por cada
                    conversación iniciada con una plantilla.
                </DialogDescription>
            </DialogHeader>

            <div
                v-if="loading"
                class="flex items-center justify-center gap-2 py-10 text-sm text-muted-foreground"
            >
                <Spinner /> Cargando plantillas…
            </div>
            <div
                v-else-if="loadError"
                class="grid justify-items-center gap-3 py-8 text-sm"
            >
                <p class="text-destructive">{{ loadError }}</p>
                <Button variant="outline" @click="load">Reintentar</Button>
            </div>
            <div
                v-else-if="templates.length === 0"
                class="grid justify-items-center gap-2 py-8 text-center text-sm text-muted-foreground"
            >
                <LayoutTemplate class="size-8" aria-hidden="true" />
                <p>
                    No hay plantillas aprobadas para esta conexión. Un
                    administrador puede sincronizarlas desde Plantillas.
                </p>
            </div>
            <div v-else class="grid gap-4 md:grid-cols-[16rem_minmax(0,1fr)]">
                <div class="grid content-start gap-2">
                    <div class="relative">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <Input
                            v-model="search"
                            type="search"
                            class="h-9 pl-8"
                            placeholder="Buscar plantilla"
                            aria-label="Buscar plantilla"
                        />
                    </div>
                    <ul
                        class="max-h-72 overflow-y-auto rounded-lg border p-1"
                        role="listbox"
                        aria-label="Plantillas aprobadas"
                    >
                        <li
                            v-for="template in filtered"
                            :key="template.id"
                            role="option"
                            :aria-selected="template.id === selectedId"
                            tabindex="0"
                            class="cursor-pointer rounded-md px-2.5 py-2 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            :class="
                                template.id === selectedId
                                    ? 'bg-accent'
                                    : 'hover:bg-accent/60'
                            "
                            @click="selectedId = template.id"
                            @keydown.enter.prevent="selectedId = template.id"
                            @keydown.space.prevent="selectedId = template.id"
                        >
                            <span class="block truncate font-medium">{{
                                template.name
                            }}</span>
                            <span class="text-xs text-muted-foreground">
                                {{ template.language }}
                                <template v-if="template.category?.label">
                                    · {{ template.category.label }}</template
                                >
                                <template v-if="!template.sendable">
                                    · no disponible</template
                                >
                            </span>
                        </li>
                        <li
                            v-if="filtered.length === 0"
                            class="px-2 py-4 text-center text-xs text-muted-foreground"
                        >
                            Ninguna plantilla coincide.
                        </li>
                    </ul>
                </div>

                <div
                    v-if="selected && preview"
                    class="grid content-start gap-4"
                >
                    <p
                        v-if="!selected.sendable"
                        class="flex items-start gap-1.5 rounded-md bg-warning/10 px-3 py-2 text-xs"
                    >
                        <TriangleAlert
                            class="mt-0.5 size-3.5 shrink-0 text-warning"
                            aria-hidden="true"
                        />
                        {{
                            selected.unsupported_reason ??
                            'Esta plantilla no se puede enviar desde la bandeja.'
                        }}
                    </p>
                    <form
                        v-if="selected.parameters.length > 0"
                        class="grid gap-3"
                        novalidate
                        @submit.prevent="send"
                    >
                        <div
                            v-for="parameter in selected.parameters"
                            :key="parameter.key"
                            class="grid gap-1.5"
                        >
                            <Label :for="`template-${parameter.key}`">{{
                                parameter.label
                            }}</Label>
                            <Input
                                :id="`template-${parameter.key}`"
                                v-model="values[parameter.key]"
                                :type="
                                    parameter.kind === 'media_url'
                                        ? 'url'
                                        : 'text'
                                "
                                :maxlength="parameter.max"
                                :placeholder="parameter.example ?? undefined"
                                :aria-invalid="!!fieldErrors[parameter.key]"
                                required
                            />
                            <InputError :message="fieldErrors[parameter.key]" />
                        </div>
                    </form>
                    <p v-else class="text-sm text-muted-foreground">
                        Esta plantilla no tiene variables.
                    </p>

                    <div>
                        <p
                            class="mb-1.5 text-xs font-medium text-muted-foreground"
                        >
                            Vista previa
                        </p>
                        <div
                            class="ml-auto max-w-sm rounded-2xl rounded-br-md bg-primary-soft px-3 py-2 text-sm text-primary-soft-foreground"
                        >
                            <p v-if="preview.header" class="mb-1 font-semibold">
                                {{ preview.header }}
                            </p>
                            <WhatsAppText :text="preview.body" />
                            <p
                                v-if="preview.footer"
                                class="mt-1 text-xs text-muted-foreground"
                            >
                                {{ preview.footer }}
                            </p>
                            <div
                                v-if="preview.buttons.length > 0"
                                class="mt-2 grid gap-1 border-t pt-2"
                            >
                                <span
                                    v-for="button in preview.buttons"
                                    :key="button"
                                    class="text-center text-xs font-medium"
                                    >{{ button }}</span
                                >
                            </div>
                        </div>
                    </div>
                    <p
                        v-if="missing"
                        class="flex items-center gap-1.5 text-xs text-muted-foreground"
                    >
                        <TriangleAlert
                            class="size-3.5 text-warning"
                            aria-hidden="true"
                        />
                        Completa todas las variables para enviar.
                    </p>
                </div>
            </div>

            <InputError :message="sendError ?? undefined" />

            <DialogFooter class="gap-2">
                <Button variant="outline" @click="open = false"
                    >Cancelar</Button
                >
                <Button :disabled="!canSend" @click="send">
                    <Spinner v-if="sendHttp.processing" />
                    Enviar plantilla
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
