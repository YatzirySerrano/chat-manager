<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { TriangleAlert } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
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
import { Spinner } from '@/components/ui/spinner';
import { COLOR_LABELS } from '@/lib/chatAppearance';
import {
    contrastRatio,
    formatRatio,
    isHex,
    isReadable,
    readableTextOn,
} from '@/lib/contrast';
import {
    destroy as destroyAppearance,
    update as updateAppearance,
} from '@/routes/conversations/appearance';
import type {
    ChatAppearanceResponse,
    ChatColorKey,
    ChatColors,
    ChatColorSet,
    ViewerChatAppearance,
} from '@/types';

/**
 * Editor of the viewer's own chat colors: a preset or custom colors (light
 * and dark), a preview of both modes before saving, undo of unsaved
 * changes and restoring the defaults, for this conversation only or for all
 * of the viewer's conversations. Text colors that would be hard to read are
 * replaced by the server with near-black or white (the preview shows it).
 */
const props = defineProps<{
    conversationId: number;
    appearance: ViewerChatAppearance;
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{ saved: [appearance: ViewerChatAppearance] }>();

type Scope = 'conversation' | 'all';
type Mode = 'light' | 'dark';

/** "default" = platform theme, "custom" = colors chosen one by one. */
const DEFAULT = 'default';
const CUSTOM = 'custom';

const KEYS: ChatColorKey[] = [
    'sent_bg',
    'sent_text',
    'received_bg',
    'received_text',
    'chat_bg',
];
const TEXT_PAIRS: [ChatColorKey, ChatColorKey, string][] = [
    ['sent_text', 'sent_bg', 'Texto de tus mensajes'],
    ['received_text', 'received_bg', 'Texto de los mensajes del cliente'],
];
const MODES: { value: Mode; label: string }[] = [
    { value: 'light', label: 'Modo claro' },
    { value: 'dark', label: 'Modo oscuro' },
];

const scope = ref<Scope>('conversation');
const choice = ref<string>(DEFAULT);
const editingMode = ref<Mode>('light');
const draft = reactive<ChatColors>(blankColors());
const saveHttp = useHttp<
    { scope: Scope; preset: string | null; colors: ChatColors },
    ChatAppearanceResponse
>({ scope: 'conversation', preset: null, colors: blankColors() });
const resetHttp = useHttp<Record<string, never>, ChatAppearanceResponse>({});
const error = ref<string | null>(null);

const processing = computed(() => saveHttp.processing || resetHttp.processing);

function blankColors(): ChatColors {
    const set = (): ChatColorSet => ({
        sent_bg: '#FFFFFF',
        sent_text: '#111111',
        received_bg: '#FFFFFF',
        received_text: '#111111',
        chat_bg: '#FFFFFF',
    });

    return { light: set(), dark: set() };
}

function copyColors(colors: ChatColors): ChatColors {
    return { light: { ...colors.light }, dark: { ...colors.dark } };
}

function sameColors(a: ChatColors, b: ChatColors): boolean {
    return (['light', 'dark'] as Mode[]).every((mode) =>
        KEYS.every(
            (key) => a[mode][key].toUpperCase() === b[mode][key].toUpperCase(),
        ),
    );
}

/** What is stored for the scope (the conversation falls back to the default). */
const stored = computed(() => {
    const own =
        scope.value === 'conversation'
            ? props.appearance.conversation
            : props.appearance.default;
    const inherited =
        scope.value === 'conversation' ? props.appearance.default : null;

    return { own, effective: own ?? inherited };
});

function presetColors(value: string): ChatColors | null {
    return (
        props.appearance.presets.find((preset) => preset.value === value)
            ?.colors ?? null
    );
}

/** Puts the editor back to what is saved for the current scope. */
function revert(): void {
    const effective = stored.value.effective;
    error.value = null;

    if (!effective?.colors) {
        choice.value = DEFAULT;
        Object.assign(draft, copyColors(props.appearance.presets[0].colors));

        return;
    }

    choice.value = effective.preset ?? CUSTOM;
    Object.assign(draft, copyColors(effective.colors));
}

watch(open, (isOpen) => {
    if (isOpen) {
        scope.value =
            props.appearance.conversation !== null ||
            props.appearance.default === null
                ? 'conversation'
                : 'all';
        editingMode.value = 'light';
        revert();
    }
});

watch(scope, () => {
    if (open.value) {
        revert();
    }
});

function choose(value: string): void {
    choice.value = value;
    const colors = presetColors(value);

    if (colors) {
        Object.assign(draft, copyColors(colors));
    }
}

function setColor(mode: Mode, key: ChatColorKey, value: string): void {
    const hex = value.startsWith('#') ? value : `#${value}`;
    draft[mode][key] = hex.toUpperCase();
    const preset = presetColors(choice.value);

    if (preset === null || !sameColors(preset, draft)) {
        choice.value = CUSTOM;
    }
}

const invalidColors = computed(() =>
    (['light', 'dark'] as Mode[]).some((mode) =>
        KEYS.some((key) => !isHex(draft[mode][key])),
    ),
);

/** The colors as the server will store them (unreadable text adjusted). */
const effectiveDraft = computed<ChatColors | null>(() => {
    if (choice.value === DEFAULT || invalidColors.value) {
        return null;
    }

    const result = copyColors(draft);

    for (const mode of ['light', 'dark'] as Mode[]) {
        for (const [text, background] of TEXT_PAIRS) {
            if (!isReadable(result[mode][text], result[mode][background])) {
                result[mode][text] = readableTextOn(result[mode][background]);
            }
        }
    }

    return result;
});

const warnings = computed<string[]>(() => {
    if (choice.value === DEFAULT || invalidColors.value) {
        return [];
    }

    const list: string[] = [];

    for (const { value: mode, label: modeLabel } of MODES) {
        for (const [text, background, label] of TEXT_PAIRS) {
            const ratio = contrastRatio(
                draft[mode][text],
                draft[mode][background],
            );

            if (!isReadable(draft[mode][text], draft[mode][background])) {
                list.push(
                    `${label} (${modeLabel.toLowerCase()}): contraste ${formatRatio(ratio)}. Al guardar lo ajustaremos para que sea legible.`,
                );
            }
        }

        for (const key of ['sent_bg', 'received_bg'] as ChatColorKey[]) {
            if (contrastRatio(draft[mode].chat_bg, draft[mode][key]) < 1.1) {
                list.push(
                    `${COLOR_LABELS[key]} (${modeLabel.toLowerCase()}) casi no se distingue del fondo del chat.`,
                );
            }
        }
    }

    return list;
});

const dirty = computed(() => {
    const effective = stored.value.effective;

    if (choice.value === DEFAULT) {
        return effective?.colors != null;
    }

    return !effective?.colors || !sameColors(effective.colors, draft);
});

async function save(): Promise<void> {
    if (processing.value) {
        return;
    }

    if (invalidColors.value) {
        error.value = 'Cada color debe tener el formato #RRGGBB.';

        return;
    }

    error.value = null;

    if (choice.value === DEFAULT) {
        await restore();

        return;
    }

    saveHttp.scope = scope.value;
    saveHttp.preset = choice.value === CUSTOM ? null : choice.value;
    saveHttp.colors = copyColors(draft);

    try {
        const response = await saveHttp.put(
            updateAppearance.url(props.conversationId),
            { onHttpException: () => false },
        );

        // A validation refusal resolves without a response.
        if (!response) {
            error.value =
                Object.values(saveHttp.errors)[0] ??
                'No se pudo guardar la apariencia.';

            return;
        }

        if (response.notice) {
            toast.info(response.notice);
        } else {
            toast.success(
                scope.value === 'all'
                    ? 'Apariencia guardada para todas tus conversaciones.'
                    : 'Apariencia guardada para esta conversación.',
            );
        }

        emit('saved', response.appearance);
        open.value = false;
    } catch {
        error.value =
            Object.values(saveHttp.errors)[0] ??
            'No se pudo guardar la apariencia.';
    }
}

/** Deletes what is saved for the scope: back to the next level. */
async function restore(): Promise<void> {
    if (stored.value.own === null) {
        open.value = false;

        return;
    }

    try {
        const response = await resetHttp.delete(
            destroyAppearance.url(props.conversationId, {
                query: { scope: scope.value },
            }),
            { onHttpException: () => false },
        );

        if (!response) {
            error.value = 'No se pudo restablecer la apariencia.';

            return;
        }

        toast.success(
            scope.value === 'all'
                ? 'Restablecimos los colores predeterminados en todas tus conversaciones.'
                : 'Restablecimos los colores de esta conversación.',
        );
        emit('saved', response.appearance);
        open.value = false;
    } catch {
        error.value = 'No se pudo restablecer la apariencia.';
    }
}

function previewStyle(mode: Mode, part: 'chat' | 'sent' | 'received') {
    const colors = effectiveDraft.value?.[mode];

    if (!colors) {
        return {};
    }

    return part === 'chat'
        ? { backgroundColor: colors.chat_bg }
        : {
              backgroundColor: colors[`${part}_bg`],
              color: colors[`${part}_text`],
          };
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-2xl">
            <form class="grid gap-5" novalidate @submit.prevent="save">
                <DialogHeader>
                    <DialogTitle>Apariencia del chat</DialogTitle>
                    <DialogDescription>
                        Colores solo para tu vista. No cambian los mensajes ni
                        lo que ven tus compañeros o el cliente.
                    </DialogDescription>
                </DialogHeader>

                <fieldset class="grid gap-2">
                    <legend class="mb-1 text-sm font-medium">Aplicar a</legend>
                    <div class="flex flex-wrap gap-2">
                        <label
                            v-for="option in [
                                {
                                    value: 'conversation',
                                    label: 'Solo esta conversación',
                                },
                                {
                                    value: 'all',
                                    label: 'Todas mis conversaciones',
                                },
                            ]"
                            :key="option.value"
                            class="flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-sm has-checked:border-primary has-checked:bg-primary/5"
                        >
                            <input
                                v-model="scope"
                                type="radio"
                                name="appearance-scope"
                                :value="option.value"
                                class="accent-primary"
                            />
                            {{ option.label }}
                        </label>
                    </div>
                    <p
                        v-if="
                            scope === 'conversation' &&
                            !appearance.conversation &&
                            appearance.default
                        "
                        class="text-xs text-muted-foreground"
                    >
                        Esta conversación usa tus colores de todas las
                        conversaciones.
                    </p>
                </fieldset>

                <fieldset class="grid gap-2">
                    <legend class="mb-1 text-sm font-medium">Paleta</legend>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        <button
                            type="button"
                            class="rounded-md border px-3 py-2 text-left text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            :class="
                                choice === DEFAULT
                                    ? 'border-primary bg-primary/5'
                                    : ''
                            "
                            :aria-pressed="choice === DEFAULT"
                            @click="choice = DEFAULT"
                        >
                            Predeterminado
                            <span class="block text-xs text-muted-foreground">
                                Colores de la plataforma
                            </span>
                        </button>
                        <button
                            v-for="preset in appearance.presets"
                            :key="preset.value"
                            type="button"
                            class="flex items-center gap-2 rounded-md border px-3 py-2 text-left text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            :class="
                                choice === preset.value
                                    ? 'border-primary bg-primary/5'
                                    : ''
                            "
                            :aria-pressed="choice === preset.value"
                            @click="choose(preset.value)"
                        >
                            <span class="flex shrink-0" aria-hidden="true">
                                <span
                                    class="size-4 rounded-full border"
                                    :style="{
                                        backgroundColor:
                                            preset.colors.light.sent_bg,
                                    }"
                                />
                                <span
                                    class="-ml-1.5 size-4 rounded-full border"
                                    :style="{
                                        backgroundColor:
                                            preset.colors.dark.sent_bg,
                                    }"
                                />
                            </span>
                            {{ preset.label }}
                        </button>
                        <button
                            type="button"
                            class="rounded-md border px-3 py-2 text-left text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            :class="
                                choice === CUSTOM
                                    ? 'border-primary bg-primary/5'
                                    : ''
                            "
                            :aria-pressed="choice === CUSTOM"
                            @click="choice = CUSTOM"
                        >
                            Personalizado
                            <span class="block text-xs text-muted-foreground">
                                Elige cada color
                            </span>
                        </button>
                    </div>
                </fieldset>

                <fieldset v-if="choice !== DEFAULT" class="grid gap-3">
                    <legend class="mb-1 text-sm font-medium">Colores</legend>
                    <div
                        class="flex gap-1"
                        role="group"
                        aria-label="Modo a editar"
                    >
                        <Button
                            v-for="mode in MODES"
                            :key="mode.value"
                            type="button"
                            size="sm"
                            :variant="
                                editingMode === mode.value
                                    ? 'secondary'
                                    : 'ghost'
                            "
                            :aria-pressed="editingMode === mode.value"
                            @click="editingMode = mode.value"
                        >
                            {{ mode.label }}
                        </Button>
                    </div>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <div
                            v-for="key in KEYS"
                            :key="`${editingMode}-${key}`"
                            class="flex items-center gap-2"
                        >
                            <input
                                :id="`chat-color-${editingMode}-${key}`"
                                type="color"
                                class="h-9 w-11 shrink-0 cursor-pointer rounded-md border bg-transparent p-0.5"
                                :value="
                                    isHex(draft[editingMode][key])
                                        ? draft[editingMode][key].toLowerCase()
                                        : '#000000'
                                "
                                :aria-label="`${COLOR_LABELS[key]} (selector)`"
                                @input="
                                    setColor(
                                        editingMode,
                                        key,
                                        ($event.target as HTMLInputElement)
                                            .value,
                                    )
                                "
                            />
                            <div class="grid min-w-0 flex-1 gap-0.5">
                                <label
                                    :for="`chat-color-hex-${editingMode}-${key}`"
                                    class="truncate text-xs text-muted-foreground"
                                >
                                    {{ COLOR_LABELS[key] }}
                                </label>
                                <Input
                                    :id="`chat-color-hex-${editingMode}-${key}`"
                                    class="h-8 font-mono text-sm uppercase"
                                    maxlength="7"
                                    spellcheck="false"
                                    autocomplete="off"
                                    :model-value="draft[editingMode][key]"
                                    :aria-invalid="
                                        !isHex(draft[editingMode][key]) ||
                                        undefined
                                    "
                                    @update:model-value="
                                        (value) =>
                                            setColor(
                                                editingMode,
                                                key,
                                                String(value),
                                            )
                                    "
                                />
                            </div>
                        </div>
                    </div>
                </fieldset>

                <section class="grid gap-2" aria-label="Vista previa">
                    <h3 class="text-sm font-medium">Vista previa</h3>
                    <p
                        v-if="choice === DEFAULT"
                        class="text-sm text-muted-foreground"
                    >
                        El chat usará los colores de la plataforma.
                    </p>
                    <div v-else class="grid gap-2 sm:grid-cols-2">
                        <div
                            v-for="mode in MODES"
                            :key="mode.value"
                            class="grid gap-1.5 rounded-lg border p-3"
                            :style="previewStyle(mode.value, 'chat')"
                            data-appearance-preview
                        >
                            <span
                                class="w-fit rounded bg-background/80 px-1.5 text-xs text-foreground"
                            >
                                {{ mode.label }}
                            </span>
                            <p
                                class="w-fit max-w-[85%] rounded-2xl rounded-bl-md border px-3 py-1.5 text-sm shadow-xs"
                                :style="previewStyle(mode.value, 'received')"
                            >
                                Hola, ¿me ayudas con mi pedido?
                            </p>
                            <p
                                class="ml-auto w-fit max-w-[85%] rounded-2xl rounded-br-md px-3 py-1.5 text-sm shadow-xs"
                                :style="previewStyle(mode.value, 'sent')"
                            >
                                ¡Claro! Ya lo reviso.
                            </p>
                        </div>
                    </div>
                    <ul
                        v-if="warnings.length > 0"
                        class="grid gap-1 rounded-md border border-warning/40 bg-warning/10 p-2 text-xs"
                        role="status"
                    >
                        <li
                            v-for="warning in warnings"
                            :key="warning"
                            class="flex items-start gap-1.5"
                        >
                            <TriangleAlert
                                class="mt-px size-3.5 shrink-0 text-warning"
                                aria-hidden="true"
                            />
                            {{ warning }}
                        </li>
                    </ul>
                </section>

                <p v-if="error" class="text-sm text-destructive" role="alert">
                    {{ error }}
                </p>

                <DialogFooter class="gap-2 pointer-coarse:*:h-10">
                    <Button
                        v-if="stored.own"
                        type="button"
                        variant="ghost"
                        class="sm:mr-auto"
                        :disabled="processing"
                        @click="restore"
                    >
                        Restablecer predeterminados
                    </Button>
                    <Button
                        v-if="dirty"
                        type="button"
                        variant="outline"
                        :disabled="processing"
                        @click="revert"
                    >
                        Deshacer cambios
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="processing"
                        @click="open = false"
                    >
                        Cancelar
                    </Button>
                    <Button type="submit" :disabled="processing || !dirty">
                        <Spinner v-if="processing" />
                        Guardar
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
