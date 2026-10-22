<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Braces, Globe, Users } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import DepartmentTag from '@/components/DepartmentTag.vue';
import FieldHelp from '@/components/FieldHelp.vue';
import FormDialog from '@/components/FormDialog.vue';
import FormField from '@/components/FormField.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useFormValidation } from '@/composables/useFormValidation';
import {
    maxLength,
    normalizers,
    pattern,
    required,
    when,
} from '@/lib/validation';
import { store, update } from '@/routes/quick-replies';
import type {
    DepartmentOption,
    QuickReplySummary,
    QuickReplyVariableOption,
} from '@/types';
import QuickReplyPreview from './QuickReplyPreview.vue';
import {
    containsHtmlTag,
    inspectBraces,
    renderTemplate,
} from './whatsappFormat';

const props = defineProps<{
    quickReply: QuickReplySummary | null;
    departments: DepartmentOption[];
    variables: QuickReplyVariableOption[];
    sampleValues: Record<string, string>;
    bodyMaxLength: number;
    canManageGlobal: boolean;
}>();

const open = defineModel<boolean>('open', { required: true });

const isEditing = computed(() => props.quickReply !== null);

const form = useForm<{
    title: string;
    shortcut: string;
    body: string;
    is_global: boolean;
    department_ids: number[];
}>({
    title: '',
    shortcut: '',
    body: '',
    is_global: false,
    department_ids: [],
});

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    const ownDepartments =
        props.departments.length === 1 ? [props.departments[0].id] : [];

    validation.load({
        title: props.quickReply?.title ?? '',
        shortcut: props.quickReply?.shortcut ?? '',
        body: props.quickReply?.body ?? '',
        is_global: props.quickReply?.is_global ?? props.canManageGlobal,
        department_ids:
            props.quickReply?.departments.map((department) => department.id) ??
            ownDepartments,
    });
});

/**
 * Departments that are not deleted plus deleted ones the reply already uses
 * (the server keeps those memberships until the department is restored).
 */
const selectableDepartments = computed(() =>
    props.departments.filter(
        (department) =>
            department.is_active || form.department_ids.includes(department.id),
    ),
);

function toggleDepartment(id: number, checked: boolean): void {
    form.department_ids = checked
        ? [...new Set([...form.department_ids, id])]
        : form.department_ids.filter((departmentId) => departmentId !== id);
}

/**
 * Keep the shortcut in the stored format while typing: lowercase, no "/",
 * no spaces.
 */
function normalizeShortcut(value: string | number): void {
    form.shortcut = String(value)
        .toLowerCase()
        .replace(/^\/+/, '')
        .replace(/\s+/g, '-');
}

const bodyField = ref<InstanceType<typeof Textarea> | null>(null);

async function insertVariable(token: string): Promise<void> {
    const element = bodyField.value?.$el as HTMLTextAreaElement | undefined;
    const start = element?.selectionStart ?? form.body.length;
    const end = element?.selectionEnd ?? form.body.length;

    form.body = form.body.slice(0, start) + token + form.body.slice(end);

    await nextTick();
    element?.focus();
    element?.setSelectionRange(start + token.length, start + token.length);
}

const allowedVariables = computed(() =>
    props.variables.map((variable) => variable.value),
);

const simulateMissingContactName = ref(false);

const preview = computed(() =>
    renderTemplate(
        form.body,
        simulateMissingContactName.value
            ? { ...props.sampleValues, 'contacto.nombre': '' }
            : props.sampleValues,
        allowedVariables.value,
    ),
);

/**
 * Immediate hints; the server validates the same rules on save.
 */
const bodyWarning = computed(() => {
    if (containsHtmlTag(form.body)) {
        return 'El mensaje no puede contener etiquetas HTML. Usa *negritas*, _cursivas_ o ~tachado~.';
    }

    const { unknown, strayBraces } = inspectBraces(
        form.body,
        allowedVariables.value,
    );

    if (unknown.length > 0) {
        return `Variable no reconocida: ${unknown.join(', ')}. Usa los botones para insertar variables válidas.`;
    }

    if (strayBraces) {
        return 'Las llaves { } solo se usan para escribir variables completas.';
    }

    return undefined;
});

const validation = useFormValidation(form, {
    rules: () => ({
        title: [required('Escribe el título.'), maxLength(120)],
        shortcut: [
            required('Escribe el atajo.'),
            maxLength(32),
            pattern(
                /^[a-z0-9][a-z0-9_-]*$/,
                'Usa minúsculas sin acentos, números, guion o guion bajo, empezando con letra o número.',
            ),
        ],
        body: [
            required('Escribe el mensaje.'),
            maxLength(props.bodyMaxLength),
            () => bodyWarning.value,
        ],
        department_ids: [
            when(
                (data) => !data.is_global,
                [required('Elige al menos un departamento.')],
            ),
        ],
    }),
    normalize: { title: normalizers.squish },
    requireChanges: isEditing,
});

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    };

    form.transform((data) => ({
        ...data,
        department_ids: data.is_global ? [] : data.department_ids,
    }));

    if (props.quickReply) {
        form.submit(update(props.quickReply.id), options);
    } else {
        form.submit(store(), options);
    }
}
</script>

<template>
    <FormDialog
        v-model:open="open"
        size="xl"
        :title="
            isEditing ? 'Editar respuesta rápida' : 'Nueva respuesta rápida'
        "
        description="Texto predefinido que el equipo inserta en el chat escribiendo «/» y el atajo. Siempre se puede revisar antes de enviar."
        :processing="form.processing"
        :validation="validation"
        :submit-label="isEditing ? 'Guardar cambios' : 'Crear respuesta'"
        @submit="submit"
    >
        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]">
            <div class="grid content-start gap-5">
                <FormField
                    id="quick-reply-title"
                    label="Título"
                    :error="validation.error('title')"
                    hint="Nombre descriptivo para encontrarla en la lista."
                >
                    <Input
                        id="quick-reply-title"
                        v-model="form.title"
                        maxlength="120"
                        placeholder="Saludo inicial"
                        v-bind="validation.field('title', 'quick-reply-title')"
                    />
                </FormField>

                <FormField
                    id="quick-reply-shortcut"
                    label="Atajo"
                    :error="validation.error('shortcut')"
                    :help="{
                        description:
                            'Palabra que se escribe después de «/» en el chat para insertar esta respuesta.',
                        howTo: 'Usa minúsculas sin acentos, números, guion (-) o guion bajo (_). Debe empezar con letra o número. No escribas la «/».',
                        example: 'saludo',
                        warning:
                            'No puede repetirse con otra respuesta disponible para todos ni con otra que comparta algún departamento.',
                    }"
                >
                    <div class="flex">
                        <span
                            class="inline-flex items-center rounded-l-md border border-r-0 bg-muted px-3 font-mono text-sm text-muted-foreground"
                            aria-hidden="true"
                            >/</span
                        >
                        <Input
                            id="quick-reply-shortcut"
                            :model-value="form.shortcut"
                            maxlength="32"
                            autocomplete="off"
                            spellcheck="false"
                            class="rounded-l-none font-mono"
                            placeholder="saludo"
                            v-bind="
                                validation.field(
                                    'shortcut',
                                    'quick-reply-shortcut',
                                )
                            "
                            @update:model-value="normalizeShortcut"
                        />
                    </div>
                </FormField>

                <div class="grid gap-2">
                    <div class="flex items-center gap-1.5">
                        <Label for="quick-reply-body">Mensaje</Label>
                        <FieldHelp
                            title="Mensaje"
                            :help="{
                                description:
                                    'Texto plano que se insertará en el chat. Admite el formato de WhatsApp: *negritas*, _cursivas_, ~tachado~ y ```monoespaciado```.',
                                howTo: 'Las variables se reemplazan al insertar la respuesta. Si una variable no tiene valor quedará en blanco y se avisará al agente.',
                                example:
                                    'Hola {contacto.nombre}, soy {agente.nombre} de {empresa.nombre}.',
                                warning:
                                    'No se permite HTML ni variables fuera de la lista.',
                            }"
                        />
                    </div>
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span
                            class="inline-flex items-center gap-1 text-xs text-muted-foreground"
                        >
                            <Braces class="size-3.5" aria-hidden="true" />
                            Insertar:
                        </span>
                        <Button
                            v-for="variable in variables"
                            :key="variable.value"
                            type="button"
                            variant="outline"
                            size="sm"
                            class="h-7 px-2 text-xs"
                            :title="`Inserta ${variable.token}`"
                            @click="insertVariable(variable.token)"
                        >
                            {{ variable.label }}
                        </Button>
                        <FieldHelp
                            title="Variables"
                            :help="{
                                description:
                                    'Datos que se completan automáticamente al usar la respuesta.',
                                howTo:
                                    'Coloca el cursor donde quieras el dato y pulsa el botón. Solo existen estas variables: ' +
                                    variables
                                        .map((variable) => variable.token)
                                        .join(', ') +
                                    '.',
                                warning:
                                    'Cualquier otro texto entre llaves será rechazado.',
                            }"
                        />
                    </div>
                    <Textarea
                        id="quick-reply-body"
                        ref="bodyField"
                        v-model="form.body"
                        rows="6"
                        class="max-h-72 min-h-32"
                        placeholder="Hola {contacto.nombre}, gracias por escribirnos."
                        v-bind="validation.field('body', 'quick-reply-body')"
                    />
                    <p
                        v-if="bodyWarning && !validation.error('body')"
                        class="text-xs text-foreground"
                        aria-live="polite"
                    >
                        {{ bodyWarning }}
                    </p>
                    <InputError
                        id="quick-reply-body-error"
                        :message="validation.error('body')"
                    />
                </div>

                <fieldset class="grid gap-3">
                    <legend
                        class="mb-1 flex items-center gap-1.5 text-sm font-medium"
                    >
                        Disponibilidad
                        <FieldHelp
                            title="Disponibilidad"
                            :help="{
                                description:
                                    'Define quién puede usar esta respuesta en sus conversaciones.',
                                howTo: canManageGlobal
                                    ? 'Elige «Disponible para todos los departamentos» o marca los departamentos que la usarán.'
                                    : 'Marca uno o varios de tus departamentos.',
                                warning: canManageGlobal
                                    ? undefined
                                    : 'Solo los usuarios con alcance global pueden publicar respuestas para todos los departamentos.',
                            }"
                        />
                    </legend>
                    <div class="grid gap-2 sm:grid-cols-2" role="radiogroup">
                        <button
                            type="button"
                            role="radio"
                            :aria-checked="form.is_global"
                            :disabled="!canManageGlobal"
                            class="flex items-start gap-3 rounded-lg border p-3 text-left text-sm transition outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
                            :class="
                                form.is_global &&
                                'border-primary bg-primary-soft/60'
                            "
                            @click="form.is_global = true"
                        >
                            <Globe
                                class="mt-0.5 size-4 shrink-0"
                                aria-hidden="true"
                            />
                            <span>
                                <span class="block font-medium"
                                    >Disponible para todos los
                                    departamentos</span
                                >
                                <span class="text-xs text-muted-foreground"
                                    >Cualquier persona con permiso de usar
                                    respuestas rápidas.</span
                                >
                            </span>
                        </button>
                        <button
                            type="button"
                            role="radio"
                            :aria-checked="!form.is_global"
                            class="flex items-start gap-3 rounded-lg border p-3 text-left text-sm transition outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            :class="
                                !form.is_global &&
                                'border-primary bg-primary-soft/60'
                            "
                            @click="form.is_global = false"
                        >
                            <Users
                                class="mt-0.5 size-4 shrink-0"
                                aria-hidden="true"
                            />
                            <span>
                                <span class="block font-medium"
                                    >Departamentos seleccionados</span
                                >
                                <span class="text-xs text-muted-foreground"
                                    >Solo los miembros de los departamentos
                                    elegidos.</span
                                >
                            </span>
                        </button>
                    </div>
                    <InputError :message="validation.error('is_global')" />

                    <template v-if="!form.is_global">
                        <p
                            v-if="selectableDepartments.length === 0"
                            class="rounded-lg border border-dashed p-3 text-sm text-muted-foreground"
                        >
                            No hay departamentos activos disponibles.
                        </p>
                        <div
                            v-else
                            class="grid max-h-44 gap-1 overflow-y-auto rounded-lg border p-1 sm:grid-cols-2"
                        >
                            <Label
                                v-for="department in selectableDepartments"
                                :key="department.id"
                                class="flex cursor-pointer items-center gap-3 rounded-md px-2 py-1.5 font-normal hover:bg-accent"
                            >
                                <Checkbox
                                    :model-value="
                                        form.department_ids.includes(
                                            department.id,
                                        )
                                    "
                                    @update:model-value="
                                        (checked) =>
                                            toggleDepartment(
                                                department.id,
                                                checked === true,
                                            )
                                    "
                                />
                                <DepartmentTag
                                    :name="department.name"
                                    :color="department.color"
                                    :muted="!department.is_active"
                                />
                            </Label>
                        </div>
                        <InputError
                            :message="validation.error('department_ids')"
                        />
                    </template>
                </fieldset>
            </div>

            <aside class="grid content-start gap-2 lg:sticky lg:top-0">
                <p class="text-sm font-medium">Vista previa</p>
                <p class="text-xs text-muted-foreground">
                    Con valores de ejemplo. En el chat se usarán los datos
                    reales de la conversación.
                </p>
                <QuickReplyPreview
                    :text="preview.text"
                    :missing="preview.missing"
                    :variables="variables"
                    :max-length="bodyMaxLength"
                    :caption="form.shortcut ? `/${form.shortcut}` : undefined"
                />
                <Label
                    class="flex cursor-pointer items-center gap-2 text-xs font-normal text-muted-foreground"
                >
                    <Checkbox
                        :model-value="simulateMissingContactName"
                        @update:model-value="
                            (checked) =>
                                (simulateMissingContactName = checked === true)
                        "
                    />
                    Simular un contacto sin nombre registrado
                </Label>
            </aside>
        </div>
    </FormDialog>
</template>
