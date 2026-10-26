<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Info,
    Lock,
    Power,
    PowerOff,
    RotateCcw,
    ShieldCheck,
    Trash2,
    TriangleAlert,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import AutoMessagePreview from '@/components/connections/AutoMessagePreview.vue';
import ConnectionChecklist from '@/components/connections/ConnectionChecklist.vue';
import ConnectionDiagnosticsCard from '@/components/connections/ConnectionDiagnosticsCard.vue';
import MetaAccountCard from '@/components/connections/MetaAccountCard.vue';
import { connectionFieldHelp as help } from '@/components/connections/connectionFieldHelp';
import ConnectionHealthBadge from '@/components/connections/ConnectionHealthBadge.vue';
import SecretInput from '@/components/connections/SecretInput.vue';
import WebChatPreview from '@/components/connections/WebChatPreview.vue';
import type { WebChatPreviewAppearance } from '@/components/connections/WebChatPreview.vue';
import WebChatSnippet from '@/components/connections/WebChatSnippet.vue';
import WebChatWidgetCard from '@/components/connections/WebChatWidgetCard.vue';
import type { WebChatWidgetOptions } from '@/components/connections/WebChatWidgetCard.vue';
import DeletedBadge from '@/components/DeletedBadge.vue';
import DeletedRecordNotice from '@/components/DeletedRecordNotice.vue';
import FieldHelp from '@/components/FieldHelp.vue';
import FormField from '@/components/FormField.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { useFormValidation } from '@/composables/useFormValidation';
import { useRecordDeletion } from '@/composables/useRecordDeletion';
import { colorPresentations } from '@/lib/colors';
import { connectionDeletionCopy } from '@/lib/deletionCopy';
import { toastFirstError } from '@/lib/toastErrors';
import {
    integerBetween,
    maxLength,
    minLength,
    pattern,
    required,
    when,
} from '@/lib/validation';
import type { FieldRule } from '@/lib/validation';
import {
    destroy as destroyConnection,
    index as connectionsIndex,
    restore as restoreConnection,
    store,
    update,
    verify,
} from '@/routes/connections';
import { update as updateStatus } from '@/routes/connections/status';
import type {
    AppColor,
    Connection,
    ConnectionDiagnostics,
    ConnectionMessageKind,
    ConnectionOption,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Conexiones', href: connectionsIndex() }],
    },
});

const props = defineProps<{
    connection: Connection | null;
    channel: ConnectionOption;
    provider: ConnectionOption;
    departments: {
        id: number;
        name: string;
        color: AppColor;
        is_active: boolean;
    }[];
    limits: { message: number; reopen_window: number };
    /** Graph API version (WhatsApp only). */
    graphVersion: string | null;
    /** Widget choices and limits (web chat only). */
    widgetOptions: WebChatWidgetOptions | null;
    diagnostics: ConnectionDiagnostics | null;
    can: {
        update: boolean;
        changeStatus: boolean;
        verify: boolean;
        delete: boolean;
        restore: boolean;
        syncTemplates: boolean;
        viewTemplates: boolean;
    };
}>();

type ConnectionForm = {
    name: string;
    display_phone_number: string;
    phone_number_id: string;
    business_account_id: string;
    meta_app_id: string;
    access_token: string;
    app_secret: string;
    webhook_verify_token: string;
    department_ids: number[];
    default_department_id: number | null;
    welcome_enabled: boolean;
    welcome_message: string;
    away_enabled: boolean;
    away_message: string;
    farewell_enabled: boolean;
    farewell_message: string;
    reopen_window_minutes: number | null;
};

const connection = computed(() => props.connection);
const isEditing = computed(() => connection.value !== null);
const isWebChat = computed(() => props.channel.value === 'web_chat');
/** Only WhatsApp has Meta identifiers and secrets typed in the form. */
const isWhatsapp = computed(() => props.channel.value === 'whatsapp');
/** Messenger and Instagram are authorized with Meta (OAuth), not typed. */
const isMeta = computed(
    () =>
        props.channel.value === 'messenger' ||
        props.channel.value === 'instagram',
);
const widget = computed(() => connection.value?.web_chat_widget ?? null);

/** Unsaved appearance of the widget card, followed by the live preview. */
const previewAppearance = ref<WebChatPreviewAppearance | null>(null);
const previewDirty = ref(false);

function onWidgetAppearance(
    appearance: WebChatPreviewAppearance,
    dirty: boolean,
): void {
    previewAppearance.value = appearance;
    previewDirty.value = dirty;
}

const META_FIELDS = [
    'display_phone_number',
    'phone_number_id',
    'business_account_id',
    'meta_app_id',
    'access_token',
    'app_secret',
    'webhook_verify_token',
] as const;

const form = useForm<ConnectionForm>({
    name: connection.value?.name ?? '',
    display_phone_number: connection.value?.display_phone_number ?? '',
    phone_number_id: connection.value?.phone_number_id ?? '',
    business_account_id: connection.value?.business_account_id ?? '',
    meta_app_id: connection.value?.meta_app_id ?? '',
    access_token: '',
    app_secret: '',
    webhook_verify_token: '',
    department_ids:
        connection.value?.departments.map((department) => department.id) ?? [],
    default_department_id: connection.value?.default_department_id ?? null,
    welcome_enabled: connection.value?.messages.welcome.enabled ?? false,
    welcome_message: connection.value?.messages.welcome.text ?? '',
    away_enabled: connection.value?.messages.away.enabled ?? false,
    away_message: connection.value?.messages.away.text ?? '',
    farewell_enabled: connection.value?.messages.farewell.enabled ?? false,
    farewell_message: connection.value?.messages.farewell.text ?? '',
    reopen_window_minutes: connection.value?.reopen_window_minutes ?? null,
});

const metaId = (message: string): FieldRule[] => [
    pattern(/^\d{5,64}$/, message),
];

const secret = (
    min: number,
    max: number,
    spacesMessage: string,
): FieldRule[] => [
    minLength(min),
    maxLength(max),
    pattern(/^\S+$/, spacesMessage),
];

const message = (kind: ConnectionMessageKind): FieldRule[] => [
    when(
        (data) => data[`${kind}_enabled`] === true,
        [required('Escribe el mensaje o desactívalo.')],
    ),
    maxLength(props.limits.message),
];

const validation = useFormValidation(form, {
    rules: () => ({
        name: [required('Escribe el nombre de la conexión.'), maxLength(120)],
        display_phone_number: [
            maxLength(32),
            pattern(
                /^\+?[0-9][0-9 ()-]{5,31}$/,
                'Escribe el número con lada internacional, por ejemplo +52 55 1234 5678.',
            ),
        ],
        phone_number_id: metaId(
            'El Phone number ID solo contiene dígitos (de 5 a 64); lo encuentras en WhatsApp Manager o en la configuración de la API de tu app de Meta.',
        ),
        business_account_id: metaId(
            'El ID de la cuenta de WhatsApp Business (WABA ID) solo contiene dígitos (de 5 a 64).',
        ),
        meta_app_id: metaId(
            'El ID de la app de Meta (App ID) solo contiene dígitos (de 5 a 64).',
        ),
        access_token: secret(
            20,
            2000,
            'El token de acceso no debe contener espacios.',
        ),
        app_secret: secret(16, 255, 'El app secret no debe contener espacios.'),
        webhook_verify_token: secret(
            16,
            255,
            'El token de verificación no debe contener espacios.',
        ),
        welcome_message: message('welcome'),
        away_message: message('away'),
        farewell_message: message('farewell'),
        reopen_window_minutes: [integerBetween(1, props.limits.reopen_window)],
    }),
    requireChanges: isEditing,
});

const nativeSelectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50 dark:bg-input/30';

/**
 * Departments that can be offered: active ones, plus inactive ones already
 * authorized (they stay until removed, but cannot be newly added).
 */
const departmentChoices = computed(() =>
    props.departments.filter(
        (department) =>
            department.is_active || form.department_ids.includes(department.id),
    ),
);

const selectedDepartments = computed(() =>
    props.departments.filter((department) =>
        form.department_ids.includes(department.id),
    ),
);

function toggleDepartment(id: number, checked: boolean): void {
    if (checked) {
        form.department_ids = [...form.department_ids, id];

        return;
    }

    form.department_ids = form.department_ids.filter(
        (departmentId) => departmentId !== id,
    );

    if (form.default_department_id === id) {
        form.default_department_id = null;
    }
}

function onDefaultDepartmentChange(event: Event): void {
    const value = (event.target as HTMLSelectElement).value;
    form.default_department_id = value === '' ? null : Number(value);
}

const messageDefinitions: {
    kind: ConnectionMessageKind;
    label: string;
    trigger: string;
    placeholder: string;
}[] = [
    {
        kind: 'welcome',
        label: 'Bienvenida',
        trigger: 'Al iniciar una conversación nueva',
        placeholder:
            '¡Hola! Gracias por escribir a *Mi Empresa*. En breve te atiende un asesor.',
    },
    {
        kind: 'away',
        label: 'Fuera de horario',
        trigger: 'Cuando el cliente escribe fuera del horario de atención',
        placeholder:
            'Nuestro horario es de lunes a viernes de _9:00 a 18:00_. Te responderemos en cuanto abramos.',
    },
    {
        kind: 'farewell',
        label: 'Despedida',
        trigger: 'Cuando un asesor cierra la conversación',
        placeholder:
            'Gracias por contactarnos. Si necesitas algo más, escríbenos aquí.',
    },
];

function messageText(kind: ConnectionMessageKind): string {
    return form[`${kind}_message`];
}

function setMessageText(kind: ConnectionMessageKind, value: string): void {
    form[`${kind}_message`] = value;
}

function messageEnabled(kind: ConnectionMessageKind): boolean {
    return form[`${kind}_enabled`];
}

function setMessageEnabled(kind: ConnectionMessageKind, value: boolean): void {
    form[`${kind}_enabled`] = value;
}

const previewMessages = computed(() =>
    messageDefinitions.map((definition) => ({
        key: definition.kind,
        label: definition.label,
        trigger: definition.trigger,
        text: messageText(definition.kind),
        enabled: messageEnabled(definition.kind),
    })),
);

const reopenEnabled = computed({
    get: () => form.reopen_window_minutes !== null,
    set: (enabled: boolean) => {
        form.reopen_window_minutes = enabled ? 120 : null;
    },
});

const reopenPresets = [
    { minutes: 30, label: '30 min' },
    { minutes: 120, label: '2 horas' },
    { minutes: 1440, label: '24 horas' },
];

function onReopenInput(event: Event): void {
    const value = (event.target as HTMLInputElement).value;
    const parsed = Number(value);
    form.reopen_window_minutes =
        value === '' || !Number.isFinite(parsed) ? null : Math.trunc(parsed);
}

const reopenSummary = computed(() => {
    const minutes = form.reopen_window_minutes;

    if (minutes === null) {
        return 'Cada mensaje después del cierre crea una conversación nueva.';
    }

    if (minutes >= 60 && minutes % 60 === 0) {
        const hours = minutes / 60;

        return `Se reabrirá si el cliente escribe dentro de ${hours} ${hours === 1 ? 'hora' : 'horas'} después del cierre.`;
    }

    return `Se reabrirá si el cliente escribe dentro de ${minutes} minutos después del cierre.`;
});

function submit(): void {
    if (!validation.validate()) {
        toast.error('Revisa los campos marcados.');

        return;
    }

    const options = {
        preserveScroll: true,
        // Saved secrets are never sent back: the fields return to "keep".
        onSuccess: () =>
            validation.load({
                ...form.data(),
                access_token: '',
                app_secret: '',
                webhook_verify_token: '',
            }),
        onError: () => {
            toast.error('Revisa los campos marcados.');
        },
    };

    // The channel is only chosen when creating; a web chat connection has
    // no Meta identifiers or secrets to send.
    form.transform((data) => {
        const payload: Record<string, unknown> = { ...data };

        if (!isWhatsapp.value) {
            META_FIELDS.forEach((field) => delete payload[field]);
        }

        if (!connection.value) {
            payload.channel = props.channel.value;
        }

        return payload;
    });

    if (connection.value) {
        form.submit(update(connection.value.id), options);
    } else {
        form.submit(store(), options);
    }
}

const verifying = ref(false);
const statusProcessing = ref(false);
const confirmDeactivateOpen = ref(false);

const deletion = useRecordDeletion<Connection>({
    destroy: (record) => destroyConnection(record.id),
    restore: (record) => restoreConnection(record.id),
    copy: connectionDeletionCopy,
});

function verifyCredentials(): void {
    if (!connection.value) {
        return;
    }

    router.visit(verify(connection.value.id), {
        preserveScroll: true,
        onStart: () => (verifying.value = true),
        onFinish: () => (verifying.value = false),
        onError: toastFirstError,
    });
}

function changeStatus(active: boolean): void {
    if (!connection.value) {
        return;
    }

    router.visit(updateStatus(connection.value.id), {
        data: { is_active: active },
        preserveScroll: true,
        onStart: () => (statusProcessing.value = true),
        onSuccess: () => (confirmDeactivateOpen.value = false),
        onFinish: () => (statusProcessing.value = false),
        onError: (errors) => {
            confirmDeactivateOpen.value = false;
            toastFirstError(errors);
        },
    });
}

const credentialsUnverified = computed(
    () =>
        connection.value !== null &&
        connection.value.checks.credentials.state !== 'verified',
);

const metaDetails = computed(() => {
    const current = connection.value;

    if (!current || current.checks.credentials.state !== 'verified') {
        return [];
    }

    return [
        { label: 'Nombre verificado', value: current.verified_name },
        { label: 'Número', value: current.display_phone_number },
        { label: 'Estado del número', value: current.meta.phone_status },
        { label: 'Calidad', value: current.meta.quality_rating },
        {
            label: 'Verificación del código',
            value: current.meta.code_verification_status,
        },
        { label: 'Plataforma', value: current.meta.platform_type },
    ].filter((detail) => detail.value);
});

const phoneNotConnected = computed(
    () =>
        connection.value?.checks.credentials.state === 'verified' &&
        connection.value.meta.phone_status !== null &&
        connection.value.meta.phone_status !== 'CONNECTED',
);

const pageTitle = computed(() =>
    connection.value ? connection.value.name : 'Nueva conexión',
);
</script>

<template>
    <Head :title="pageTitle" />

    <form class="flex flex-1 flex-col" novalidate @submit.prevent="submit">
        <div class="page-shell [--page-max:72rem]">
            <PageHeader
                help="conexiones"
                show-title
                :title="pageTitle"
                :description="
                    isEditing
                        ? `${channel.label} · ${provider.label}`
                        : isWebChat
                          ? 'Crea un chat para tu sitio web. Después de guardarlo configurarás sus dominios, su apariencia y el código para insertarlo.'
                          : 'Registra un número de WhatsApp Business conectado mediante la API oficial de Meta.'
                "
            >
                <template #eyebrow>
                    <Link
                        :href="connectionsIndex()"
                        class="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft class="size-4" aria-hidden="true" />
                        Conexiones
                    </Link>
                </template>
                <template v-if="connection" #actions>
                    <DeletedBadge v-if="connection.is_deleted" feminine />
                    <ConnectionHealthBadge :health="connection.health" />
                </template>
            </PageHeader>

            <DeletedRecordNotice
                v-if="connection?.is_deleted"
                title="Esta conexión está eliminada"
                :deleted-at="connection.deleted_at"
            >
                <template v-if="isWebChat">
                    El widget deja de funcionar en los sitios donde está
                    insertado. Sus conversaciones e historial se conservan. Al
                    restaurarla quedará inactiva hasta que la actives.
                </template>
                <template v-else-if="isMeta">
                    No envía ni recibe mensajes y su cuenta de Meta queda
                    reservada. Sus conversaciones e historial se conservan. Al
                    restaurarla quedará inactiva; vuelve a autorizarla si Meta
                    retiró el acceso.
                </template>
                <template v-else>
                    No envía ni recibe mensajes y su Phone number ID queda
                    reservado. Sus conversaciones e historial se conservan. Al
                    restaurarla quedará inactiva y deberás verificar sus
                    credenciales de nuevo.
                </template>
            </DeletedRecordNotice>

            <p
                v-else-if="!can.update"
                class="flex items-center gap-2 rounded-xl border bg-card px-4 py-3 text-sm text-muted-foreground shadow-card"
            >
                <Lock class="size-4" aria-hidden="true" />
                Puedes consultar esta conexión, pero no modificarla.
            </p>

            <div
                class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]"
            >
                <fieldset :disabled="!can.update" class="grid min-w-0 gap-6">
                    <SurfaceCard
                        title="Identificación"
                        description="Cómo reconocerás esta conexión dentro de la plataforma."
                        body-class="grid gap-5 p-5 sm:grid-cols-2"
                    >
                        <FormField
                            id="connection-name"
                            label="Nombre"
                            :error="validation.error('name')"
                            :help="help.name"
                        >
                            <Input
                                id="connection-name"
                                v-bind="
                                    validation.field('name', 'connection-name')
                                "
                                v-model="form.name"
                                required
                                maxlength="120"
                                placeholder="Ventas CDMX"
                            />
                        </FormField>
                        <FormField
                            id="connection-channel"
                            label="Canal"
                            hint="El canal se elige al crear la conexión y no se puede cambiar."
                        >
                            <Input
                                id="connection-channel"
                                :model-value="`${channel.label} · ${provider.label}`"
                                readonly
                                disabled
                            />
                        </FormField>
                        <FormField
                            v-if="isWhatsapp"
                            id="connection-display-phone"
                            label="Número visible (opcional)"
                            :error="validation.error('display_phone_number')"
                            :help="help.displayPhoneNumber"
                            class="sm:col-span-2"
                        >
                            <Input
                                id="connection-display-phone"
                                v-bind="
                                    validation.field(
                                        'display_phone_number',
                                        'connection-display-phone',
                                    )
                                "
                                v-model="form.display_phone_number"
                                inputmode="tel"
                                maxlength="32"
                                placeholder="+52 55 1234 5678"
                            />
                        </FormField>
                    </SurfaceCard>

                    <MetaAccountCard
                        v-if="isMeta && connection && connection.meta_account"
                        :connection="connection"
                        :can-manage="can.update"
                    />

                    <SurfaceCard
                        v-if="isWhatsapp"
                        title="Credenciales de Meta"
                        :description="`Datos de tu app y de tu cuenta de WhatsApp Business. Se consultan con la Graph API ${graphVersion ?? ''}.`"
                        body-class="grid gap-5 p-5"
                    >
                        <div class="grid gap-5 sm:grid-cols-2">
                            <FormField
                                id="connection-phone-number-id"
                                label="ID del número (Phone number ID)"
                                :error="validation.error('phone_number_id')"
                                :help="help.phoneNumberId"
                            >
                                <Input
                                    id="connection-phone-number-id"
                                    v-bind="
                                        validation.field(
                                            'phone_number_id',
                                            'connection-phone-number-id',
                                        )
                                    "
                                    v-model="form.phone_number_id"
                                    inputmode="numeric"
                                    maxlength="64"
                                    class="font-mono"
                                    placeholder="106540352242922"
                                />
                            </FormField>
                            <FormField
                                id="connection-waba-id"
                                label="ID de la cuenta de WhatsApp Business (WABA ID)"
                                :error="validation.error('business_account_id')"
                                :help="help.businessAccountId"
                            >
                                <Input
                                    id="connection-waba-id"
                                    v-bind="
                                        validation.field(
                                            'business_account_id',
                                            'connection-waba-id',
                                        )
                                    "
                                    v-model="form.business_account_id"
                                    inputmode="numeric"
                                    maxlength="64"
                                    class="font-mono"
                                    placeholder="102290129340398"
                                />
                            </FormField>
                            <FormField
                                id="connection-app-id"
                                label="ID de la app de Meta (App ID, opcional)"
                                :error="validation.error('meta_app_id')"
                                :help="help.metaAppId"
                                class="sm:col-span-2"
                            >
                                <Input
                                    id="connection-app-id"
                                    v-bind="
                                        validation.field(
                                            'meta_app_id',
                                            'connection-app-id',
                                        )
                                    "
                                    v-model="form.meta_app_id"
                                    inputmode="numeric"
                                    maxlength="64"
                                    class="font-mono sm:max-w-xs"
                                />
                            </FormField>
                        </div>

                        <div
                            class="flex gap-2 rounded-lg bg-muted/60 px-3 py-2 text-xs text-muted-foreground"
                        >
                            <ShieldCheck
                                class="mt-0.5 size-4 shrink-0"
                                aria-hidden="true"
                            />
                            <p>
                                Los secretos se guardan cifrados y nunca se
                                vuelven a mostrar. Deja un campo vacío para
                                conservar el valor guardado.
                            </p>
                        </div>

                        <FormField
                            id="connection-access-token"
                            label="Token de acceso"
                            :error="validation.error('access_token')"
                            :help="help.accessToken"
                        >
                            <SecretInput
                                id="connection-access-token"
                                v-model="form.access_token"
                                :saved="
                                    connection?.secrets.access_token ?? false
                                "
                                :disabled="!can.update"
                                :field-attrs="
                                    validation.field(
                                        'access_token',
                                        'connection-access-token',
                                    )
                                "
                                :maxlength="2000"
                                placeholder="EAAG…"
                            />
                        </FormField>
                        <div class="grid gap-5 sm:grid-cols-2">
                            <FormField
                                id="connection-app-secret"
                                label="App secret"
                                :error="validation.error('app_secret')"
                                :help="help.appSecret"
                            >
                                <SecretInput
                                    id="connection-app-secret"
                                    v-model="form.app_secret"
                                    :saved="
                                        connection?.secrets.app_secret ?? false
                                    "
                                    :disabled="!can.update"
                                    :field-attrs="
                                        validation.field(
                                            'app_secret',
                                            'connection-app-secret',
                                        )
                                    "
                                    :maxlength="255"
                                />
                            </FormField>
                            <FormField
                                id="connection-verify-token"
                                label="Token de verificación del webhook"
                                :error="
                                    validation.error('webhook_verify_token')
                                "
                                :help="help.webhookVerifyToken"
                            >
                                <SecretInput
                                    id="connection-verify-token"
                                    v-model="form.webhook_verify_token"
                                    :saved="
                                        connection?.secrets
                                            .webhook_verify_token ?? false
                                    "
                                    :disabled="!can.update"
                                    :field-attrs="
                                        validation.field(
                                            'webhook_verify_token',
                                            'connection-verify-token',
                                        )
                                    "
                                    :maxlength="255"
                                    generatable
                                />
                            </FormField>
                        </div>
                    </SurfaceCard>

                    <template v-if="isWebChat">
                        <WebChatWidgetCard
                            v-if="connection && widget && widgetOptions"
                            :connection-id="connection.id"
                            :widget="widget"
                            :options="widgetOptions"
                            :can-update="can.update"
                            @appearance="onWidgetAppearance"
                        />
                        <p
                            v-else-if="!connection"
                            class="flex gap-2 rounded-xl border bg-card px-4 py-3 text-sm text-muted-foreground shadow-card"
                        >
                            <Info
                                class="mt-0.5 size-4 shrink-0"
                                aria-hidden="true"
                            />
                            Al crear la conexión podrás autorizar los dominios
                            de tu sitio, personalizar el widget y copiar el
                            código para insertarlo.
                        </p>
                    </template>

                    <SurfaceCard
                        title="Departamentos"
                        :description="
                            isWebChat
                                ? 'Quién atiende las conversaciones que lleguen por este chat.'
                                : 'Quién atiende las conversaciones que lleguen a este número.'
                        "
                        body-class="grid gap-5 p-5"
                    >
                        <fieldset class="grid gap-2">
                            <legend
                                class="mb-2 flex items-center gap-1.5 text-sm font-medium"
                            >
                                Departamentos autorizados
                                <FieldHelp
                                    title="Departamentos autorizados"
                                    :help="help.departments"
                                />
                            </legend>
                            <div
                                v-if="departmentChoices.length > 0"
                                class="grid gap-2 sm:grid-cols-2"
                            >
                                <label
                                    v-for="department in departmentChoices"
                                    :key="department.id"
                                    class="flex cursor-pointer items-center gap-3 rounded-lg border px-3 py-2 text-sm transition-colors has-[[data-state=checked]]:border-primary/40 has-[[data-state=checked]]:bg-primary-soft/40"
                                >
                                    <Checkbox
                                        :model-value="
                                            form.department_ids.includes(
                                                department.id,
                                            )
                                        "
                                        :disabled="!can.update"
                                        @update:model-value="
                                            (checked) =>
                                                toggleDepartment(
                                                    department.id,
                                                    checked === true,
                                                )
                                        "
                                    />
                                    <span
                                        class="size-2 shrink-0 rounded-full"
                                        v-bind="
                                            colorPresentations(department.color)
                                                .dot
                                        "
                                        aria-hidden="true"
                                    />
                                    <span class="truncate">{{
                                        department.name
                                    }}</span>
                                    <span
                                        v-if="!department.is_active"
                                        class="ml-auto text-xs text-muted-foreground"
                                        >Eliminado</span
                                    >
                                </label>
                            </div>
                            <p v-else class="text-sm text-muted-foreground">
                                No hay departamentos activos. Crea uno en
                                Departamentos para poder autorizarlo.
                            </p>
                            <InputError
                                :message="validation.error('department_ids')"
                            />
                        </fieldset>

                        <FormField
                            id="connection-default-department"
                            label="Departamento predeterminado"
                            :error="validation.error('default_department_id')"
                            :help="help.defaultDepartment"
                        >
                            <select
                                id="connection-default-department"
                                :value="form.default_department_id ?? ''"
                                :class="nativeSelectClass"
                                :disabled="!can.update"
                                @change="onDefaultDepartmentChange"
                            >
                                <option value="">
                                    Usar el predeterminado de la plataforma
                                </option>
                                <option
                                    v-for="department in selectedDepartments"
                                    :key="department.id"
                                    :value="department.id"
                                >
                                    {{ department.name }}
                                </option>
                            </select>
                        </FormField>
                    </SurfaceCard>

                    <SurfaceCard body-class="grid gap-5 p-5">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h2
                                    class="flex items-center gap-1.5 text-sm font-semibold"
                                >
                                    Mensajes automáticos
                                    <FieldHelp
                                        title="Mensajes automáticos"
                                        :help="help.automaticMessages"
                                    />
                                </h2>
                                <p class="text-sm text-muted-foreground">
                                    Respuestas que la plataforma enviará sola.
                                </p>
                            </div>
                        </div>

                        <div
                            class="flex gap-2 rounded-lg border border-primary/20 bg-primary-soft/50 px-3 py-2 text-sm text-primary-soft-foreground"
                            role="note"
                        >
                            <Info
                                class="mt-0.5 size-4 shrink-0"
                                aria-hidden="true"
                            />
                            <p v-if="isWebChat">
                                Estos mensajes se envían solos, solo como texto,
                                y solo mientras la conexión está activa. El
                                mensaje de ausencia o bienvenida del
                                departamento tiene prioridad.
                            </p>
                            <p v-else>
                                Estos mensajes se envían solos, solo como texto,
                                únicamente dentro de la ventana de 24 horas de
                                atención al cliente de WhatsApp y solo mientras
                                la conexión está activa. El mensaje de ausencia
                                o bienvenida del departamento tiene prioridad.
                            </p>
                        </div>

                        <div
                            class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_17rem]"
                        >
                            <div class="grid gap-5">
                                <div
                                    v-for="definition in messageDefinitions"
                                    :key="definition.kind"
                                    class="grid gap-2 rounded-lg border p-4"
                                >
                                    <div
                                        class="flex items-center justify-between gap-3"
                                    >
                                        <div
                                            class="flex min-w-0 items-center gap-1.5"
                                        >
                                            <label
                                                :for="`connection-${definition.kind}-message`"
                                                class="text-sm font-medium"
                                                >{{ definition.label }}</label
                                            >
                                            <FieldHelp
                                                :title="definition.label"
                                                :help="help[definition.kind]"
                                            />
                                        </div>
                                        <Switch
                                            :model-value="
                                                messageEnabled(definition.kind)
                                            "
                                            :disabled="!can.update"
                                            :aria-label="`Activar mensaje de ${definition.label.toLowerCase()}`"
                                            @update:model-value="
                                                (value: boolean) =>
                                                    setMessageEnabled(
                                                        definition.kind,
                                                        value,
                                                    )
                                            "
                                        />
                                    </div>
                                    <p class="text-xs text-muted-foreground">
                                        {{ definition.trigger }}
                                    </p>
                                    <Textarea
                                        :id="`connection-${definition.kind}-message`"
                                        :model-value="
                                            messageText(definition.kind)
                                        "
                                        rows="3"
                                        :maxlength="limits.message"
                                        :placeholder="definition.placeholder"
                                        v-bind="
                                            validation.field(
                                                `${definition.kind}_message`,
                                                `connection-${definition.kind}-message`,
                                            )
                                        "
                                        @update:model-value="
                                            (value: string | number) =>
                                                setMessageText(
                                                    definition.kind,
                                                    String(value),
                                                )
                                        "
                                    />
                                    <div
                                        class="flex items-start justify-between gap-3"
                                    >
                                        <InputError
                                            :id="`connection-${definition.kind}-message-error`"
                                            :message="
                                                validation.error(
                                                    `${definition.kind}_message`,
                                                )
                                            "
                                        />
                                        <span
                                            class="ml-auto shrink-0 text-xs text-muted-foreground tabular-nums"
                                            :class="
                                                messageText(definition.kind)
                                                    .length >
                                                    limits.message * 0.9 &&
                                                'text-amber-600 dark:text-amber-400'
                                            "
                                        >
                                            {{
                                                messageText(definition.kind)
                                                    .length
                                            }}/{{ limits.message }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="xl:sticky xl:top-4 xl:self-start">
                                <AutoMessagePreview
                                    :business-name="
                                        connection?.verified_name ||
                                        form.name ||
                                        ''
                                    "
                                    :messages="previewMessages"
                                />
                            </div>
                        </div>
                    </SurfaceCard>

                    <SurfaceCard body-class="grid gap-4 p-5">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <h2
                                    class="flex items-center gap-1.5 text-sm font-semibold"
                                >
                                    Reapertura
                                    <FieldHelp
                                        title="Ventana de reapertura"
                                        :help="help.reopenWindow"
                                    />
                                </h2>
                                <p class="text-sm text-muted-foreground">
                                    {{ reopenSummary }}
                                </p>
                            </div>
                            <Switch
                                v-model="reopenEnabled"
                                :disabled="!can.update"
                                aria-label="Reabrir conversaciones cerradas recientemente"
                            />
                        </div>
                        <div
                            v-if="reopenEnabled"
                            class="flex flex-wrap items-end gap-3"
                        >
                            <FormField
                                id="connection-reopen-window"
                                label="Minutos"
                                :error="
                                    validation.error('reopen_window_minutes')
                                "
                            >
                                <Input
                                    id="connection-reopen-window"
                                    v-bind="
                                        validation.field(
                                            'reopen_window_minutes',
                                            'connection-reopen-window',
                                        )
                                    "
                                    type="number"
                                    min="1"
                                    :max="limits.reopen_window"
                                    step="1"
                                    class="w-32"
                                    :model-value="
                                        form.reopen_window_minutes ?? ''
                                    "
                                    @input="onReopenInput"
                                />
                            </FormField>
                            <div class="flex flex-wrap gap-2 pb-0.5">
                                <Button
                                    v-for="preset in reopenPresets"
                                    :key="preset.minutes"
                                    type="button"
                                    size="sm"
                                    :variant="
                                        form.reopen_window_minutes ===
                                        preset.minutes
                                            ? 'default'
                                            : 'outline'
                                    "
                                    @click="
                                        form.reopen_window_minutes =
                                            preset.minutes
                                    "
                                >
                                    {{ preset.label }}
                                </Button>
                            </div>
                        </div>
                    </SurfaceCard>
                </fieldset>

                <aside
                    class="grid min-w-0 grid-cols-1 gap-6"
                    :class="!isWebChat && 'lg:sticky lg:top-4'"
                >
                    <SurfaceCard body-class="grid gap-4 p-5">
                        <div class="flex items-center justify-between gap-2">
                            <h2
                                class="flex items-center gap-1.5 text-sm font-semibold"
                            >
                                Estado
                                <FieldHelp
                                    title="Activa o inactiva"
                                    :help="help.active"
                                />
                            </h2>
                            <StatusBadge
                                v-if="connection"
                                :active="connection.is_active"
                                active-label="Activa"
                                inactive-label="Inactiva"
                            />
                        </div>

                        <template v-if="connection">
                            <div class="space-y-1">
                                <ConnectionHealthBadge
                                    :health="connection.configuration_health"
                                />
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        connection.configuration_health
                                            .description
                                    }}
                                </p>
                            </div>

                            <ConnectionChecklist
                                v-if="isWhatsapp"
                                :checks="connection.checks"
                            />

                            <div
                                v-if="phoneNotConnected"
                                class="flex gap-2 rounded-lg bg-warning-soft p-3 text-xs"
                            >
                                <TriangleAlert
                                    class="mt-0.5 size-4 shrink-0 text-warning"
                                    aria-hidden="true"
                                />
                                <p>
                                    Meta reporta el número con estado
                                    <strong>{{
                                        connection.meta.phone_status
                                    }}</strong
                                    >. Debe estar CONNECTED para enviar y
                                    recibir mensajes.
                                </p>
                            </div>

                            <dl
                                v-if="metaDetails.length > 0"
                                class="grid gap-2 border-t pt-4 text-xs"
                            >
                                <div
                                    v-for="detail in metaDetails"
                                    :key="detail.label"
                                    class="flex justify-between gap-3"
                                >
                                    <dt class="text-muted-foreground">
                                        {{ detail.label }}
                                    </dt>
                                    <dd class="truncate text-right font-medium">
                                        {{ detail.value }}
                                    </dd>
                                </div>
                            </dl>

                            <div
                                v-if="
                                    can.verify ||
                                    can.changeStatus ||
                                    can.delete ||
                                    can.restore
                                "
                                class="grid gap-2 border-t pt-4"
                            >
                                <Button
                                    v-if="can.verify"
                                    type="button"
                                    variant="outline"
                                    :disabled="
                                        verifying ||
                                        validation.hasChanges ||
                                        !connection.has_required_credentials
                                    "
                                    @click="verifyCredentials"
                                >
                                    <Spinner v-if="verifying" />
                                    <ShieldCheck v-else />
                                    Verificar credenciales
                                </Button>
                                <p
                                    v-if="can.verify && validation.hasChanges"
                                    class="text-xs text-muted-foreground"
                                >
                                    Guarda los cambios antes de verificar.
                                </p>
                                <p
                                    v-else-if="
                                        can.verify &&
                                        !connection.has_required_credentials
                                    "
                                    class="text-xs text-muted-foreground"
                                >
                                    Guarda el Phone number ID, el WABA ID y el
                                    token de acceso para poder verificar.
                                </p>

                                <template v-if="can.changeStatus">
                                    <Button
                                        v-if="!connection.is_active"
                                        type="button"
                                        :disabled="
                                            statusProcessing ||
                                            !connection.has_required_credentials
                                        "
                                        @click="changeStatus(true)"
                                    >
                                        <Spinner v-if="statusProcessing" />
                                        <Power v-else />
                                        Activar conexión
                                    </Button>
                                    <Button
                                        v-else
                                        type="button"
                                        variant="ghost"
                                        :disabled="statusProcessing"
                                        @click="confirmDeactivateOpen = true"
                                    >
                                        <PowerOff />
                                        Desactivar conexión
                                    </Button>
                                    <p
                                        v-if="
                                            isWebChat &&
                                            !connection.is_active &&
                                            !connection.has_required_credentials
                                        "
                                        class="text-xs text-muted-foreground"
                                    >
                                        Guarda al menos un dominio autorizado en
                                        el widget para poder activarla.
                                    </p>
                                    <p
                                        v-if="
                                            isMeta &&
                                            !connection.is_active &&
                                            !connection.has_required_credentials
                                        "
                                        class="text-xs text-muted-foreground"
                                    >
                                        Vuelve a autorizar la cuenta con Meta
                                        para poder activarla.
                                    </p>
                                    <p
                                        v-if="
                                            !isWebChat &&
                                            !connection.is_active &&
                                            connection.has_required_credentials &&
                                            credentialsUnverified
                                        "
                                        class="flex gap-1.5 text-xs text-amber-700 dark:text-amber-300"
                                    >
                                        <TriangleAlert
                                            class="mt-px size-3.5 shrink-0"
                                            aria-hidden="true"
                                        />
                                        Puedes activarla, pero se recomienda
                                        verificar las credenciales primero.
                                    </p>
                                </template>

                                <Button
                                    v-if="can.restore"
                                    type="button"
                                    @click="deletion.ask('restore', connection)"
                                >
                                    <RotateCcw />
                                    Restaurar conexión
                                </Button>
                                <Button
                                    v-if="can.delete"
                                    type="button"
                                    variant="ghost"
                                    class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                                    @click="deletion.ask('delete', connection)"
                                >
                                    <Trash2 />
                                    Eliminar conexión
                                </Button>
                            </div>
                        </template>

                        <p
                            v-else-if="isWebChat"
                            class="text-sm text-muted-foreground"
                        >
                            La conexión se creará <strong>inactiva</strong>.
                            Después de guardarla podrás configurar el widget y
                            activarla.
                        </p>
                        <p v-else class="text-sm text-muted-foreground">
                            La conexión se creará <strong>inactiva</strong>.
                            Después de guardarla podrás verificar las
                            credenciales con Meta y activarla.
                        </p>
                    </SurfaceCard>

                    <SurfaceCard
                        v-if="isWebChat && widget"
                        body-class="grid gap-5 p-5"
                    >
                        <WebChatSnippet
                            :snippet="widget.snippet"
                            :configured="widget.allowed_origins.length > 0"
                        />
                        <WebChatPreview
                            v-if="previewAppearance"
                            :frame-url="widget.frame_url"
                            :appearance="previewAppearance"
                            :dirty="previewDirty"
                        />
                    </SurfaceCard>

                    <ConnectionDiagnosticsCard
                        v-if="connection && diagnostics"
                        :connection="connection"
                        :diagnostics="diagnostics"
                        :can="can"
                    />
                </aside>
            </div>
        </div>

        <div
            v-if="can.update"
            data-dock-bottom
            class="sticky bottom-0 z-10 flex items-center justify-end gap-3 border-t bg-background/95 px-4 py-3 backdrop-blur md:px-6"
        >
            <span
                v-if="validation.hasChanges"
                class="text-sm text-muted-foreground max-sm:sr-only"
                >Cambios sin guardar</span
            >
            <span
                v-else-if="isEditing"
                class="text-sm text-muted-foreground max-sm:sr-only"
                aria-live="polite"
                >Sin cambios por guardar</span
            >
            <Button
                v-if="isEditing"
                type="button"
                variant="outline"
                :disabled="!validation.hasChanges || form.processing"
                @click="form.reset()"
            >
                Descartar
            </Button>
            <Button v-else as-child variant="outline">
                <Link :href="connectionsIndex()">Cancelar</Link>
            </Button>
            <Button
                type="submit"
                :disabled="
                    form.processing ||
                    (validation.requiresChanges && !validation.hasChanges)
                "
            >
                <Spinner v-if="form.processing" />
                {{ isEditing ? 'Guardar cambios' : 'Crear conexión' }}
            </Button>
        </div>

        <ConfirmDialog
            v-model:open="confirmDeactivateOpen"
            :title="`¿Desactivar ${connection?.name ?? 'la conexión'}?`"
            :description="
                isWebChat
                    ? 'El widget se mostrará fuera de línea y no aceptará mensajes nuevos. La configuración y el historial se conservan.'
                    : 'La plataforma dejará de procesar los mensajes de este número cuando la integración esté habilitada. La configuración y el historial se conservan.'
            "
            confirm-label="Desactivar"
            destructive
            :processing="statusProcessing"
            @confirm="changeStatus(false)"
        />

        <ConfirmDialog
            v-model:open="deletion.open"
            v-bind="deletion.dialog"
            @confirm="deletion.confirm"
        />
    </form>
</template>
