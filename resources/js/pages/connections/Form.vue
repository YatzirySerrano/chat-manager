<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Info,
    KeyRound,
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
import RegisterNumberDialog from '@/components/connections/RegisterNumberDialog.vue';
import SectionNav from '@/components/connections/SectionNav.vue';
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
import FormSection from '@/components/FormSection.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
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
    away_in_open_conversations: boolean;
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
    away_in_open_conversations:
        connection.value?.messages.away.in_open_conversations ?? false,
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

const defaultDepartmentOptions = computed(() => {
    const options = [
        {
            value: null as number | null,
            label: 'Usar el predeterminado de la plataforma',
        },
        ...selectedDepartments.value.map((department) => ({
            value: department.id as number | null,
            label: department.name,
        })),
    ];

    // A saved default that is no longer authorized stays visible, so it can
    // be read and replaced.
    const current = form.default_department_id;

    if (current !== null && !options.some((o) => o.value === current)) {
        const department = props.departments.find((d) => d.id === current);

        options.push({
            value: current,
            label: `${department?.name ?? 'Departamento'} (no autorizado)`,
        });
    }

    return options;
});

const messageDefinitions: {
    kind: ConnectionMessageKind;
    label: string;
    trigger: string;
    placeholder: string;
}[] = [
    {
        kind: 'welcome',
        label: 'Bienvenida',
        trigger:
            'Cuando el cliente inicia una conversación nueva o reabre una cerrada, dentro del horario de atención',
        placeholder:
            '¡Hola! Gracias por escribir a *Mi Empresa*. En breve te atiende un asesor.',
    },
    {
        kind: 'away',
        label: 'Fuera de horario',
        trigger:
            'Cuando el cliente inicia una conversación nueva o reabre una cerrada fuera del horario de atención (requiere «Aplicar horario de atención» en Configuración o un horario propio del departamento)',
        placeholder:
            'Nuestro horario es de lunes a viernes de _9:00 a 18:00_. Te responderemos en cuanto abramos.',
    },
    {
        kind: 'farewell',
        label: 'Despedida',
        trigger:
            'Cuando se cierra la conversación (por un asesor o por inactividad), si el cliente escribió en las últimas 24 horas',
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
const registerOpen = ref(false);
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

const pageTitle = computed(() =>
    connection.value ? connection.value.name : 'Nueva conexión',
);

/** Why an action of the status section is unavailable, or a caution about it. */
const statusHints = computed(() => {
    const current = connection.value;
    const hints: { text: string; warning?: boolean }[] = [];

    if (!current) {
        return hints;
    }

    if (props.can.verify && validation.hasChanges) {
        hints.push({
            text: 'Guarda los cambios antes de verificar las credenciales o registrar el número.',
        });
    } else if (props.can.verify && !current.has_required_credentials) {
        hints.push({
            text: 'Guarda el Phone number ID, el WABA ID y el token de acceso para poder verificar.',
        });
    }

    if (!props.can.changeStatus || current.is_active) {
        return hints;
    }

    if (isWebChat.value && !current.has_required_credentials) {
        hints.push({
            text: 'Guarda al menos un dominio autorizado en el widget para poder activarla.',
        });
    }

    if (isMeta.value && !current.has_required_credentials) {
        hints.push({
            text: 'Vuelve a autorizar la cuenta con Meta para poder activarla.',
        });
    }

    if (
        !isWebChat.value &&
        current.has_required_credentials &&
        credentialsUnverified.value
    ) {
        hints.push({
            text: 'Puedes activarla, pero se recomienda verificar las credenciales primero.',
            warning: true,
        });
    }

    return hints;
});

/** Sections of the page, in order, for the sticky navigation. */
const sections = computed(() => [
    { id: 'diagnostico', label: 'Estado' },
    { id: 'identificacion', label: 'Identificación' },
    ...(isMeta.value && connection.value?.meta_account
        ? [{ id: 'cuenta-meta', label: 'Cuenta de Meta' }]
        : []),
    ...(isWhatsapp.value
        ? [{ id: 'credenciales', label: 'Credenciales' }]
        : []),
    ...(isWebChat.value && connection.value && widget.value
        ? [
              { id: 'codigo', label: 'Instalación' },
              { id: 'widget-dominios', label: 'Dominios' },
              { id: 'widget-apariencia', label: 'Apariencia' },
              { id: 'widget-identificacion', label: 'Visitantes' },
              { id: 'widget-formulario', label: 'Formulario previo' },
              { id: 'widget-disponibilidad', label: 'Disponibilidad' },
          ]
        : []),
    { id: 'departamentos', label: 'Departamentos' },
    { id: 'mensajes', label: 'Mensajes automáticos' },
    { id: 'reapertura', label: 'Reapertura' },
    ...(connection.value && props.diagnostics
        ? [{ id: 'integracion', label: 'Integración' }]
        : []),
]);

const SECTION_FIELDS: Record<string, string[]> = {
    identificacion: ['name', 'display_phone_number'],
    credenciales: [...META_FIELDS].filter(
        (field) => field !== 'display_phone_number',
    ),
    departamentos: ['department_ids', 'default_department_id'],
    mensajes: ['welcome_message', 'away_message', 'farewell_message'],
    reapertura: ['reopen_window_minutes'],
};

/** Sections with a field to review: marked in the navigation. */
const flaggedSections = computed(() =>
    Object.entries(SECTION_FIELDS)
        .filter(([, fields]) =>
            fields.some((field) => Boolean(validation.error(field))),
        )
        .map(([id]) => id),
);
</script>

<template>
    <Head :title="pageTitle" />

    <form class="flex flex-1 flex-col" novalidate @submit.prevent="submit">
        <div
            class="page-shell lg:[&_[data-slot=form-section]>div:first-child]:top-32"
        >
            <PageHeader
                help="conexiones"
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
                        prefetch
                        class="group/back inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft
                            class="size-4 transition-transform duration-(--duration-base) ease-(--ease-spring) group-hover/back:-translate-x-0.5"
                            aria-hidden="true"
                        />
                        Conexiones
                    </Link>
                </template>
                <template v-if="connection" #actions>
                    <DeletedBadge v-if="connection.is_deleted" feminine />
                    <StatusBadge
                        :active="connection.is_active"
                        active-label="Activa"
                        inactive-label="Inactiva"
                    />
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
                class="flex items-center gap-2 rounded-xl border bg-card px-4 py-3 text-sm text-muted-foreground"
            >
                <Lock class="size-4" aria-hidden="true" />
                Puedes consultar esta conexión, pero no modificarla.
            </p>

            <SectionNav
                :sections="sections"
                :flagged="flaggedSections"
                aria-label="Secciones de la conexión"
            />

            <div class="grid gap-10 pt-2">
                <!--
                  Control deck of the connection: what Meta has confirmed,
                  what is missing and every action on the connection itself
                  (apart from the form fields below, which are saved with
                  the bar at the bottom).
                -->
                <FormSection
                    id="diagnostico"
                    class="reveal scroll-mt-32"
                    title="Estado"
                    :description="
                        connection
                            ? connection.configuration_health.description
                            : isWebChat
                              ? 'La conexión se creará inactiva. Después de guardarla podrás configurar el widget y activarla.'
                              : 'La conexión se creará inactiva. Después de guardarla podrás verificar las credenciales con Meta, registrar el número y activarla.'
                    "
                >
                    <template v-if="connection" #aside>
                        <div class="flex flex-wrap items-center gap-2 pt-2">
                            <ConnectionHealthBadge
                                :health="connection.configuration_health"
                            />
                            <FieldHelp
                                title="Activa o inactiva"
                                :help="help.active"
                            />
                        </div>
                    </template>

                    <template v-if="connection">
                        <div class="grid gap-5">
                            <ConnectionChecklist
                                :checks="connection.checks"
                                :monitoring="connection.monitoring"
                                :channel="connection.channel.value"
                            />

                            <dl
                                v-if="metaDetails.length > 0"
                                class="auto-grid border-t pt-5 [--col-min:11rem] [--grid-gap:1rem]"
                            >
                                <div
                                    v-for="detail in metaDetails"
                                    :key="detail.label"
                                    class="min-w-0"
                                >
                                    <dt class="text-xs text-muted-foreground">
                                        {{ detail.label }}
                                    </dt>
                                    <dd
                                        class="truncate font-display text-base font-semibold"
                                        :title="detail.value ?? undefined"
                                    >
                                        {{ detail.value }}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </template>
                    <p
                        v-else
                        class="flex max-w-prose gap-2 text-sm text-muted-foreground"
                    >
                        <Info
                            class="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span v-if="isWebChat">
                            Al crear la conexión podrás autorizar los dominios
                            de tu sitio, personalizar el widget y copiar el
                            código para insertarlo.
                        </span>
                        <span v-else>
                            Aquí verás lo que Meta haya confirmado: las
                            credenciales, el webhook, la suscripción de la app y
                            el estado del número.
                        </span>
                    </p>

                    <template
                        v-if="
                            connection &&
                            (can.verify ||
                                can.changeStatus ||
                                can.delete ||
                                can.restore)
                        "
                        #footer
                    >
                        <Button
                            v-if="can.verify"
                            type="button"
                            variant="outline"
                            :disabled="
                                validation.hasChanges ||
                                !connection.has_required_credentials
                            "
                            :loading="verifying"
                            @click="verifyCredentials"
                        >
                            <ShieldCheck />
                            Verificar credenciales
                        </Button>
                        <Button
                            v-if="can.verify && isWhatsapp"
                            type="button"
                            variant="outline"
                            data-test="register-number"
                            :disabled="
                                validation.hasChanges ||
                                !connection.has_required_credentials
                            "
                            @click="registerOpen = true"
                        >
                            <KeyRound />
                            Registrar número
                        </Button>

                        <template v-if="can.changeStatus">
                            <Button
                                v-if="!connection.is_active"
                                type="button"
                                :disabled="!connection.has_required_credentials"
                                :loading="statusProcessing"
                                @click="changeStatus(true)"
                            >
                                <Power />
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
                            class="text-destructive hover:bg-destructive/10 hover:text-destructive sm:ml-auto"
                            @click="deletion.ask('delete', connection)"
                        >
                            <Trash2 />
                            Eliminar conexión
                        </Button>

                        <div
                            v-if="statusHints.length > 0"
                            class="grid basis-full gap-1"
                        >
                            <p
                                v-for="hint in statusHints"
                                :key="hint.text"
                                class="flex gap-1.5 text-xs"
                                :class="
                                    hint.warning
                                        ? 'text-warning-soft-foreground'
                                        : 'text-muted-foreground'
                                "
                            >
                                <TriangleAlert
                                    v-if="hint.warning"
                                    class="mt-px size-3.5 shrink-0"
                                    aria-hidden="true"
                                />
                                {{ hint.text }}
                            </p>
                        </div>
                    </template>
                </FormSection>

                <fieldset :disabled="!can.update" class="grid min-w-0 gap-10">
                    <FormSection
                        id="identificacion"
                        class="scroll-mt-32"
                        title="Identificación"
                        description="Cómo reconocerás esta conexión dentro de la plataforma."
                    >
                        <div class="field-grid [--col-min:18rem]">
                            <FormField
                                id="connection-name"
                                label="Nombre"
                                :error="validation.error('name')"
                                :help="help.name"
                            >
                                <Input
                                    id="connection-name"
                                    v-bind="
                                        validation.field(
                                            'name',
                                            'connection-name',
                                        )
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
                                :error="
                                    validation.error('display_phone_number')
                                "
                                :help="help.displayPhoneNumber"
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
                        </div>
                    </FormSection>

                    <FormSection
                        v-if="isMeta && connection && connection.meta_account"
                        id="cuenta-meta"
                        class="scroll-mt-32"
                        title="Cuenta de Meta"
                        description="La cuenta que autorizaste para este canal. Vuelve a autorizarla si Meta retiró el acceso."
                        bare
                    >
                        <MetaAccountCard
                            :connection="connection"
                            :can-manage="can.update"
                        />
                    </FormSection>

                    <FormSection
                        v-if="isWhatsapp"
                        id="credenciales"
                        class="scroll-mt-32"
                        title="Credenciales de Meta"
                        :description="`Datos de tu app y de tu cuenta de WhatsApp Business. Se consultan con la Graph API ${graphVersion ?? ''}.`"
                    >
                        <template #aside>
                            <p
                                class="flex max-w-prose gap-2 pt-2 text-xs text-muted-foreground"
                            >
                                <ShieldCheck
                                    class="mt-0.5 size-4 shrink-0 text-primary"
                                    aria-hidden="true"
                                />
                                Los secretos se guardan cifrados y nunca se
                                vuelven a mostrar. Deja un campo vacío para
                                conservar el valor guardado.
                            </p>
                        </template>

                        <div class="field-grid [--col-min:18rem]">
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
                                    class="font-mono"
                                />
                            </FormField>

                            <FormField
                                id="connection-access-token"
                                label="Token de acceso"
                                :error="validation.error('access_token')"
                                :help="help.accessToken"
                                class="col-span-full"
                            >
                                <SecretInput
                                    id="connection-access-token"
                                    v-model="form.access_token"
                                    :saved="
                                        connection?.secrets.access_token ??
                                        false
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
                    </FormSection>

                    <template v-if="isWebChat && connection && widget">
                        <FormSection
                            id="codigo"
                            class="scroll-mt-32"
                            title="Instalación"
                            description="El fragmento que conecta tu sitio con este chat. Solo funciona en los dominios autorizados."
                        >
                            <WebChatSnippet
                                :snippet="widget.snippet"
                                :configured="widget.allowed_origins.length > 0"
                            />
                        </FormSection>

                        <WebChatWidgetCard
                            v-if="widgetOptions"
                            :connection-id="connection.id"
                            :widget="widget"
                            :options="widgetOptions"
                            :can-update="can.update"
                            class="[&>section]:scroll-mt-32"
                            @appearance="onWidgetAppearance"
                        >
                            <template v-if="previewAppearance" #preview>
                                <WebChatPreview
                                    :frame-url="widget.frame_url"
                                    :appearance="previewAppearance"
                                    :dirty="previewDirty"
                                />
                            </template>
                        </WebChatWidgetCard>
                    </template>

                    <FormSection
                        id="departamentos"
                        class="scroll-mt-32"
                        title="Departamentos"
                        :description="
                            isWebChat
                                ? 'Quién atiende las conversaciones que lleguen por este chat.'
                                : 'Quién atiende las conversaciones que lleguen a este número.'
                        "
                    >
                        <div class="grid gap-6">
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
                                    class="auto-grid [--col-min:13rem] [--grid-gap:0.5rem]"
                                >
                                    <label
                                        v-for="department in departmentChoices"
                                        :key="department.id"
                                        class="flex cursor-pointer items-center gap-3 rounded-lg border px-3 py-2.5 text-sm transition-colors hover:border-border-strong has-[[data-state=checked]]:border-primary/50 has-[[data-state=checked]]:bg-primary-soft/40"
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
                                                colorPresentations(
                                                    department.color,
                                                ).dot
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
                                    :message="
                                        validation.error('department_ids')
                                    "
                                />
                            </fieldset>

                            <div class="field-grid [--col-min:18rem]">
                                <FormField
                                    id="connection-default-department"
                                    label="Departamento predeterminado"
                                    :error="
                                        validation.error(
                                            'default_department_id',
                                        )
                                    "
                                    :help="help.defaultDepartment"
                                >
                                    <AppSelect
                                        id="connection-default-department"
                                        v-model="form.default_department_id"
                                        :options="defaultDepartmentOptions"
                                        :disabled="!can.update"
                                    />
                                </FormField>
                            </div>
                        </div>
                    </FormSection>

                    <FormSection
                        id="mensajes"
                        class="scroll-mt-32"
                        title="Mensajes automáticos"
                        description="Respuestas que la plataforma enviará sola."
                        bare
                    >
                        <template #aside>
                            <div class="grid gap-4 pt-2">
                                <div class="flex items-center gap-1.5 text-sm">
                                    <FieldHelp
                                        title="Mensajes automáticos"
                                        :help="help.automaticMessages"
                                    />
                                    <span class="text-muted-foreground"
                                        >Cuándo se envía cada uno</span
                                    >
                                </div>
                                <AutoMessagePreview
                                    :business-name="
                                        connection?.verified_name ||
                                        form.name ||
                                        ''
                                    "
                                    :messages="previewMessages"
                                />
                            </div>
                        </template>

                        <div
                            class="flex gap-2 rounded-xl border border-primary/20 bg-primary-soft/50 px-4 py-3 text-sm text-primary-soft-foreground"
                            role="note"
                        >
                            <Info
                                class="mt-0.5 size-4 shrink-0"
                                aria-hidden="true"
                            />
                            <p v-if="isWebChat" class="max-w-prose">
                                Estos mensajes se envían solos, solo como texto,
                                y solo mientras la conexión está activa. El
                                mensaje de ausencia o bienvenida del
                                departamento tiene prioridad.
                            </p>
                            <p v-else class="max-w-prose">
                                Estos mensajes se envían solos, solo como texto,
                                únicamente dentro de la ventana de 24 horas de
                                atención al cliente de WhatsApp y solo mientras
                                la conexión está activa. El mensaje de ausencia
                                o bienvenida del departamento tiene prioridad.
                            </p>
                        </div>

                        <div
                            class="auto-grid [--col-min:21rem] [--grid-gap:1rem]"
                        >
                            <div
                                v-for="definition in messageDefinitions"
                                :key="definition.kind"
                                class="grid content-start gap-2 rounded-xl border bg-card p-4 transition-colors sm:p-5"
                                :class="
                                    messageEnabled(definition.kind) &&
                                    'border-primary/35'
                                "
                            >
                                <div
                                    class="flex items-center justify-between gap-3"
                                >
                                    <div
                                        class="flex min-w-0 items-center gap-1.5"
                                    >
                                        <label
                                            :for="`connection-${definition.kind}-message`"
                                            class="font-display text-base font-semibold"
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
                                    :model-value="messageText(definition.kind)"
                                    rows="4"
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
                                            'text-warning-soft-foreground'
                                        "
                                    >
                                        {{
                                            messageText(definition.kind).length
                                        }}/{{ limits.message }}
                                    </span>
                                </div>
                                <label
                                    v-if="definition.kind === 'away'"
                                    class="flex items-start justify-between gap-3 rounded-lg bg-surface-sunken px-3 py-2 text-sm"
                                    data-away-in-open
                                >
                                    <span class="min-w-0">
                                        <span class="block font-medium"
                                            >También en conversaciones
                                            abiertas</span
                                        >
                                        <span
                                            class="block text-xs text-muted-foreground"
                                        >
                                            Si el cliente vuelve a escribir
                                            fuera de horario en una conversación
                                            que ya está abierta o en espera,
                                            recibe el mensaje una sola vez por
                                            cada periodo fuera de horario.
                                        </span>
                                    </span>
                                    <Switch
                                        v-model="
                                            form.away_in_open_conversations
                                        "
                                        :disabled="!can.update"
                                        aria-label="Enviar el mensaje fuera de horario también en conversaciones abiertas"
                                    />
                                </label>
                            </div>
                        </div>
                    </FormSection>

                    <FormSection
                        id="reapertura"
                        class="scroll-mt-32"
                        title="Reapertura"
                        :description="reopenSummary"
                    >
                        <template #aside>
                            <div class="pt-1">
                                <FieldHelp
                                    title="Ventana de reapertura"
                                    :help="help.reopenWindow"
                                />
                            </div>
                        </template>
                        <div class="grid gap-5">
                            <label
                                class="flex items-center justify-between gap-4"
                            >
                                <span class="min-w-0">
                                    <span class="block text-sm font-medium"
                                        >Reabrir conversaciones cerradas
                                        recientemente</span
                                    >
                                    <span
                                        class="block text-xs text-muted-foreground"
                                        >Si el cliente escribe poco después del
                                        cierre, vuelve a la misma
                                        conversación.</span
                                    >
                                </span>
                                <Switch
                                    v-model="reopenEnabled"
                                    :disabled="!can.update"
                                    aria-label="Reabrir conversaciones cerradas recientemente"
                                />
                            </label>
                            <div
                                v-if="reopenEnabled"
                                class="flex flex-wrap items-end gap-3 border-t pt-5"
                            >
                                <FormField
                                    id="connection-reopen-window"
                                    label="Minutos"
                                    :error="
                                        validation.error(
                                            'reopen_window_minutes',
                                        )
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
                                        :aria-pressed="
                                            form.reopen_window_minutes ===
                                            preset.minutes
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
                        </div>
                    </FormSection>
                </fieldset>

                <FormSection
                    v-if="connection && diagnostics"
                    id="integracion"
                    class="scroll-mt-32"
                    title="Integración con WhatsApp Cloud API"
                    description="Pasos para activar la conexión, confirmados con Meta."
                >
                    <ConnectionDiagnosticsCard
                        :connection="connection"
                        :diagnostics="diagnostics"
                        :can="can"
                    />
                </FormSection>
            </div>
        </div>

        <div
            v-if="can.update"
            data-dock-bottom
            class="sticky bottom-0 z-10 flex items-center gap-3 border-t bg-background/85 px-4 py-3 backdrop-blur-md md:px-6 lg:px-8 min-[100rem]:px-10"
        >
            <Button
                type="submit"
                :disabled="validation.requiresChanges && !validation.hasChanges"
                :loading="form.processing"
            >
                {{ isEditing ? 'Guardar cambios' : 'Crear conexión' }}
            </Button>
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
            <span
                v-if="validation.hasChanges"
                class="flex items-center gap-2 text-sm text-muted-foreground max-sm:sr-only"
            >
                <span
                    class="size-1.5 rounded-full bg-warning"
                    aria-hidden="true"
                />
                Cambios sin guardar
            </span>
            <span
                v-else-if="isEditing"
                class="text-sm text-muted-foreground max-sm:sr-only"
                aria-live="polite"
                >Sin cambios por guardar</span
            >
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

        <RegisterNumberDialog
            v-if="connection && isWhatsapp && can.verify"
            v-model:open="registerOpen"
            :connection-id="connection.id"
            :display-number="connection.display_phone_number"
            :phone-status="connection.meta.phone_status"
        />
    </form>
</template>
