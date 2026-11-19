<script setup lang="ts">
import {
    ArrowDown,
    ArrowUp,
    ChevronDown,
    ChevronRight,
    CornerDownRight,
    ListPlus,
    MessageSquareReply,
    Plus,
    Trash2,
    TriangleAlert,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ChatbotTextField from '@/components/chatbot/ChatbotTextField.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { AppSelect } from '@/components/ui/app-select';
import type { AppSelectOption } from '@/components/ui/app-select';
import { Input } from '@/components/ui/input';
import type {
    ChatbotLimits,
    ChatbotOptionAction,
    ChatbotVariable,
} from '@/types';
import type { ChatbotOptionRow } from './chatbotTree';
import { ACTION_CHOICES, newOption, optionWorks } from './chatbotTree';

defineOptions({ name: 'ChatbotOptionList' });

/**
 * One level of the chatbot menu (the main one or a submenu) with its
 * options in order. Each option says what it does (action) and shows only
 * the fields that action needs; a submenu holds another ChatbotOptionList
 * inside, so the menu can have as many levels as the limits allow.
 */
const props = defineProps<{
    options: ChatbotOptionRow[];
    /** Validation key of this level: «options» or «options.0.children». */
    path: string;
    depth: number;
    connectionId: number;
    departmentOptions: AppSelectOption<number>[];
    routable: (departmentId: number | null) => boolean;
    departmentName: (departmentId: number | null) => string;
    errors: Record<string, string | undefined>;
    variables: ChatbotVariable[];
    limits: ChatbotLimits;
    disabled: boolean;
}>();

const collapsed = ref(new Set<number>());

function toggle(key: number): void {
    const next = new Set(collapsed.value);

    if (next.has(key)) {
        next.delete(key);
    } else {
        next.add(key);
    }

    collapsed.value = next;
}

const actionOptions = computed<AppSelectOption<ChatbotOptionAction>[]>(() =>
    ACTION_CHOICES.filter(
        (choice) =>
            choice.value !== 'submenu' || props.depth < props.limits.depth,
    ).map((choice) => ({
        value: choice.value,
        label: choice.label,
        description: choice.description,
    })),
);

function error(index: number, field: string): string | undefined {
    return props.errors[`${props.path}.${index}.${field}`];
}

function add(): void {
    if (props.options.length >= props.limits.options) {
        return;
    }

    // eslint-disable-next-line vue/no-mutating-props -- the level edits its own rows of the form
    props.options.push(newOption('reply_stay'));
}

function move(index: number, offset: -1 | 1): void {
    const target = index + offset;

    if (target < 0 || target >= props.options.length) {
        return;
    }

    const [row] = props.options.splice(index, 1);
    props.options.splice(target, 0, row);
}

function remove(index: number): void {
    props.options.splice(index, 1);
}

function setAction(
    option: ChatbotOptionRow,
    action: ChatbotOptionAction,
): void {
    option.action = action;

    if (action === 'submenu' && option.children.length === 0) {
        option.children.push(newOption('reply_stay'));
    }

    if (action === 'transfer') {
        option.showReply = option.reply_message.trim() !== '';
    }
}

function numberPrefix(index: number): string {
    return `${index + 1}`;
}
</script>

<template>
    <div class="grid gap-2">
        <div
            v-if="options.length === 0"
            class="grid place-items-center gap-1 rounded-lg border border-dashed px-4 py-6 text-center"
        >
            <ListPlus class="size-6 text-muted-foreground" aria-hidden="true" />
            <p class="text-sm font-medium">Aún no hay opciones</p>
            <p class="text-xs text-muted-foreground">
                {{
                    disabled
                        ? 'Este menú todavía no tiene opciones.'
                        : 'Sin opciones, el bot solo manda la bienvenida y la conversación pasa a la fila. Agrega la primera con el botón de abajo.'
                }}
            </p>
        </div>

        <ol v-else class="grid gap-2" :data-test="`chatbot-options-${path}`">
            <li
                v-for="(option, index) in options"
                :key="option.key"
                class="grid gap-3 rounded-lg border bg-background p-3"
                :class="
                    !optionWorks(option, routable) && 'border-dashed opacity-75'
                "
            >
                <div class="flex items-start gap-2">
                    <span
                        class="mt-1 grid size-7 shrink-0 place-items-center rounded-md bg-primary-soft font-display text-sm font-semibold text-primary-soft-foreground tabular-nums"
                        :title="`Si responde ${index + 1}`"
                        >{{ numberPrefix(index) }}</span
                    >
                    <div
                        class="grid min-w-0 flex-1 gap-2 sm:grid-cols-[minmax(0,1fr)_minmax(0,15rem)]"
                    >
                        <div class="grid gap-1">
                            <Input
                                v-model="option.label"
                                :maxlength="limits.label"
                                :disabled="disabled"
                                :placeholder="
                                    option.action === 'transfer'
                                        ? departmentName(option.department_id)
                                        : 'Texto que ve el cliente'
                                "
                                :aria-label="`Texto de la opción ${index + 1}`"
                                :aria-invalid="Boolean(error(index, 'label'))"
                            />
                            <InputError :message="error(index, 'label')" />
                        </div>
                        <div class="grid gap-1">
                            <AppSelect
                                :model-value="option.action"
                                :options="actionOptions"
                                :disabled="disabled"
                                :aria-label="`Qué hace la opción ${index + 1}`"
                                @update:model-value="
                                    (value) => value && setAction(option, value)
                                "
                            />
                            <InputError :message="error(index, 'action')" />
                        </div>
                    </div>
                    <div v-if="!disabled" class="flex shrink-0 items-center">
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
                            :disabled="index === options.length - 1"
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

                <!-- Transfer: the department, and an optional reply. -->
                <template v-if="option.action === 'transfer'">
                    <div class="grid gap-1 sm:max-w-sm">
                        <AppSelect
                            v-model="option.department_id"
                            :options="departmentOptions"
                            :disabled="disabled"
                            placeholder="Elige el departamento"
                            :invalid="Boolean(error(index, 'department_id'))"
                            :aria-label="`Departamento de la opción ${index + 1}`"
                        />
                        <InputError :message="error(index, 'department_id')" />
                    </div>
                    <p
                        v-if="
                            option.department_id !== null &&
                            !routable(option.department_id)
                        "
                        class="flex items-center gap-1.5 text-xs text-warning-soft-foreground"
                    >
                        <TriangleAlert
                            class="size-3.5 shrink-0"
                            aria-hidden="true"
                        />
                        No se muestra: el departamento está inactivo o no recibe
                        conversaciones de este número.
                    </p>
                    <ChatbotTextField
                        v-if="option.showReply"
                        :id="`chatbot-reply-${connectionId}-${option.key}`"
                        v-model="option.reply_message"
                        label="Lo que contesta el bot antes de pasarla"
                        compact
                        :rows="3"
                        :maxlength="limits.message"
                        :variables="variables"
                        :disabled="disabled"
                        :error="error(index, 'reply_message')"
                        placeholder="Te atiende {{departamento}}. En un momento te contestamos."
                    />
                    <button
                        v-else-if="!disabled"
                        type="button"
                        class="inline-flex flex-wrap items-center gap-1.5 justify-self-start text-left text-xs font-medium text-primary hover:underline"
                        @click="option.showReply = true"
                    >
                        <MessageSquareReply
                            class="size-3.5"
                            aria-hidden="true"
                        />
                        Escribir una respuesta
                        <span class="font-normal text-muted-foreground"
                            >(si no, se usa la bienvenida del
                            departamento)</span
                        >
                    </button>
                </template>

                <!-- Answers: their text is required. -->
                <ChatbotTextField
                    v-else-if="
                        option.action === 'reply' ||
                        option.action === 'reply_stay'
                    "
                    :id="`chatbot-reply-${connectionId}-${option.key}`"
                    v-model="option.reply_message"
                    :label="
                        option.action === 'reply'
                            ? 'Lo que contesta el bot (y termina el menú)'
                            : 'Lo que contesta el bot (y vuelve a mostrar este menú)'
                    "
                    compact
                    :rows="4"
                    :maxlength="limits.message"
                    :variables="variables"
                    :disabled="disabled"
                    :error="error(index, 'reply_message')"
                    placeholder="Escribe la respuesta…"
                />

                <!-- Submenu: its text and its own options. -->
                <template v-else-if="option.action === 'submenu'">
                    <ChatbotTextField
                        :id="`chatbot-reply-${connectionId}-${option.key}`"
                        v-model="option.reply_message"
                        label="Texto arriba de las opciones del submenú"
                        compact
                        :rows="3"
                        :maxlength="limits.message"
                        :variables="variables"
                        :disabled="disabled"
                        :error="error(index, 'reply_message')"
                        placeholder="Indica cómo obtienes tus ingresos (elige 1 o 2):"
                    />
                    <div class="grid gap-2 border-l-2 border-primary/30 pl-3">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 justify-self-start text-xs font-semibold text-muted-foreground hover:text-foreground"
                            :aria-expanded="!collapsed.has(option.key)"
                            @click="toggle(option.key)"
                        >
                            <component
                                :is="
                                    collapsed.has(option.key)
                                        ? ChevronRight
                                        : ChevronDown
                                "
                                class="size-3.5"
                                aria-hidden="true"
                            />
                            <CornerDownRight
                                class="size-3.5"
                                aria-hidden="true"
                            />
                            Opciones de «{{ option.label || 'este submenú' }}»
                            ({{ option.children.length }})
                        </button>
                        <ChatbotOptionList
                            v-show="!collapsed.has(option.key)"
                            :options="option.children"
                            :path="`${path}.${index}.children`"
                            :depth="depth + 1"
                            :connection-id="connectionId"
                            :department-options="departmentOptions"
                            :routable="routable"
                            :department-name="departmentName"
                            :errors="errors"
                            :variables="variables"
                            :limits="limits"
                            :disabled="disabled"
                        />
                    </div>
                </template>
            </li>
        </ol>

        <InputError :message="errors[path]" />

        <Button
            v-if="!disabled && options.length < limits.options"
            type="button"
            variant="outline"
            size="sm"
            class="justify-self-start"
            @click="add"
        >
            <Plus class="size-4" aria-hidden="true" />
            {{ depth === 1 ? 'Agregar opción' : 'Agregar opción al submenú' }}
        </Button>
    </div>
</template>
