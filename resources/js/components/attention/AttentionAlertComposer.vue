<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { Check, ChevronDown, Search, Volume2, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import AttentionAlertCard from '@/components/attention/AttentionAlertCard.vue';
import { ALERT_SURFACE, PRIORITY_LOOK } from '@/components/attention/priority';
import FormDialog from '@/components/FormDialog.vue';
import InputError from '@/components/InputError.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Button } from '@/components/ui/button';
import {
    previewAttentionAlertSound,
    stopAttentionAlertPreview,
} from '@/lib/notifications/alerts';
import type { AttentionAlertTone } from '@/lib/notifications/alerts';
import { swatchClasses } from '@/lib/swatches';
import { cn } from '@/lib/utils';
import { store as storeAlert } from '@/routes/attention/alerts';
import type {
    AttentionAgent,
    AttentionAlertAudience,
    AttentionAlertPriority,
    AttentionAlertSound,
    AttentionOption,
    ColorSwatch,
} from '@/types';

/**
 * Composer of an attention alert: recipients (one agent, a selection or
 * every agent within reach), title, message, priority, color and sound from
 * closed catalogs, with a live preview of what agents will see. The server
 * resolves the recipients again from the sender's reach; a client key per
 * opening makes a double click or a retried request harmless.
 */
const props = defineProps<{
    agents: AttentionAgent[];
    /** Agents chosen when the dialog opens; empty with audience «everyone». */
    initialRecipients: number[];
    initialAudience: AttentionAlertAudience;
    senderName: string;
    options: {
        priorities: AttentionOption<AttentionAlertPriority>[];
        sounds: AttentionOption<AttentionAlertSound>[];
        colors: AttentionOption<ColorSwatch>[];
        title_max: number;
        body_max: number;
    };
}>();

const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ sent: [] }>();

const DEFAULT_SOUND: Record<AttentionAlertPriority, AttentionAlertTone> = {
    info: 'chime',
    important: 'bell',
    urgent: 'siren',
};

const form = useForm({
    client_key: '',
    audience: 'individual' as AttentionAlertAudience,
    recipient_ids: [] as number[],
    title: '',
    body: '',
    priority: 'info' as AttentionAlertPriority,
    color: 'blue' as ColorSwatch,
    sound: 'priority' as AttentionAlertSound,
    link_pending: true,
});

function newKey(): string {
    return typeof crypto !== 'undefined' && 'randomUUID' in crypto
        ? crypto.randomUUID()
        : 'xxxxxxxx-xxxx-4xxx-8xxx-xxxxxxxxxxxx'.replace(/x/g, () =>
              Math.floor(Math.random() * 16).toString(16),
          );
}

watch(open, (value) => {
    if (!value) {
        stopAttentionAlertPreview();

        return;
    }

    form.reset();
    form.clearErrors();
    form.client_key = newKey();
    form.recipient_ids = [...props.initialRecipients];
    form.audience = props.initialAudience;
    search.value = '';
    showAllRecipients.value = false;
    form.defaults();
});

/* The audience follows the recipients chosen (one, several, everyone). */
const everyone = computed({
    get: () => form.audience === 'everyone',
    set: (value: boolean) => {
        form.audience = value
            ? 'everyone'
            : form.recipient_ids.length > 1
              ? 'selection'
              : 'individual';
    },
});

watch(
    () => form.recipient_ids.length,
    (count) => {
        if (form.audience !== 'everyone') {
            form.audience = count > 1 ? 'selection' : 'individual';
        }
    },
);

const search = ref('');
const department = ref('all');

const departments = computed(() => {
    const seen = new Map<number, string>();
    props.agents.forEach((agent) =>
        agent.departments.forEach((item) => seen.set(item.id, item.name)),
    );

    return [...seen.entries()].sort((a, b) => a[1].localeCompare(b[1]));
});

const matches = computed(() => {
    const term = search.value.trim().toLocaleLowerCase('es');

    return props.agents.filter(
        (agent) =>
            (department.value === 'all' ||
                agent.departments.some(
                    (item) => String(item.id) === department.value,
                )) &&
            (term === '' ||
                agent.name.toLocaleLowerCase('es').includes(term) ||
                agent.email.toLocaleLowerCase('es').includes(term)),
    );
});

const chosen = computed(() =>
    props.agents.filter((agent) => form.recipient_ids.includes(agent.id)),
);

function toggle(id: number, checked: boolean): void {
    form.recipient_ids = checked
        ? [...new Set([...form.recipient_ids, id])]
        : form.recipient_ids.filter((item) => item !== id);
}

function chooseVisible(): void {
    form.recipient_ids = [
        ...new Set([
            ...form.recipient_ids,
            ...matches.value.map((agent) => agent.id),
        ]),
    ];
}

const recipientSummary = computed(() => {
    if (everyone.value) {
        return props.agents.length === 1
            ? 'Todos los agentes (1)'
            : `Todos los agentes (${props.agents.length})`;
    }

    return form.recipient_ids.length === 1
        ? '1 agente elegido'
        : `${form.recipient_ids.length} agentes elegidos`;
});

const priorityLabel = computed(
    () =>
        props.options.priorities.find((item) => item.value === form.priority)
            ?.label ?? '',
);

const effectiveTone = computed<AttentionAlertTone>(() =>
    form.sound === 'priority' ? DEFAULT_SOUND[form.priority] : form.sound,
);

/** Plays the effective sound again (the speaker button). */
function testSound(): void {
    previewAttentionAlertSound(effectiveTone.value);
}

/**
 * A sound chosen in the list plays at once, from that same selection (the
 * gesture browsers ask for); the previous one stops first and «Sin sonido»
 * plays nothing. Only a choice of the person sounds: opening or resetting
 * the form does not go through here.
 */
function chooseSound(value: unknown): void {
    if (typeof value !== 'string') {
        return;
    }

    form.sound = value as AttentionAlertSound;
    previewAttentionAlertSound(effectiveTone.value);
}

/** «Todos»: the agents who will get it, by name (the server checks the same list). */
const everyoneNames = computed(() =>
    [...props.agents].sort((a, b) => a.name.localeCompare(b.name, 'es')),
);
const showAllRecipients = ref(false);
const RECIPIENTS_PREVIEW = 6;

const canSubmit = computed(
    () =>
        form.title.trim() !== '' &&
        form.body.trim() !== '' &&
        (everyone.value || form.recipient_ids.length > 0),
);

function submit(): void {
    stopAttentionAlertPreview();
    form.transform((data) => ({
        ...data,
        recipient_ids: data.audience === 'everyone' ? [] : data.recipient_ids,
        // The list shown, so the server refuses it if it changed meanwhile.
        expected_recipient_ids:
            data.audience === 'everyone'
                ? props.agents.map((agent) => agent.id)
                : undefined,
    })).post(storeAlert.url(), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            emit('sent');
        },
        onError: (errors) => {
            // Recipients changed or left the sender's reach: the list on
            // screen is brought up to date for the next attempt.
            if (errors.recipient_ids) {
                router.reload({ only: ['agents'] });
            }
        },
    });
}
</script>

<template>
    <FormDialog
        v-model:open="open"
        title="Enviar alerta de atención"
        description="Llega al momento a los agentes conectados y queda pendiente para quienes no lo están, hasta que la confirman con «Entendido»."
        size="xl"
        :processing="form.processing"
        :dirty="form.isDirty"
        submit-label="Enviar alerta"
        :submit-disabled="!canSubmit"
        @submit="submit"
    >
        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
            <div class="grid content-start gap-5">
                <fieldset class="grid gap-3">
                    <legend class="mb-1 text-sm font-medium">
                        Destinatarios
                    </legend>
                    <Label
                        class="flex min-h-10 cursor-pointer items-center gap-3 rounded-lg border px-3 py-2 text-sm has-[[data-state=checked]]:border-primary/40"
                    >
                        <Checkbox
                            :model-value="everyone"
                            data-alert-everyone
                            @update:model-value="
                                (checked) => (everyone = checked === true)
                            "
                        />
                        <span>
                            Todos los agentes que te corresponden
                            <span
                                class="block text-xs font-normal text-muted-foreground"
                            >
                                {{ agents.length }}
                                {{
                                    agents.length === 1
                                        ? 'destinatario'
                                        : 'destinatarios'
                                }}, conectados o no.
                            </span>
                        </span>
                    </Label>

                    <div
                        v-if="everyone"
                        class="grid gap-2 rounded-lg border bg-muted/40 p-3"
                        data-alert-everyone-recipients
                    >
                        <p class="text-xs font-medium">
                            {{
                                agents.length === 1
                                    ? 'Recibirá la alerta:'
                                    : `Recibirán la alerta (${agents.length}):`
                            }}
                        </p>
                        <ul
                            class="flex max-h-40 flex-wrap gap-1.5 overflow-y-auto"
                            aria-label="Destinatarios de la alerta"
                        >
                            <li
                                v-for="agent in showAllRecipients
                                    ? everyoneNames
                                    : everyoneNames.slice(
                                          0,
                                          RECIPIENTS_PREVIEW,
                                      )"
                                :key="agent.id"
                                class="rounded-full border bg-background px-2.5 py-0.5 text-xs"
                                data-alert-recipient-name
                            >
                                {{ agent.name }}
                            </li>
                        </ul>
                        <Button
                            v-if="everyoneNames.length > RECIPIENTS_PREVIEW"
                            type="button"
                            variant="link"
                            size="sm"
                            class="h-auto justify-self-start p-0 text-xs"
                            :aria-expanded="showAllRecipients"
                            @click="showAllRecipients = !showAllRecipients"
                        >
                            {{
                                showAllRecipients
                                    ? 'Mostrar menos'
                                    : `Ver los ${everyoneNames.length} destinatarios`
                            }}
                            <ChevronDown
                                :class="
                                    cn(
                                        'size-3.5 transition-transform',
                                        showAllRecipients && 'rotate-180',
                                    )
                                "
                                aria-hidden="true"
                            />
                        </Button>
                        <p
                            v-if="agents.length === 0"
                            class="text-xs text-muted-foreground"
                        >
                            No hay agentes que puedan recibir alertas.
                        </p>
                    </div>

                    <template v-if="!everyone">
                        <div class="flex flex-col gap-2 sm:flex-row">
                            <div class="relative flex-1">
                                <Search
                                    class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <Input
                                    v-model="search"
                                    type="search"
                                    class="pl-8"
                                    placeholder="Buscar por nombre o correo"
                                    aria-label="Buscar agentes"
                                />
                            </div>
                            <Select v-model="department">
                                <SelectTrigger
                                    class="sm:w-48"
                                    aria-label="Filtrar por departamento"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all"
                                        >Todos los departamentos</SelectItem
                                    >
                                    <SelectItem
                                        v-for="[id, name] in departments"
                                        :key="id"
                                        :value="String(id)"
                                    >
                                        {{ name }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div
                            v-if="chosen.length > 0"
                            class="flex flex-wrap gap-1.5"
                            aria-label="Agentes elegidos"
                        >
                            <span
                                v-for="agent in chosen"
                                :key="agent.id"
                                class="inline-flex items-center gap-1 rounded-full border bg-primary-soft py-0.5 pr-0.5 pl-2.5 text-xs text-primary-soft-foreground"
                            >
                                {{ agent.name }}
                                <button
                                    type="button"
                                    class="inline-flex size-5 items-center justify-center rounded-full outline-none hover:bg-background/60 focus-visible:ring-2 focus-visible:ring-ring pointer-coarse:size-8"
                                    :aria-label="`Quitar a ${agent.name}`"
                                    @click="toggle(agent.id, false)"
                                >
                                    <X class="size-3.5" aria-hidden="true" />
                                </button>
                            </span>
                        </div>

                        <ul
                            class="grid max-h-56 gap-1 overflow-y-auto rounded-lg border p-1"
                            aria-label="Agentes"
                        >
                            <li v-for="agent in matches" :key="agent.id">
                                <Label
                                    class="flex min-h-10 cursor-pointer items-center gap-3 rounded-md px-2 py-1.5 text-sm font-normal hover:bg-accent"
                                >
                                    <Checkbox
                                        :model-value="
                                            form.recipient_ids.includes(
                                                agent.id,
                                            )
                                        "
                                        @update:model-value="
                                            (checked) =>
                                                toggle(
                                                    agent.id,
                                                    checked === true,
                                                )
                                        "
                                    />
                                    <span class="min-w-0 flex-1">
                                        <span
                                            class="block truncate font-medium"
                                            >{{ agent.name }}</span
                                        >
                                        <span
                                            class="block truncate text-xs text-muted-foreground"
                                        >
                                            {{
                                                agent.departments
                                                    .map((item) => item.name)
                                                    .join(', ') ||
                                                'Sin departamento'
                                            }}
                                        </span>
                                    </span>
                                    <span
                                        v-if="agent.awaiting > 0"
                                        class="shrink-0 rounded-full bg-warning-soft px-2 py-0.5 text-xs text-warning-soft-foreground"
                                    >
                                        {{ agent.awaiting }} esperando
                                    </span>
                                </Label>
                            </li>
                            <li
                                v-if="matches.length === 0"
                                class="px-2 py-3 text-sm text-muted-foreground"
                            >
                                Ningún agente coincide con la búsqueda.
                            </li>
                        </ul>
                        <div
                            class="flex items-center justify-between gap-2 text-xs text-muted-foreground"
                        >
                            <span>{{ recipientSummary }}</span>
                            <Button
                                v-if="matches.length > 0"
                                type="button"
                                variant="link"
                                size="sm"
                                class="h-auto p-0"
                                @click="chooseVisible"
                            >
                                Elegir los {{ matches.length }} de la lista
                            </Button>
                        </div>
                    </template>
                    <InputError
                        :message="
                            form.errors.recipient_ids ??
                            (form.errors as Record<string, string>)
                                .expected_recipient_ids
                        "
                    />
                </fieldset>

                <div class="grid gap-2">
                    <Label for="alert-title">Título</Label>
                    <Input
                        id="alert-title"
                        v-model="form.title"
                        :maxlength="options.title_max"
                        placeholder="Por ejemplo: Clientes esperando respuesta"
                    />
                    <InputError :message="form.errors.title" />
                </div>

                <div class="grid gap-2">
                    <Label for="alert-body">Mensaje</Label>
                    <Textarea
                        id="alert-body"
                        v-model="form.body"
                        rows="4"
                        :maxlength="options.body_max"
                        placeholder="Escribe con claridad qué deben hacer."
                    />
                    <p
                        class="text-right text-xs text-muted-foreground tabular-nums"
                    >
                        {{ form.body.length }} / {{ options.body_max }}
                    </p>
                    <InputError :message="form.errors.body" />
                </div>

                <fieldset class="grid gap-2">
                    <legend class="mb-1 text-sm font-medium">Prioridad</legend>
                    <div
                        class="grid gap-2 sm:grid-cols-3"
                        role="radiogroup"
                        aria-label="Prioridad"
                    >
                        <button
                            v-for="priority in options.priorities"
                            :key="priority.value"
                            type="button"
                            role="radio"
                            :aria-checked="form.priority === priority.value"
                            :class="
                                cn(
                                    'grid gap-1 rounded-lg border p-3 text-left text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                    form.priority === priority.value
                                        ? 'border-primary bg-primary-soft'
                                        : 'hover:bg-accent',
                                )
                            "
                            @click="form.priority = priority.value"
                        >
                            <span class="flex items-center gap-1.5 font-medium">
                                <component
                                    :is="PRIORITY_LOOK[priority.value].icon"
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                {{ priority.label }}
                                <Check
                                    v-if="form.priority === priority.value"
                                    class="ml-auto size-4"
                                    aria-hidden="true"
                                />
                            </span>
                            <span class="text-xs text-muted-foreground">{{
                                priority.description
                            }}</span>
                        </button>
                    </div>
                    <InputError :message="form.errors.priority" />
                </fieldset>

                <div class="grid gap-4 sm:grid-cols-2">
                    <fieldset class="grid gap-2">
                        <legend class="mb-1 text-sm font-medium">
                            Color de fondo
                        </legend>
                        <div
                            class="flex flex-wrap gap-2"
                            role="radiogroup"
                            aria-label="Color de fondo"
                        >
                            <button
                                v-for="color in options.colors"
                                :key="color.value"
                                type="button"
                                role="radio"
                                :aria-checked="form.color === color.value"
                                :aria-label="color.label"
                                :title="color.label"
                                :data-alert-color="color.value"
                                :class="
                                    cn(
                                        'flex size-8 items-center justify-center rounded-full border-2 ring-offset-2 ring-offset-background outline-none focus-visible:ring-2 focus-visible:ring-ring pointer-coarse:size-10',
                                        ALERT_SURFACE[color.value].surface,
                                        form.color === color.value &&
                                            'ring-2 ring-foreground',
                                    )
                                "
                                @click="form.color = color.value"
                            >
                                <Check
                                    v-if="form.color === color.value"
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                <span
                                    v-else
                                    :class="
                                        cn(
                                            'size-2.5 rounded-full',
                                            swatchClasses[color.value].dot,
                                        )
                                    "
                                    aria-hidden="true"
                                />
                            </button>
                        </div>
                    </fieldset>

                    <div class="grid content-start gap-2">
                        <Label>Sonido</Label>
                        <div class="flex gap-2">
                            <Select
                                :model-value="form.sound"
                                @update:model-value="chooseSound"
                            >
                                <SelectTrigger
                                    class="flex-1"
                                    aria-label="Sonido"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="sound in options.sounds"
                                        :key="sound.value"
                                        :value="sound.value"
                                    >
                                        {{ sound.label }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                :disabled="effectiveTone === 'none'"
                                aria-label="Escuchar otra vez"
                                title="Escuchar otra vez"
                                @click="testSound"
                            >
                                <Volume2 aria-hidden="true" />
                            </Button>
                        </div>
                    </div>
                </div>

                <Label
                    class="flex cursor-pointer items-center gap-3 text-sm font-normal"
                >
                    <Checkbox
                        :model-value="form.link_pending"
                        @update:model-value="
                            (checked) => (form.link_pending = checked === true)
                        "
                    />
                    Incluir el botón «Ver mis conversaciones»
                </Label>
            </div>

            <aside
                class="grid content-start gap-2"
                aria-label="Vista previa de la alerta"
            >
                <p class="text-sm font-medium">Así la verán los agentes</p>
                <AttentionAlertCard
                    preview
                    :title="form.title || 'Título de la alerta'"
                    :body="form.body || 'El mensaje aparecerá aquí.'"
                    :priority="form.priority"
                    :priority-label="priorityLabel"
                    :color="form.color"
                    :sender="senderName"
                    :sent-at="null"
                    :link-pending="form.link_pending"
                />
                <p class="text-xs text-muted-foreground">
                    Al elegir un sonido se reproduce para que lo escuches; no se
                    envía nada. El navegador del agente solo reproduce sonidos
                    si ya interactuó con la página; si no puede, la alerta se
                    queda visible con el botón «Activar sonido».
                </p>
            </aside>
        </div>
    </FormDialog>
</template>
