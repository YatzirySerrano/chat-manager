<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ChatbotTextField from '@/components/chatbot/ChatbotTextField.vue';
import FormDialog from '@/components/FormDialog.vue';
import FormField from '@/components/FormField.vue';
import InputError from '@/components/InputError.vue';
import WhatsAppText from '@/components/WhatsAppText.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { colorPresentations } from '@/lib/colors';
import { defaultSample, renderChatbotText } from '@/lib/chatbotText';
import { store, update } from '@/routes/admin/chatbot/rules';
import type {
    ChatbotConnection,
    ChatbotDepartment,
    ChatbotKeywordRule,
    ChatbotLimits,
    ChatbotVariable,
} from '@/types';

/**
 * Create or edit a keyword rule: name, keywords as chips (Enter or comma
 * adds one), the reply with its variables and live bubble, where it
 * applies (all connections and departments unless some are chosen) and
 * the waiting time before the same rule answers the same conversation again.
 */
const props = defineProps<{
    rule: ChatbotKeywordRule | null;
    connections: ChatbotConnection[];
    departments: ChatbotDepartment[];
    variables: ChatbotVariable[];
    limits: ChatbotLimits;
    defaultThrottle: number;
}>();

const open = defineModel<boolean>('open', { required: true });

const form = useForm({
    name: '',
    keywords: [] as string[],
    reply_message: '',
    is_active: true,
    throttle_minutes: props.defaultThrottle,
    connection_ids: [] as number[],
    department_ids: [] as number[],
});

const draft = ref('');

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    draft.value = '';
    form.clearErrors();
    form.defaults({
        name: props.rule?.name ?? '',
        keywords: [...(props.rule?.keywords ?? [])],
        reply_message: props.rule?.reply_message ?? '',
        is_active: props.rule?.is_active ?? true,
        throttle_minutes: props.rule?.throttle_minutes ?? props.defaultThrottle,
        connection_ids: [...(props.rule?.connection_ids ?? [])],
        department_ids: [...(props.rule?.department_ids ?? [])],
    });
    form.reset();
});

const normalize = (value: string): string =>
    value
        .normalize('NFD')
        .replace(/\p{M}/gu, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, ' ')
        .trim();

/** Adds the keywords written so far (comma, semicolon or line separated). */
function commitDraft(): void {
    const known = new Set(form.keywords.map(normalize));

    for (const part of draft.value.split(/[,;\n]+/)) {
        const keyword = part.replace(/\s+/g, ' ').trim();

        if (
            keyword === '' ||
            known.has(normalize(keyword)) ||
            form.keywords.length >= props.limits.keywords
        ) {
            continue;
        }

        known.add(normalize(keyword));
        form.keywords.push(keyword.slice(0, props.limits.keyword));
    }

    draft.value = '';
}

function onDraftKey(event: KeyboardEvent): void {
    if (event.key === 'Enter' || event.key === ',') {
        event.preventDefault();
        commitDraft();
    } else if (
        event.key === 'Backspace' &&
        draft.value === '' &&
        form.keywords.length > 0
    ) {
        form.keywords.pop();
    }
}

function removeKeyword(index: number): void {
    form.keywords = form.keywords.filter((_, position) => position !== index);
}

function toggle(list: 'connection_ids' | 'department_ids', id: number): void {
    form[list] = form[list].includes(id)
        ? form[list].filter((item) => item !== id)
        : [...form[list], id];
}

const activeDepartments = computed(() =>
    props.departments.filter(
        (department) =>
            department.is_active || form.department_ids.includes(department.id),
    ),
);

const previewText = computed(() =>
    renderChatbotText(form.reply_message, defaultSample()),
);

function submit(): void {
    commitDraft();

    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    };

    if (props.rule) {
        form.put(update.url(props.rule.id), options);
    } else {
        form.post(store.url(), options);
    }
}
</script>

<template>
    <FormDialog
        v-model:open="open"
        :title="rule ? 'Editar palabra clave' : 'Nueva palabra clave'"
        description="Cuando un cliente escribe alguna de estas palabras, el chatbot responde solo y avisa al equipo."
        size="lg"
        :processing="form.processing"
        :dirty="form.isDirty || draft.trim() !== ''"
        :submit-label="rule ? 'Guardar cambios' : 'Crear'"
        @submit="submit"
    >
        <div class="grid gap-5">
            <div
                class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end"
            >
                <FormField
                    id="chatbot-rule-name"
                    label="Nombre"
                    :error="form.errors.name"
                >
                    <Input
                        id="chatbot-rule-name"
                        v-model="form.name"
                        :maxlength="limits.name"
                        placeholder="Horario de atención"
                    />
                </FormField>
                <label class="flex h-(--control-h) items-center gap-2 text-sm">
                    <Switch v-model="form.is_active" />
                    Activa
                </label>
            </div>

            <div class="grid gap-1.5">
                <Label for="chatbot-rule-keywords">Palabras clave</Label>
                <div
                    class="flex min-h-(--control-h) flex-wrap items-center gap-1.5 rounded-md border bg-card px-2 py-1.5 focus-within:border-primary/50 focus-within:ring-[3px] focus-within:ring-primary/20"
                    :aria-invalid="form.errors.keywords ? true : undefined"
                >
                    <span
                        v-for="(keyword, index) in form.keywords"
                        :key="keyword"
                        class="inline-flex items-center gap-1 rounded-sm bg-primary-soft py-0.5 pr-1 pl-2 text-xs font-medium text-primary-soft-foreground"
                    >
                        {{ keyword }}
                        <button
                            type="button"
                            class="grid size-4 place-items-center rounded-sm hover:bg-primary/15"
                            :aria-label="`Quitar «${keyword}»`"
                            @click="removeKeyword(index)"
                        >
                            <X class="size-3" aria-hidden="true" />
                        </button>
                    </span>
                    <input
                        id="chatbot-rule-keywords"
                        v-model="draft"
                        class="min-w-32 flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                        :placeholder="
                            form.keywords.length === 0
                                ? 'horario, a qué hora, abren…'
                                : 'Agregar otra'
                        "
                        aria-describedby="chatbot-rule-keywords-hint"
                        @keydown="onDraftKey"
                        @blur="commitDraft"
                    />
                </div>
                <p
                    id="chatbot-rule-keywords-hint"
                    class="text-xs text-muted-foreground"
                >
                    Enter o coma para agregar. Palabras completas, sin importar
                    mayúsculas ni acentos.
                </p>
                <InputError :message="form.errors.keywords" />
            </div>

            <div
                class="grid gap-4 md:grid-cols-[minmax(0,1fr)_minmax(0,16rem)]"
            >
                <ChatbotTextField
                    id="chatbot-rule-reply"
                    v-model="form.reply_message"
                    label="Respuesta"
                    :rows="4"
                    :maxlength="limits.message"
                    :variables="variables"
                    :error="form.errors.reply_message"
                    placeholder="{{saludo}}, {{nombre}}. Nuestro horario es de 9:00 a 16:00 hrs, de lunes a viernes."
                />
                <div
                    class="grid content-start gap-1.5 rounded-xl bg-surface-sunken p-3"
                    aria-label="Así se verá la respuesta"
                >
                    <span class="text-xs font-medium text-muted-foreground"
                        >Así se verá</span
                    >
                    <div
                        class="justify-self-end rounded-bubble rounded-br-sm bg-primary-soft px-3 py-2 text-sm text-primary-soft-foreground"
                    >
                        <WhatsAppText
                            v-if="previewText.trim() !== ''"
                            :text="previewText"
                        />
                        <span v-else class="text-muted-foreground italic"
                            >Sin texto</span
                        >
                    </div>
                </div>
            </div>

            <fieldset class="grid gap-2">
                <legend class="mb-1 text-sm font-medium">
                    Conexiones
                    <span class="font-normal text-muted-foreground">
                        ·
                        {{
                            form.connection_ids.length === 0
                                ? 'todas'
                                : `${form.connection_ids.length} elegidas`
                        }}</span
                    >
                </legend>
                <div class="flex flex-wrap gap-1.5">
                    <button
                        v-for="connection in connections"
                        :key="connection.id"
                        type="button"
                        class="rounded-md border px-2.5 py-1 text-xs font-medium transition-colors duration-(--duration-fast)"
                        :class="
                            form.connection_ids.includes(connection.id)
                                ? 'border-primary bg-primary-soft text-primary-soft-foreground'
                                : 'bg-card text-muted-foreground hover:border-border-strong hover:text-foreground'
                        "
                        :aria-pressed="
                            form.connection_ids.includes(connection.id)
                        "
                        @click="toggle('connection_ids', connection.id)"
                    >
                        {{ connection.name }}
                    </button>
                </div>
                <InputError :message="form.errors.connection_ids" />
            </fieldset>

            <fieldset class="grid gap-2">
                <legend class="mb-1 text-sm font-medium">
                    Departamentos
                    <span class="font-normal text-muted-foreground">
                        ·
                        {{
                            form.department_ids.length === 0
                                ? 'todos'
                                : `${form.department_ids.length} elegidos`
                        }}</span
                    >
                </legend>
                <div class="flex flex-wrap gap-1.5">
                    <button
                        v-for="department in activeDepartments"
                        :key="department.id"
                        type="button"
                        class="inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1 text-xs font-medium transition-colors duration-(--duration-fast)"
                        :class="
                            form.department_ids.includes(department.id)
                                ? 'border-primary bg-primary-soft text-primary-soft-foreground'
                                : 'bg-card text-muted-foreground hover:border-border-strong hover:text-foreground'
                        "
                        :aria-pressed="
                            form.department_ids.includes(department.id)
                        "
                        @click="toggle('department_ids', department.id)"
                    >
                        <span
                            class="size-2 rounded-full"
                            v-bind="colorPresentations(department.color).dot"
                            aria-hidden="true"
                        />
                        {{ department.name }}
                    </button>
                </div>
                <InputError :message="form.errors.department_ids" />
            </fieldset>

            <FormField
                id="chatbot-rule-throttle"
                label="Esperar antes de repetir (minutos)"
                hint="La misma regla responde a la misma conversación una vez en este lapso."
                :error="form.errors.throttle_minutes"
            >
                <Input
                    id="chatbot-rule-throttle"
                    v-model.number="form.throttle_minutes"
                    type="number"
                    min="1"
                    :max="limits.throttle"
                    class="w-32"
                />
            </FormField>
        </div>
    </FormDialog>
</template>
