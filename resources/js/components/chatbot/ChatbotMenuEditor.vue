<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ArrowRight, Bot, RotateCcw } from '@lucide/vue';
import type { FunctionalComponent } from 'vue';
import { computed, h, ref } from 'vue';
import ChatbotOptionList from '@/components/chatbot/ChatbotOptionList.vue';
import ChatbotPhonePreview from '@/components/chatbot/ChatbotPhonePreview.vue';
import type { ChatbotPreviewMessage } from '@/components/chatbot/ChatbotPhonePreview.vue';
import ChatbotStep from '@/components/chatbot/ChatbotStep.vue';
import ChatbotTextField from '@/components/chatbot/ChatbotTextField.vue';
import type { ChatbotOptionRow } from '@/components/chatbot/chatbotTree';
import {
    optionWorks,
    toPayload,
    toRows,
} from '@/components/chatbot/chatbotTree';
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
    ChatbotVariable,
} from '@/types';

/**
 * The programmable chatbot menu of one connection: on/off, greeting, the
 * options of every level (each one says what it does: open a submenu,
 * answer and stay, answer and end, or pass to a department), what happens
 * with an answer that is not an option and how long it waits, next to a
 * preview the administrator can walk through as a customer would.
 */
const props = defineProps<{
    connection: ChatbotConnection;
    departments: ChatbotDepartment[];
    variables: ChatbotVariable[];
    limits: ChatbotLimits;
    defaultRetry: string;
    defaultNavigation: string;
}>();

const form = useForm({
    is_enabled: props.connection.menu.is_enabled,
    greeting: props.connection.menu.greeting,
    retry_message: props.connection.menu.retry_message,
    navigation_hint: props.connection.menu.navigation_hint,
    max_attempts: props.connection.menu.max_attempts,
    timeout_minutes: props.connection.menu.timeout_minutes,
    // Opaque for useForm (its key types cannot follow a recursive tree); read through `rows`.
    options: toRows(props.connection.menu.options) as unknown as object[],
});

const rows = computed(() => form.options as unknown as ChatbotOptionRow[]);

const canUpdate = computed(() => props.connection.can.update);

const departmentsById = computed(
    () => new Map(props.departments.map((item) => [item.id, item])),
);

/** Whether a department can receive this connection's conversations now. */
function routable(departmentId: number | null): boolean {
    const department =
        departmentId === null
            ? undefined
            : departmentsById.value.get(departmentId);

    return (
        department !== undefined &&
        department.is_active &&
        (props.connection.department_ids.length === 0 ||
            props.connection.department_ids.includes(department.id))
    );
}

function departmentName(departmentId: number | null): string {
    return (
        (departmentId !== null &&
            departmentsById.value.get(departmentId)?.name) ||
        'Texto de la opción'
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

const departmentOptions = computed<AppSelectOption<number>[]>(() =>
    props.departments.map((department) => ({
        value: department.id,
        label: department.name,
        icon: dot(department.color),
        description: !department.is_active
            ? 'Inactivo: se omite del menú'
            : !routable(department.id)
              ? 'No autorizado para esta conexión: se omite'
              : undefined,
    })),
);

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

const timeoutOptions = computed<AppSelectOption<number>[]>(() =>
    [0, 5, 10, 15, 30, 60, 120, 240, 480, 1440]
        .filter((minutes) => minutes <= props.limits.timeout)
        .map((minutes) => ({
            value: minutes,
            label:
                minutes === 0
                    ? 'Esperar siempre'
                    : minutes < 60
                      ? `${minutes} minutos`
                      : minutes === 60
                        ? '1 hora'
                        : `${minutes / 60} horas`,
        })),
);

function optionLabel(option: ChatbotOptionRow): string {
    return (
        option.label.trim() ||
        (option.action === 'transfer'
            ? departmentsById.value.get(option.department_id ?? -1)?.name
            : '') ||
        'Opción'
    );
}

/* ---- Preview: the administrator answers as a customer would. ---- */

type Answer = number | 'back' | 'main' | 'other';

const answers = ref<Answer[]>([]);

const sample = computed(() =>
    defaultSample({ conexion: props.connection.name }),
);

function visible(level: ChatbotOptionRow[]): ChatbotOptionRow[] {
    return level.filter((option) => optionWorks(option, routable));
}

function list(level: ChatbotOptionRow[]): string {
    return visible(level)
        .map((option, index) => `${index + 1} - ${optionLabel(option)}`)
        .join('\n');
}

const navigationText = computed(
    () => form.navigation_hint.trim() || props.defaultNavigation,
);

function levelText(submenu: ChatbotOptionRow | null): string {
    if (submenu === null) {
        return [form.greeting.trim(), list(rows.value)]
            .filter(Boolean)
            .join('\n\n');
    }

    return [
        submenu.reply_message.trim(),
        list(submenu.children),
        navigationText.value,
    ]
        .filter(Boolean)
        .join('\n\n');
}

/** Walks the answers through the tree, as ChatbotConversations does. */
const simulation = computed(() => {
    const render = (text: string, department = '') =>
        renderChatbotText(
            text,
            defaultSample({
                conexion: props.connection.name,
                departamento: department,
            }),
        );
    const messages: ChatbotPreviewMessage[] = [
        { key: 'hello', from: 'customer', text: 'Hola' },
        {
            key: 'menu',
            from: 'bot',
            label: 'Menú principal',
            text: renderChatbotText(levelText(null), sample.value),
            muted: !form.is_enabled,
        },
    ];
    // The submenus the customer went through (last: where they are).
    const trail: ChatbotOptionRow[] = [];
    let ended = false;
    let attempts = 0;

    answers.value.forEach((answer, step) => {
        if (ended) {
            return;
        }

        const current = trail.at(-1) ?? null;
        const level = visible(current === null ? rows.value : current.children);

        messages.push({
            key: `answer-${step}`,
            from: 'customer',
            text:
                answer === 'back'
                    ? '0'
                    : answer === 'main'
                      ? '#'
                      : answer === 'other'
                        ? 'quiero informes'
                        : String(answer),
        });

        if (answer === 'back' || answer === 'main') {
            if (answer === 'main') {
                trail.length = 0;
            } else {
                trail.pop();
            }

            attempts = 0;
            const target = trail.at(-1) ?? null;
            messages.push({
                key: `level-${step}`,
                from: 'bot',
                label:
                    target === null
                        ? 'Menú principal'
                        : `Submenú «${optionLabel(target)}»`,
                text: render(levelText(target)),
            });

            return;
        }

        const option = answer === 'other' ? null : (level[answer - 1] ?? null);

        if (option === null) {
            attempts++;

            if (attempts > form.max_attempts) {
                ended = true;
                messages.push({
                    key: `fallback-${step}`,
                    from: 'bot',
                    label: 'Sin opción válida: pasa a la fila normal',
                    text: '',
                    muted: true,
                });

                return;
            }

            messages.push({
                key: `retry-${step}`,
                from: 'bot',
                label: 'Reintento',
                text: [
                    form.retry_message.trim() || props.defaultRetry,
                    list(current === null ? rows.value : current.children),
                    current === null ? '' : navigationText.value,
                ]
                    .filter(Boolean)
                    .join('\n\n'),
            });

            return;
        }

        attempts = 0;

        if (option.action === 'submenu') {
            trail.push(option);
            messages.push({
                key: `level-${step}`,
                from: 'bot',
                label: `Submenú «${optionLabel(option)}»`,
                text: render(levelText(option)),
            });

            return;
        }

        if (option.action === 'reply_stay') {
            messages.push({
                key: `stay-${step}`,
                from: 'bot',
                label: 'Respuesta · sigue en el menú',
                text: render(
                    [
                        option.reply_message.trim(),
                        list(current === null ? rows.value : current.children),
                        current === null ? '' : navigationText.value,
                    ]
                        .filter(Boolean)
                        .join('\n\n'),
                ),
            });

            return;
        }

        ended = true;

        if (option.action === 'reply') {
            messages.push({
                key: `reply-${step}`,
                from: 'bot',
                label: 'Respuesta · termina el menú, la toma un agente',
                text: render(option.reply_message.trim()),
            });

            return;
        }

        const department = departmentsById.value.get(
            option.department_id ?? -1,
        );
        const reply = option.showReply ? option.reply_message.trim() : '';

        messages.push({
            key: `transfer-${step}`,
            from: 'bot',
            label: `Pasa a ${department?.name ?? 'un departamento'}`,
            text: reply
                ? render(reply, department?.name ?? '')
                : department?.has_welcome
                  ? `_(La bienvenida configurada en ${department.name})_`
                  : '',
            muted: !reply,
        });
    });

    const current = trail.at(-1) ?? null;

    return {
        messages,
        ended,
        inSubmenu: current !== null,
        choices: visible(current === null ? rows.value : current.children),
    };
});

function answer(value: Answer): void {
    answers.value = [...answers.value, value];
}

function errorsRecord(): Record<string, string | undefined> {
    return form.errors as Record<string, string | undefined>;
}

function save(): void {
    form.transform((data) => ({
        ...data,
        options: toPayload(data.options as unknown as ChatbotOptionRow[]),
    })).put(updateMenu.url(props.connection.id), {
        preserveScroll: true,
        onSuccess: () => form.defaults(),
    });
}
</script>

<template>
    <div class="split-layout gap-6 [--aside-w:26rem]">
        <form
            class="grid content-start gap-4"
            novalidate
            @submit.prevent="save"
        >
            <!-- On / off -->
            <label
                class="flex items-center gap-4 rounded-xl border bg-card px-4 py-4 transition-colors duration-(--duration-base)"
                :class="
                    form.is_enabled
                        ? 'border-success/40 bg-success-soft/50'
                        : 'border-dashed'
                "
            >
                <span
                    class="grid size-10 shrink-0 place-items-center rounded-full"
                    :class="
                        form.is_enabled
                            ? 'bg-success text-white'
                            : 'bg-muted text-muted-foreground'
                    "
                    aria-hidden="true"
                >
                    <Bot class="size-5" />
                </span>
                <span class="grid min-w-0 flex-1 gap-0.5">
                    <span class="font-display text-base font-semibold">
                        {{
                            form.is_enabled
                                ? 'El chatbot está encendido'
                                : 'El chatbot está apagado'
                        }}
                    </span>
                    <span class="text-sm text-muted-foreground">
                        {{
                            form.is_enabled
                                ? `Contesta solo cuando alguien escribe por primera vez a ${connection.name}.`
                                : 'Nadie lo recibe. Enciéndelo cuando esté listo.'
                        }}
                    </span>
                </span>
                <Switch
                    v-model="form.is_enabled"
                    :disabled="!canUpdate"
                    aria-label="Encender el chatbot"
                    data-test="chatbot-menu-enabled"
                />
            </label>

            <ChatbotStep
                :id="`chatbot-step-greeting-${connection.id}`"
                :number="1"
                title="Mensaje de bienvenida"
                hint="Lo primero que lee el cliente, antes de la lista de opciones."
            >
                <ChatbotTextField
                    :id="`chatbot-greeting-${connection.id}`"
                    v-model="form.greeting"
                    label="Texto de bienvenida"
                    :rows="5"
                    :maxlength="limits.message"
                    :variables="variables"
                    :disabled="!canUpdate"
                    :error="form.errors.greeting"
                    placeholder="¡{{saludo}} {{nombre}}! 👋 Indica 1, 2 o 3 para que te atienda:"
                />
            </ChatbotStep>

            <ChatbotStep
                :id="`chatbot-step-options-${connection.id}`"
                :number="2"
                title="Opciones y respuestas"
                :hint="`Cada opción hace algo: abre un submenú, contesta, o pasa a un departamento. Hasta ${limits.options} por menú y ${limits.depth} niveles.`"
            >
                <ChatbotOptionList
                    :options="rows"
                    path="options"
                    :depth="1"
                    :connection-id="connection.id"
                    :department-options="departmentOptions"
                    :routable="routable"
                    :department-name="departmentName"
                    :errors="errorsRecord()"
                    :variables="variables"
                    :limits="limits"
                    :disabled="!canUpdate"
                />
            </ChatbotStep>

            <ChatbotStep
                :id="`chatbot-step-navigation-${connection.id}`"
                :number="3"
                title="Regresar en los submenús"
                hint="El cliente escribe 0 para volver al menú anterior o # para el principal. Esto se muestra debajo de cada submenú."
            >
                <ChatbotTextField
                    :id="`chatbot-navigation-${connection.id}`"
                    v-model="form.navigation_hint"
                    label="Instrucciones para regresar"
                    compact
                    :rows="2"
                    :maxlength="limits.navigation"
                    :variables="[]"
                    :disabled="!canUpdate"
                    :error="form.errors.navigation_hint"
                    :placeholder="defaultNavigation"
                />
            </ChatbotStep>

            <ChatbotStep
                :id="`chatbot-step-retry-${connection.id}`"
                :number="4"
                title="Si no contesta con una opción"
                hint="Por ejemplo, si escribe «quiero informes» en vez de «1», o si deja de contestar."
            >
                <div
                    class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,12rem)]"
                >
                    <div class="grid gap-1.5">
                        <Label :for="`chatbot-retry-${connection.id}`"
                            >Le pedimos que lo intente otra vez con</Label
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
                            >¿Cuántas veces?</Label
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
                <div class="grid gap-1.5 sm:max-w-xs">
                    <Label :for="`chatbot-timeout-${connection.id}`"
                        >Si no contesta en</Label
                    >
                    <AppSelect
                        :id="`chatbot-timeout-${connection.id}`"
                        v-model="form.timeout_minutes"
                        :options="timeoutOptions"
                        :disabled="!canUpdate"
                    />
                    <InputError :message="form.errors.timeout_minutes" />
                </div>
                <p
                    class="flex items-center gap-1.5 rounded-lg bg-muted/60 px-3 py-2 text-xs text-muted-foreground"
                >
                    <ArrowRight class="size-3.5 shrink-0" aria-hidden="true" />
                    Después, la conversación pasa a la fila normal de este
                    número, se avisa al equipo y la toma un agente.
                </p>
            </ChatbotStep>

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
                <p
                    v-if="form.hasErrors"
                    class="mr-auto text-sm text-destructive"
                    aria-live="polite"
                >
                    Revisa los campos marcados
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
                    Guardar chatbot
                </Button>
            </div>
        </form>

        <aside class="grid content-start gap-3 xl:sticky xl:top-24">
            <div class="flex items-center justify-between gap-2">
                <p
                    class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    Así lo ve el cliente
                </p>
                <Button
                    v-if="answers.length > 0"
                    type="button"
                    variant="ghost"
                    size="sm"
                    @click="answers = []"
                >
                    <RotateCcw class="size-3.5" aria-hidden="true" />
                    Reiniciar
                </Button>
            </div>
            <ChatbotPhonePreview
                :title="connection.name"
                :subtitle="
                    form.is_enabled
                        ? 'Vista previa · no se envía nada'
                        : 'Chatbot apagado · vista previa'
                "
                :messages="simulation.messages"
            />
            <div
                v-if="!simulation.ended && rows.length > 0"
                class="flex flex-wrap items-center gap-1.5 text-xs"
                role="group"
                aria-label="Respuesta del cliente en la vista previa"
            >
                <span class="mr-1 text-muted-foreground"
                    >Responde como cliente:</span
                >
                <button
                    v-for="(choice, index) in simulation.choices"
                    :key="choice.key"
                    type="button"
                    class="rounded-full border bg-card px-2.5 py-0.5 font-medium transition-colors duration-(--duration-fast) hover:border-border-strong"
                    :title="optionLabel(choice)"
                    @click="answer(index + 1)"
                >
                    {{ index + 1 }}
                </button>
                <template v-if="simulation.inSubmenu">
                    <button
                        type="button"
                        class="rounded-full border bg-card px-2.5 py-0.5 font-medium hover:border-border-strong"
                        title="Volver al menú anterior"
                        @click="answer('back')"
                    >
                        0
                    </button>
                    <button
                        type="button"
                        class="rounded-full border bg-card px-2.5 py-0.5 font-medium hover:border-border-strong"
                        title="Volver al menú principal"
                        @click="answer('main')"
                    >
                        #
                    </button>
                </template>
                <button
                    type="button"
                    class="rounded-full border bg-card px-2.5 py-0.5 font-medium hover:border-border-strong"
                    @click="answer('other')"
                >
                    Otra cosa
                </button>
            </div>
            <p
                v-else-if="simulation.ended"
                class="text-xs text-muted-foreground"
            >
                Aquí termina el chatbot. Pulsa «Reiniciar» para probar otro
                camino.
            </p>
        </aside>
    </div>
</template>
