<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    KeyRound,
    MessagesSquare,
    Plus,
    PlugZap,
    Sparkles,
    Store,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import FormField from '@/components/FormField.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import { AppSelect } from '@/components/ui/app-select';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Segmented } from '@/components/ui/segmented';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime } from '@/lib/format';
import { index as aiIndex, test, update } from '@/routes/admin/ai';
import { update as updateConnection } from '@/routes/admin/ai/connections';
import type {
    AiConnection,
    AiLimits,
    AiSettings,
    AiVendor,
    AiVendorValue,
} from '@/types/ai';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Inteligencia artificial', href: aiIndex() }],
    },
});

const props = defineProps<{
    settings: AiSettings;
    vendors: AiVendor[];
    connections: AiConnection[];
    departments: { id: number; name: string }[];
    defaults: { assistant_name: string; handoff_message: string };
    limits: AiLimits;
    activityDays: number;
}>();

const form = useForm({
    provider: props.settings.provider,
    model: props.settings.model,
    api_key: '',
    remove_api_key: false,
    business_info: props.settings.business_info,
    catalog: props.settings.catalog.map((entry) => ({ ...entry })),
    instructions: props.settings.instructions,
    assistant_name: props.settings.assistant_name,
    handoff_message: props.settings.handoff_message,
    max_replies: props.settings.max_replies,
});

const vendor = computed(
    () =>
        props.vendors.find((item) => item.value === form.provider) ??
        props.vendors[0],
);
const vendorOptions = computed(() =>
    props.vendors.map((item) => ({ value: item.value, label: item.label })),
);

/* The saved key only counts for the provider it was saved with. */
const savedKey = computed(
    () =>
        props.settings.has_api_key &&
        !form.remove_api_key &&
        form.provider === props.settings.provider,
);
const replacingKey = ref(!props.settings.has_api_key);

function changeProvider(value: AiVendorValue): void {
    form.provider = value;
    form.model =
        props.vendors.find((item) => item.value === value)?.default_model ?? '';
    replacingKey.value = value !== props.settings.provider || !savedKey.value;
}

function removeKey(): void {
    form.remove_api_key = true;
    form.api_key = '';
    replacingKey.value = true;
}

function keepKey(): void {
    form.remove_api_key = false;
    form.api_key = '';
    replacingKey.value = false;
}

const testing = ref(false);

function testConnection(): void {
    testing.value = true;
    router.post(
        test.url(),
        { provider: form.provider, model: form.model, api_key: form.api_key },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['settings'],
            onFinish: () => (testing.value = false),
        },
    );
}

function addEntry(): void {
    form.catalog.push({ question: '', answer: '' });
}

function moveEntry(index: number, offset: -1 | 1): void {
    const target = index + offset;

    if (target < 0 || target >= form.catalog.length) {
        return;
    }

    const entries = [...form.catalog];
    [entries[index], entries[target]] = [entries[target], entries[index]];
    form.catalog = entries;
}

function removeEntry(index: number): void {
    form.catalog = form.catalog.filter((_, position) => position !== index);
}

function entryError(index: number, field: 'question' | 'answer'): string {
    return (form.errors as Record<string, string>)[`catalog.${index}.${field}`];
}

function save(): void {
    form.put(update.url(), {
        preserveScroll: true,
        onSuccess: () => {
            form.api_key = '';
            form.remove_api_key = false;
            replacingKey.value = !props.settings.has_api_key;
            form.defaults();
        },
    });
}

/* Conexiones: each change is saved at once. */
const busyConnection = ref<number | null>(null);
const departmentOptions = computed(() =>
    props.departments.map((department) => ({
        value: department.id,
        label: department.name,
    })),
);

function saveConnection(
    connection: AiConnection,
    changes: Partial<
        Pick<
            AiConnection,
            'is_enabled' | 'handoff_department_id' | 'only_outside_hours'
        >
    >,
): void {
    busyConnection.value = connection.id;
    router.put(
        updateConnection.url(connection.id),
        {
            is_enabled: connection.is_enabled,
            handoff_department_id: connection.handoff_department_id,
            only_outside_hours: connection.only_outside_hours,
            ...changes,
        },
        {
            preserveScroll: true,
            only: ['connections'],
            onFinish: () => (busyConnection.value = null),
        },
    );
}

const status = computed(() => {
    if (props.settings.last_error) {
        return {
            tone: 'danger' as const,
            text: `Último error: ${props.settings.last_error.message}`,
        };
    }

    if (!props.settings.is_ready) {
        return {
            tone: 'warning' as const,
            text: 'Falta la clave de API o la información del negocio.',
        };
    }

    return {
        tone: 'success' as const,
        text: props.settings.last_reply_at
            ? `Lista · última respuesta ${formatDateTime(props.settings.last_reply_at)}`
            : 'Lista para contestar',
    };
});
</script>

<template>
    <Head title="Inteligencia artificial" />

    <div class="page-shell">
        <PageHeader
            title="Inteligencia artificial"
            description="La IA contesta a tus clientes con la información de tu negocio mientras tu equipo se desocupa."
        >
            <template #actions>
                <Badge :variant="status.tone" class="max-w-full truncate">{{
                    status.text
                }}</Badge>
            </template>
        </PageHeader>

        <form class="grid gap-5" @submit.prevent="save">
            <!-- Cuenta -->
            <section
                class="grid gap-4 rounded-2xl border bg-card p-4 sm:p-5"
                aria-labelledby="ai-account-title"
            >
                <h2
                    id="ai-account-title"
                    class="flex items-center gap-2 font-display text-lg font-semibold"
                >
                    <KeyRound class="size-5 text-primary" aria-hidden="true" />
                    Cuenta
                </h2>

                <Segmented
                    :model-value="form.provider"
                    :options="vendorOptions"
                    aria-label="Proveedor"
                    @update:model-value="
                        (value) => changeProvider(value as AiVendorValue)
                    "
                />

                <FormField
                    id="ai-api-key"
                    label="Clave de API"
                    :error="form.errors.api_key"
                >
                    <div
                        v-if="savedKey && !replacingKey"
                        class="flex flex-wrap items-center gap-2"
                    >
                        <span class="text-sm"
                            >Guardada
                            <span class="font-mono">{{
                                settings.api_key_hint
                            }}</span></span
                        >
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="replacingKey = true"
                            >Reemplazar</Button
                        >
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="removeKey"
                            >Quitar</Button
                        >
                    </div>
                    <div v-else class="flex flex-wrap items-center gap-2">
                        <Input
                            id="ai-api-key"
                            v-model="form.api_key"
                            type="password"
                            autocomplete="off"
                            class="min-w-0 flex-1 basis-64 font-mono"
                            :placeholder="
                                form.provider === 'anthropic'
                                    ? 'sk-ant-…'
                                    : 'sk-…'
                            "
                        />
                        <Button
                            v-if="
                                settings.has_api_key &&
                                form.provider === settings.provider
                            "
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="keepKey"
                            >Conservar la guardada</Button
                        >
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Necesita una clave de
                        <a
                            :href="vendor.console_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="underline underline-offset-2"
                            >{{
                                form.provider === 'anthropic'
                                    ? 'console.anthropic.com'
                                    : 'platform.openai.com'
                            }}</a
                        >; la suscripción de
                        {{
                            form.provider === 'anthropic'
                                ? 'claude.ai'
                                : 'ChatGPT Plus'
                        }}
                        no sirve.
                    </p>
                </FormField>

                <div class="grid gap-4 sm:grid-cols-[1fr_auto] sm:items-end">
                    <FormField
                        id="ai-model"
                        label="Modelo"
                        :error="form.errors.model"
                    >
                        <AppSelect
                            v-if="vendor.models.length > 0"
                            id="ai-model"
                            v-model="form.model"
                            :options="vendor.models"
                            aria-label="Modelo"
                        />
                        <Input
                            v-else
                            id="ai-model"
                            v-model="form.model"
                            :placeholder="vendor.default_model"
                            class="font-mono"
                        />
                    </FormField>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="testing"
                        @click="testConnection"
                    >
                        <PlugZap />
                        {{ testing ? 'Probando…' : 'Probar conexión' }}
                    </Button>
                </div>
            </section>

            <!-- Información del negocio -->
            <section
                class="grid gap-4 rounded-2xl border bg-card p-4 sm:p-5"
                aria-labelledby="ai-business-title"
            >
                <h2
                    id="ai-business-title"
                    class="flex items-center gap-2 font-display text-lg font-semibold"
                >
                    <Store class="size-5 text-primary" aria-hidden="true" />
                    Información del negocio
                </h2>

                <FormField
                    id="ai-business-info"
                    label="Lo que la IA debe saber"
                    hint="Horarios, direcciones, precios, servicios y políticas. Solo contesta con esto."
                    :error="form.errors.business_info"
                >
                    <Textarea
                        id="ai-business-info"
                        v-model="form.business_info"
                        rows="10"
                        :maxlength="limits.business_info"
                        placeholder="Abrimos de lunes a viernes de 9:00 a 18:00. Estamos en…"
                    />
                </FormField>

                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField
                        id="ai-name"
                        label="Nombre del asistente"
                        :error="form.errors.assistant_name"
                    >
                        <Input
                            id="ai-name"
                            v-model="form.assistant_name"
                            maxlength="80"
                            :placeholder="defaults.assistant_name"
                        />
                    </FormField>
                    <FormField
                        id="ai-max"
                        label="Respuestas por conversación"
                        hint="Al llegar al límite pasa con un asesor."
                        :error="form.errors.max_replies"
                    >
                        <Input
                            id="ai-max"
                            v-model.number="form.max_replies"
                            type="number"
                            min="1"
                            :max="limits.max_replies"
                        />
                    </FormField>
                </div>

                <FormField
                    id="ai-tone"
                    label="Tono e indicaciones"
                    :error="form.errors.instructions"
                >
                    <Textarea
                        id="ai-tone"
                        v-model="form.instructions"
                        rows="2"
                        :maxlength="limits.instructions"
                        placeholder="Habla de tú, usa emojis moderados."
                    />
                </FormField>

                <FormField
                    id="ai-handoff"
                    label="Mensaje al pasar con un asesor"
                    :error="form.errors.handoff_message"
                >
                    <Textarea
                        id="ai-handoff"
                        v-model="form.handoff_message"
                        rows="2"
                        :maxlength="limits.handoff"
                        :placeholder="defaults.handoff_message"
                    />
                </FormField>
            </section>

            <!-- Catálogo de respuestas -->
            <section
                class="grid gap-4 rounded-2xl border bg-card p-4 sm:p-5"
                aria-labelledby="ai-catalog-title"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2
                        id="ai-catalog-title"
                        class="flex items-center gap-2 font-display text-lg font-semibold"
                    >
                        <MessagesSquare
                            class="size-5 text-primary"
                            aria-hidden="true"
                        />
                        Catálogo de respuestas
                    </h2>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="form.catalog.length >= limits.catalog"
                        @click="addEntry"
                    >
                        <Plus />
                        Agregar
                    </Button>
                </div>

                <p
                    v-if="form.catalog.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    Preguntas frecuentes con la respuesta exacta que quieres.
                </p>
                <InputError :message="form.errors.catalog" />

                <ol class="grid gap-3">
                    <li
                        v-for="(entry, index) in form.catalog"
                        :key="index"
                        class="grid gap-2 rounded-xl border p-3 sm:grid-cols-[1fr_1.5fr_auto]"
                    >
                        <div class="grid gap-1">
                            <Input
                                v-model="entry.question"
                                :maxlength="limits.question"
                                placeholder="Pregunta"
                                :aria-label="`Pregunta ${index + 1}`"
                            />
                            <InputError
                                :message="entryError(index, 'question')"
                            />
                        </div>
                        <div class="grid gap-1">
                            <Textarea
                                v-model="entry.answer"
                                rows="2"
                                :maxlength="limits.answer"
                                placeholder="Respuesta"
                                :aria-label="`Respuesta ${index + 1}`"
                            />
                            <InputError
                                :message="entryError(index, 'answer')"
                            />
                        </div>
                        <div class="flex items-start gap-1">
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                :disabled="index === 0"
                                :aria-label="`Subir respuesta ${index + 1}`"
                                @click="moveEntry(index, -1)"
                                ><ArrowUp
                            /></Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                :disabled="index === form.catalog.length - 1"
                                :aria-label="`Bajar respuesta ${index + 1}`"
                                @click="moveEntry(index, 1)"
                                ><ArrowDown
                            /></Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                :aria-label="`Eliminar respuesta ${index + 1}`"
                                @click="removeEntry(index)"
                                ><Trash2
                            /></Button>
                        </div>
                    </li>
                </ol>
            </section>

            <div class="flex justify-end">
                <Button
                    type="submit"
                    class="max-sm:w-full"
                    :disabled="form.processing"
                >
                    <Sparkles />
                    Guardar
                </Button>
            </div>
        </form>

        <!-- Conexiones -->
        <section
            class="grid gap-3 rounded-2xl border bg-card p-4 sm:p-5"
            aria-labelledby="ai-connections-title"
        >
            <div class="grid gap-0.5">
                <h2
                    id="ai-connections-title"
                    class="flex items-center gap-2 font-display text-lg font-semibold"
                >
                    <PlugZap class="size-5 text-primary" aria-hidden="true" />
                    Conexiones
                </h2>
                <p class="text-sm text-muted-foreground">
                    Contesta mientras nadie ha tomado la conversación.
                </p>
            </div>

            <p
                v-if="connections.length === 0"
                class="text-sm text-muted-foreground"
            >
                Aún no hay conexiones.
            </p>

            <ul class="grid gap-2">
                <li
                    v-for="connection in connections"
                    :key="connection.id"
                    class="grid gap-3 rounded-xl border p-3 md:grid-cols-[minmax(0,1fr)_auto_14rem_auto] md:items-center"
                    :class="!connection.supported && 'opacity-70'"
                >
                    <div class="min-w-0">
                        <p class="truncate font-medium">
                            {{ connection.name }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ connection.channel
                            }}<template v-if="connection.supported">
                                · {{ connection.replies }} respuestas en
                                {{ activityDays }} días</template
                            >
                        </p>
                    </div>

                    <label class="flex items-center gap-2 text-sm">
                        <Switch
                            :model-value="connection.is_enabled"
                            :disabled="
                                !connection.supported ||
                                busyConnection === connection.id
                            "
                            @update:model-value="
                                (value: boolean) =>
                                    saveConnection(connection, {
                                        is_enabled: value,
                                    })
                            "
                        />
                        La IA contesta
                        <Badge v-if="!connection.supported" variant="muted"
                            >Próximamente</Badge
                        >
                    </label>

                    <AppSelect
                        :model-value="connection.handoff_department_id"
                        :options="departmentOptions"
                        :disabled="
                            !connection.supported ||
                            busyConnection === connection.id
                        "
                        clearable
                        size="sm"
                        placeholder="Pasar a: su departamento"
                        :aria-label="`Departamento al pasar con un asesor en ${connection.name}`"
                        @update:model-value="
                            (value) =>
                                saveConnection(connection, {
                                    handoff_department_id:
                                        (value as number | null) ?? null,
                                })
                        "
                    />

                    <label class="flex items-center gap-2 text-sm">
                        <Switch
                            :model-value="connection.only_outside_hours"
                            :disabled="
                                !connection.supported ||
                                busyConnection === connection.id
                            "
                            @update:model-value="
                                (value: boolean) =>
                                    saveConnection(connection, {
                                        only_outside_hours: value,
                                    })
                            "
                        />
                        Solo fuera de horario
                    </label>
                </li>
            </ul>
        </section>
    </div>
</template>
