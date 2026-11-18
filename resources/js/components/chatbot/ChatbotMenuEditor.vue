<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    CornerDownRight,
    MessageSquareReply,
    Plus,
    Trash2,
    TriangleAlert,
} from '@lucide/vue';
import type { FunctionalComponent } from 'vue';
import { computed, h, ref } from 'vue';
import ChatbotPhonePreview from '@/components/chatbot/ChatbotPhonePreview.vue';
import type { ChatbotPreviewMessage } from '@/components/chatbot/ChatbotPhonePreview.vue';
import ChatbotTextField from '@/components/chatbot/ChatbotTextField.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { AppSelect } from '@/components/ui/app-select';
import type { AppSelectOption } from '@/components/ui/app-select';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { colorPresentations } from '@/lib/colors';
import { defaultSample, renderChatbotText } from '@/lib/chatbotText';
import { update as updateMenu } from '@/routes/admin/chatbot/menus';
import type {
    AppColor,
    ChatbotConnection,
    ChatbotDepartment,
    ChatbotLimits,
    ChatbotMenuOption,
    ChatbotVariable,
} from '@/types';

/**
 * The welcome menu of one connection: on/off, greeting, the ordered options
 * (department, optional text and reply) and what happens with an answer
 * that is not an option, next to a live preview of the conversation.
 */
const props = defineProps<{
    connection: ChatbotConnection;
    departments: ChatbotDepartment[];
    variables: ChatbotVariable[];
    limits: ChatbotLimits;
    defaultRetry: string;
}>();

type OptionRow = ChatbotMenuOption & { key: number; showReply: boolean };

let nextKey = 0;

const toRow = (option: ChatbotMenuOption): OptionRow => ({
    ...option,
    key: nextKey++,
    showReply: option.reply_message.trim() !== '',
});

const form = useForm({
    is_enabled: props.connection.menu.is_enabled,
    greeting: props.connection.menu.greeting,
    retry_message: props.connection.menu.retry_message,
    max_attempts: props.connection.menu.max_attempts,
    options: props.connection.menu.options.map(toRow),
});

const canUpdate = computed(() => props.connection.can.update);

const departmentsById = computed(
    () => new Map(props.departments.map((item) => [item.id, item])),
);

/** Whether a department can receive this connection's conversations now. */
function routable(department: ChatbotDepartment | undefined): boolean {
    return (
        department !== undefined &&
        department.is_active &&
        (props.connection.department_ids.length === 0 ||
            props.connection.department_ids.includes(department.id))
    );
}

/** Small color dot, used as the icon of a department in the selects. */
function dot(color: AppColor): FunctionalComponent {
    const presentation = colorPresentations(color).dot;

    return (_, { attrs }) =>
        h('span', {
            ...attrs,
            ...presentation,
            class: [
                'inline-block size-2.5! shrink-0 self-center rounded-full',
                attrs.class,
                presentation.class,
            ],
        });
}

function departmentOptions(current?: number): AppSelectOption<number>[] {
    const taken = new Set(form.options.map((option) => option.department_id));

    return props.departments
        .filter(
            (department) =>
                department.id === current ||
                (!taken.has(department.id) && routable(department)),
        )
        .map((department) => ({
            value: department.id,
            label: department.name,
            icon: dot(department.color),
            description: !department.is_active
                ? 'Inactivo: se omite del menú'
                : !routable(department)
                  ? 'No autorizado para esta conexión: se omite'
                  : undefined,
        }));
}

const addable = computed(() => departmentOptions());
const adding = ref<number | null>(null);

function addOption(id: number | null | undefined): void {
    if (
        id === null ||
        id === undefined ||
        form.options.length >= props.limits.options
    ) {
        return;
    }

    form.options.push(
        toRow({ department_id: id, label: '', reply_message: '' }),
    );
    adding.value = null;
}

function move(index: number, offset: -1 | 1): void {
    const target = index + offset;

    if (target < 0 || target >= form.options.length) {
        return;
    }

    const rows = [...form.options];
    [rows[index], rows[target]] = [rows[target], rows[index]];
    form.options = rows;
}

function remove(index: number): void {
    form.options = form.options.filter((_, position) => position !== index);
}

const attemptOptions = computed<AppSelectOption<number>[]>(() =>
    Array.from({ length: props.limits.attempts + 1 }, (_, count) => ({
        value: count,
        label:
            count === 0
                ? 'Sin reintentos'
                : count === 1
                  ? '1 reintento'
                  : `${count} reintentos`,
    })),
);

function optionLabel(option: ChatbotMenuOption): string {
    return (
        option.label.trim() ||
        departmentsById.value.get(option.department_id)?.name ||
        'Departamento'
    );
}

/* Preview: only the options a customer would see, renumbered (as the server does). */
const visibleOptions = computed(() =>
    form.options.filter((option) =>
        routable(departmentsById.value.get(option.department_id)),
    ),
);

const answer = ref<number | 'other'>(1);

const sample = computed(() =>
    defaultSample({ conexion: props.connection.name }),
);

const preview = computed<ChatbotPreviewMessage[]>(() => {
    const list = visibleOptions.value
        .map((option, index) => `${index + 1} - ${optionLabel(option)}`)
        .join('\n');
    const greeting = form.greeting.trim();
    const messages: ChatbotPreviewMessage[] = [
        { key: 'hello', from: 'customer', text: 'Hola' },
        {
            key: 'menu',
            from: 'bot',
            label: 'Menú de bienvenida',
            text: renderChatbotText(
                [greeting, list].filter(Boolean).join('\n\n'),
                sample.value,
            ),
            muted: !form.is_enabled,
        },
    ];

    if (visibleOptions.value.length === 0) {
        return messages;
    }

    const choice =
        answer.value === 'other'
            ? null
            : (visibleOptions.value[answer.value - 1] ?? null);

    messages.push({
        key: 'answer',
        from: 'customer',
        text: choice === null ? 'quiero informes' : String(answer.value),
    });

    if (choice === null) {
        messages.push({
            key: 'retry',
            from: 'bot',
            label:
                form.max_attempts > 0
                    ? 'Reintento'
                    : 'Sin reintentos: pasa a la cola normal',
            text:
                form.max_attempts > 0
                    ? `${form.retry_message.trim() || props.defaultRetry}\n\n${list}`
                    : '',
            muted: form.max_attempts === 0,
        });

        return messages;
    }

    const department = departmentsById.value.get(choice.department_id);
    const reply = choice.reply_message.trim();

    messages.push({
        key: `reply-${choice.key}`,
        from: 'bot',
        label: reply
            ? 'Respuesta de la opción'
            : department?.has_welcome
              ? 'Bienvenida del departamento'
              : 'Sin respuesta',
        text: reply
            ? renderChatbotText(
                  reply,
                  defaultSample({
                      conexion: props.connection.name,
                      departamento: department?.name ?? '',
                  }),
              )
            : department?.has_welcome
              ? `_(La bienvenida configurada en ${department.name})_`
              : '',
        muted: !reply,
    });

    return messages;
});

const answerChoices = computed(() => [
    ...visibleOptions.value.map((_, index) => ({
        value: (index + 1) as number | 'other',
        label: String(index + 1),
    })),
    { value: 'other' as const, label: 'Otra cosa' },
]);

const skipped = computed(
    () => form.options.length - visibleOptions.value.length,
);

function optionError(index: number, field: string): string | undefined {
    return (form.errors as Record<string, string | undefined>)[
        `options.${index}.${field}`
    ];
}

function save(): void {
    form.transform((data) => ({
        ...data,
        options: data.options.map((option) => ({
            department_id: option.department_id,
            label: option.label,
            reply_message: option.showReply ? option.reply_message : '',
        })),
    })).put(updateMenu.url(props.connection.id), {
        preserveScroll: true,
        onSuccess: () => form.defaults(),
    });
}
</script>

<template>
    <div class="split-layout gap-6 [--aside-w:24rem]">
        <form
            class="grid content-start gap-6"
            novalidate
            @submit.prevent="save"
        >
            <!-- On / off -->
            <label
                class="flex items-center justify-between gap-4 rounded-xl border bg-card px-4 py-3.5"
                :class="
                    form.is_enabled && 'border-primary/40 bg-primary-soft/30'
                "
            >
                <span class="grid gap-0.5">
                    <span class="font-display text-base font-semibold">
                        {{ form.is_enabled ? 'Menú activo' : 'Menú apagado' }}
                    </span>
                    <span class="text-sm text-muted-foreground">
                        Se envía al iniciar una conversación nueva en
                        {{ connection.name }}.
                    </span>
                </span>
                <Switch
                    v-model="form.is_enabled"
                    :disabled="!canUpdate"
                    aria-label="Activar el menú de bienvenida"
                    data-test="chatbot-menu-enabled"
                />
            </label>

            <ChatbotTextField
                :id="`chatbot-greeting-${connection.id}`"
                v-model="form.greeting"
                label="Saludo"
                :rows="3"
                :maxlength="limits.message"
                :variables="variables"
                :disabled="!canUpdate"
                :error="form.errors.greeting"
                placeholder="¡Hola {{nombre}}! 👋 Elige una opción:"
            />

            <section class="grid gap-3" aria-labelledby="chatbot-options-title">
                <div class="flex items-baseline justify-between gap-3">
                    <h3
                        id="chatbot-options-title"
                        class="font-display text-base font-semibold"
                    >
                        Opciones
                    </h3>
                    <span class="text-xs text-muted-foreground tabular-nums"
                        >{{ form.options.length }}/{{ limits.options }}</span
                    >
                </div>

                <ol class="grid gap-2" data-test="chatbot-options">
                    <li
                        v-for="(option, index) in form.options"
                        :key="option.key"
                        class="grid gap-3 rounded-xl border bg-card p-3 transition-colors duration-(--duration-fast)"
                        :class="
                            !routable(
                                departmentsById.get(option.department_id),
                            ) && 'border-dashed opacity-70'
                        "
                    >
                        <div class="flex items-start gap-2">
                            <span
                                class="mt-1.5 grid size-7 shrink-0 place-items-center rounded-md bg-primary-soft font-display text-sm font-semibold text-primary-soft-foreground tabular-nums"
                                aria-hidden="true"
                                >{{ index + 1 }}</span
                            >
                            <div
                                class="grid min-w-0 flex-1 gap-2 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]"
                            >
                                <div class="grid gap-1">
                                    <AppSelect
                                        v-model="option.department_id"
                                        :options="
                                            departmentOptions(
                                                option.department_id,
                                            )
                                        "
                                        :disabled="!canUpdate"
                                        :invalid="
                                            Boolean(
                                                optionError(
                                                    index,
                                                    'department_id',
                                                ),
                                            )
                                        "
                                        :aria-label="`Departamento de la opción ${index + 1}`"
                                    />
                                    <InputError
                                        :message="
                                            optionError(index, 'department_id')
                                        "
                                    />
                                </div>
                                <div class="grid gap-1">
                                    <Input
                                        v-model="option.label"
                                        :maxlength="limits.label"
                                        :disabled="!canUpdate"
                                        :placeholder="
                                            departmentsById.get(
                                                option.department_id,
                                            )?.name ?? 'Texto de la opción'
                                        "
                                        :aria-label="`Texto de la opción ${index + 1} (opcional)`"
                                    />
                                    <InputError
                                        :message="optionError(index, 'label')"
                                    />
                                </div>
                            </div>
                            <div
                                v-if="canUpdate"
                                class="flex shrink-0 items-center"
                            >
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon-sm"
                                    :disabled="index === 0"
                                    :aria-label="`Subir la opción ${index + 1}`"
                                    @click="move(index, -1)"
                                >
                                    <ArrowUp />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon-sm"
                                    :disabled="
                                        index === form.options.length - 1
                                    "
                                    :aria-label="`Bajar la opción ${index + 1}`"
                                    @click="move(index, 1)"
                                >
                                    <ArrowDown />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon-sm"
                                    class="text-muted-foreground hover:text-destructive"
                                    :aria-label="`Quitar la opción ${index + 1}`"
                                    @click="remove(index)"
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                        </div>

                        <p
                            v-if="
                                !routable(
                                    departmentsById.get(option.department_id),
                                )
                            "
                            class="flex items-center gap-1.5 pl-9 text-xs text-warning-soft-foreground"
                        >
                            <TriangleAlert
                                class="size-3.5 shrink-0"
                                aria-hidden="true"
                            />
                            No se muestra: el departamento está inactivo o no
                            recibe conversaciones de esta conexión.
                        </p>

                        <div class="pl-9">
                            <ChatbotTextField
                                v-if="option.showReply"
                                :id="`chatbot-option-reply-${connection.id}-${option.key}`"
                                v-model="option.reply_message"
                                label="Respuesta al elegirla"
                                compact
                                :rows="2"
                                :maxlength="limits.message"
                                :variables="variables"
                                :disabled="!canUpdate"
                                :error="optionError(index, 'reply_message')"
                                placeholder="Te atiende {{departamento}}. En breve una analista procesará tu solicitud."
                            />
                            <button
                                v-else-if="canUpdate"
                                type="button"
                                class="inline-flex items-center gap-1.5 text-xs font-medium text-primary hover:underline"
                                @click="option.showReply = true"
                            >
                                <MessageSquareReply
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                Respuesta propia
                                <span class="font-normal text-muted-foreground"
                                    >(si no, la bienvenida del
                                    departamento)</span
                                >
                            </button>
                        </div>
                    </li>
                </ol>

                <InputError :message="form.errors.options" />

                <div
                    v-if="canUpdate && form.options.length < limits.options"
                    class="flex items-center gap-2"
                >
                    <Plus
                        class="size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <AppSelect
                        :model-value="adding"
                        :options="addable"
                        placeholder="Agregar opción: elige un departamento"
                        empty-text="No quedan departamentos disponibles"
                        class="max-w-sm"
                        aria-label="Agregar opción"
                        :disabled="addable.length === 0"
                        @update:model-value="(value) => addOption(value)"
                    />
                </div>
            </section>

            <section
                class="grid gap-3 rounded-xl border border-dashed p-4"
                aria-labelledby="chatbot-retry-title"
            >
                <h3
                    id="chatbot-retry-title"
                    class="flex items-center gap-1.5 text-sm font-semibold"
                >
                    <CornerDownRight
                        class="size-4 text-muted-foreground"
                        aria-hidden="true"
                    />
                    Si no responde con una opción
                </h3>
                <div
                    class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,12rem)]"
                >
                    <div class="grid gap-1.5">
                        <Label :for="`chatbot-retry-${connection.id}`"
                            >Mensaje</Label
                        >
                        <Input
                            :id="`chatbot-retry-${connection.id}`"
                            v-model="form.retry_message"
                            :maxlength="limits.retry"
                            :placeholder="defaultRetry"
                            :disabled="!canUpdate || form.max_attempts === 0"
                        />
                        <InputError :message="form.errors.retry_message" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label :for="`chatbot-attempts-${connection.id}`"
                            >Reintentos</Label
                        >
                        <AppSelect
                            :id="`chatbot-attempts-${connection.id}`"
                            v-model="form.max_attempts"
                            :options="attemptOptions"
                            :disabled="!canUpdate"
                        />
                        <InputError :message="form.errors.max_attempts" />
                    </div>
                </div>
                <p class="text-xs text-muted-foreground">
                    Después, la conversación sigue en la cola normal de la
                    conexión.
                </p>
            </section>

            <div
                v-if="canUpdate"
                class="sticky bottom-3 z-10 flex flex-wrap items-center justify-end gap-3 rounded-xl border glass px-4 py-2.5 shadow-(--shadow-float)"
            >
                <p
                    v-if="form.isDirty"
                    class="mr-auto text-sm text-muted-foreground"
                    aria-live="polite"
                >
                    Cambios sin guardar
                </p>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="!form.isDirty || form.processing"
                    @click="form.reset()"
                >
                    Descartar
                </Button>
                <Button
                    type="submit"
                    :loading="form.processing"
                    :disabled="!form.isDirty"
                    data-test="chatbot-menu-save"
                >
                    Guardar menú
                </Button>
            </div>
        </form>

        <aside class="grid content-start gap-3 xl:sticky xl:top-24">
            <ChatbotPhonePreview
                :title="connection.name"
                :subtitle="
                    form.is_enabled
                        ? 'Vista previa · no se envía nada'
                        : 'Menú apagado · vista previa'
                "
                :messages="preview"
            />
            <div
                v-if="visibleOptions.length > 0"
                class="flex flex-wrap items-center gap-1.5 text-xs"
                role="group"
                aria-label="Respuesta del cliente en la vista previa"
            >
                <span class="mr-1 text-muted-foreground"
                    >El cliente responde:</span
                >
                <button
                    v-for="choice in answerChoices"
                    :key="String(choice.value)"
                    type="button"
                    class="rounded-md border px-2 py-0.5 font-medium transition-colors duration-(--duration-fast)"
                    :class="
                        answer === choice.value
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'bg-card hover:border-border-strong'
                    "
                    :aria-pressed="answer === choice.value"
                    @click="answer = choice.value"
                >
                    {{ choice.label }}
                </button>
            </div>
            <p v-if="skipped > 0" class="text-xs text-muted-foreground">
                {{
                    skipped === 1
                        ? '1 opción no se muestra'
                        : `${skipped} opciones no se muestran`
                }}
                (departamento inactivo o no autorizado).
            </p>
        </aside>
    </div>
</template>
