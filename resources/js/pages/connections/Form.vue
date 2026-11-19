<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Activity,
    ArrowLeft,
    ArrowRightLeft,
    BadgeCheck,
    Bot,
    Braces,
    Building2,
    Check,
    ClipboardList,
    Clock,
    CodeXml,
    DoorClosed,
    Globe,
    Hand,
    IdCard,
    Info,
    KeyRound,
    Lock,
    MessageSquareText,
    MessagesSquare,
    Moon,
    Palette,
    Power,
    PowerOff,
    RotateCcw,
    RotateCcwClock,
    Save,
    ShieldCheck,
    Star,
    Trash2,
    TriangleAlert,
    UserRound,
    UserRoundCheck,
    Users,
    Webhook,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import StatusPill from '@/components/admin/StatusPill.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import ChannelIcon from '@/components/connections/ChannelIcon.vue';
import ConnectionConversationsPanel from '@/components/connections/ConnectionConversationsPanel.vue';
import type { MoveTarget } from '@/components/connections/ConnectionConversationsPanel.vue';
import AutoMessagePreview from '@/components/connections/AutoMessagePreview.vue';
import ConnectionChecklist from '@/components/connections/ConnectionChecklist.vue';
import ConnectionDiagnosticsCard from '@/components/connections/ConnectionDiagnosticsCard.vue';
import MetaAccountCard from '@/components/connections/MetaAccountCard.vue';
import RegisterNumberDialog from '@/components/connections/RegisterNumberDialog.vue';
import SectionNav from '@/components/connections/SectionNav.vue';
import TelegramWebhookPanel from '@/components/connections/TelegramWebhookPanel.vue';
import { connectionFieldHelp as help } from '@/components/connections/connectionFieldHelp';
import ConnectionHealthBadge from '@/components/connections/ConnectionHealthBadge.vue';
import SecretInput from '@/components/connections/SecretInput.vue';
import WebChatPreview from '@/components/connections/WebChatPreview.vue';
import type { WebChatPreviewAppearance } from '@/components/connections/WebChatPreview.vue';
import WebChatSnippet from '@/components/connections/WebChatSnippet.vue';
import WebChatWidgetCard from '@/components/connections/WebChatWidgetCard.vue';
import type { WebChatWidgetOptions } from '@/components/connections/WebChatWidgetCard.vue';
import WhatsAppWebSessionPanel from '@/components/connections/WhatsAppWebSessionPanel.vue';
import DeletedBadge from '@/components/DeletedBadge.vue';
import DeletedRecordNotice from '@/components/DeletedRecordNotice.vue';
import FieldHelp from '@/components/FieldHelp.vue';
import FormField from '@/components/FormField.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import PermanentDeleteDialog from '@/components/PermanentDeleteDialog.vue';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { useFormValidation } from '@/composables/useFormValidation';
import { useRecordDeletion } from '@/composables/useRecordDeletion';
import { defaultSample, renderChatbotText } from '@/lib/chatbotText';
import { colorPresentations } from '@/lib/colors';
import { connectionDeletionCopy } from '@/lib/deletionCopy';
import { toastFirstError } from '@/lib/toastErrors';
import { isWhatsAppWebProvider } from '@/lib/whatsappWeb';
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
    destroyPermanently as destroyConnectionPermanently,
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
    ConnectionChannel,
    ConnectionOption,
    TelegramState,
} from '@/types';
import '@/components/admin/admin.css';
import '@/components/connections/connections.css';

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
    /** Bot and webhook state (Telegram only). */
    telegram?: TelegramState | null;
    can: {
        update: boolean;
        changeStatus: boolean;
        verify: boolean;
        delete: boolean;
        deletePermanently: boolean;
        restore: boolean;
        syncTemplates: boolean;
        viewTemplates: boolean;
        moveConversations: boolean;
    };
    /** Connections of the same channel its conversations can be moved to. */
    moveTargets?: MoveTarget[];
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
    /** Telegram only: write-only, blank keeps the saved one. */
    bot_token: string;
    department_ids: number[];
    default_department_id: number | null;
    welcome_enabled: boolean;
    welcome_message: string;
    away_enabled: boolean;
    away_message: string;
    away_in_open_conversations: boolean;
    farewell_enabled: boolean;
    farewell_message: string;
    agent_assigned_enabled: boolean;
    agent_assigned_message: string;
    department_transfer_enabled: boolean;
    department_transfer_message: string;
    agent_transfer_enabled: boolean;
    agent_transfer_message: string;
    reopen_window_minutes: number | null;
};

/** Texts the action messages start with while never written. */
const ACTION_MESSAGE_DEFAULTS = {
    agent_assigned: 'Hola, soy {{agente}}. ¿En qué te puedo ayudar?',
    department_transfer:
        'Te transferimos a {{departamento}}. En breve te atenderán.',
    agent_transfer:
        'Te comunico con {{agente}}, quien continuará con tu atención.',
} as const;

const connection = computed(() => props.connection);
const isEditing = computed(() => connection.value !== null);
/* The channel option's value is one of the connection channels. */
const channelValue = computed(() => props.channel.value as ConnectionChannel);

const isWebChat = computed(() => props.channel.value === 'web_chat');
/** A Telegram bot: only its token, pasted from @BotFather. */
const isTelegram = computed(() => props.channel.value === 'telegram');
/**
 * WhatsApp through WhatsApp Web (QR): no Meta identifiers, secrets, webhook
 * or templates; a session linked by scanning a code instead.
 */
const isWhatsappWeb = computed(
    () =>
        props.channel.value === 'whatsapp' &&
        isWhatsAppWebProvider(props.provider.value),
);
/** Only WhatsApp Cloud API has Meta identifiers and secrets typed in the form. */
const isWhatsapp = computed(
    () => props.channel.value === 'whatsapp' && !isWhatsappWeb.value,
);
/** Arriving from "Vincular teléfono" in the list opens the code at once. */
const autoLink =
    typeof window !== 'undefined' &&
    new URLSearchParams(window.location.search).has('vincular');
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
    bot_token: '',
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
    // A new web chat introduces the agent by default.
    agent_assigned_enabled:
        connection.value?.messages.agent_assigned.enabled ?? isWebChat.value,
    agent_assigned_message:
        connection.value?.messages.agent_assigned.text ??
        ACTION_MESSAGE_DEFAULTS.agent_assigned,
    department_transfer_enabled:
        connection.value?.messages.department_transfer.enabled ?? false,
    department_transfer_message:
        connection.value?.messages.department_transfer.text ??
        ACTION_MESSAGE_DEFAULTS.department_transfer,
    agent_transfer_enabled:
        connection.value?.messages.agent_transfer.enabled ?? false,
    agent_transfer_message:
        connection.value?.messages.agent_transfer.text ??
        ACTION_MESSAGE_DEFAULTS.agent_transfer,
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
        bot_token: [
            when(
                () => isTelegram.value && !isEditing.value,
                [required('Pega el token que te dio @BotFather.')],
            ),
            maxLength(100),
            pattern(
                /^\d{5,20}:[A-Za-z0-9_-]{30,64}$/,
                'El token no tiene el formato de Telegram (números, dos puntos y letras).',
            ),
        ],
        welcome_message: message('welcome'),
        away_message: message('away'),
        farewell_message: message('farewell'),
        agent_assigned_message: message('agent_assigned'),
        department_transfer_message: message('department_transfer'),
        agent_transfer_message: message('agent_transfer'),
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

/**
 * The default department is picked with the star on an authorized chip;
 * pressing the star of the current default leaves it empty (the platform's
 * default applies).
 */
function toggleDefaultDepartment(id: number): void {
    form.default_department_id = form.default_department_id === id ? null : id;
}

/** A saved default that is no longer authorized: shown so it can be cleared. */
const orphanDefault = computed(() => {
    const current = form.default_department_id;

    if (current === null || form.department_ids.includes(current)) {
        return null;
    }

    return {
        id: current,
        name:
            props.departments.find((department) => department.id === current)
                ?.name ?? 'Departamento',
    };
});

const messageDefinitions: {
    kind: ConnectionMessageKind;
    label: string;
    icon: LucideIcon;
    trigger: string;
    placeholder: string;
    /** Sent after a team action (taking or transferring it). */
    action?: boolean;
}[] = [
    {
        kind: 'welcome',
        label: 'Bienvenida',
        icon: Hand,
        trigger:
            'Al iniciar o reabrir una conversación, dentro del horario de atención.',
        placeholder:
            '¡Hola! Gracias por escribir a *Mi Empresa*. En breve te atiende un asesor.',
    },
    {
        kind: 'away',
        label: 'Fuera de horario',
        icon: Moon,
        trigger:
            'Al iniciar o reabrir una conversación fuera de horario (requiere un horario de atención activo).',
        placeholder:
            'Nuestro horario es de lunes a viernes de _9:00 a 18:00_. Te responderemos en cuanto abramos.',
    },
    {
        kind: 'farewell',
        label: 'Despedida',
        icon: DoorClosed,
        trigger:
            'Al cerrar o resolver la conversación (también por inactividad), si el cliente escribió en las últimas 24 h.',
        placeholder:
            'Gracias por contactarnos. Si necesitas algo más, escríbenos aquí.',
    },
    {
        kind: 'agent_assigned',
        label: 'Agente asignado',
        icon: UserRoundCheck,
        trigger: 'Cuando un agente toma la conversación.',
        placeholder: ACTION_MESSAGE_DEFAULTS.agent_assigned,
        action: true,
    },
    {
        kind: 'department_transfer',
        label: 'Transferido a departamento',
        icon: Building2,
        trigger: 'Al transferirla a la fila de otro departamento.',
        placeholder: ACTION_MESSAGE_DEFAULTS.department_transfer,
        action: true,
    },
    {
        kind: 'agent_transfer',
        label: 'Transferido a otro agente',
        icon: ArrowRightLeft,
        trigger: 'Cuando otro agente continúa la atención.',
        placeholder: ACTION_MESSAGE_DEFAULTS.agent_transfer,
        action: true,
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

/* A phone linked by QR has no 24 hour window: closing always says goodbye. */
function triggerFor(definition: {
    kind: ConnectionMessageKind;
    trigger: string;
}): string {
    return (isWhatsappWeb.value || isTelegram.value) &&
        definition.kind === 'farewell'
        ? 'Al cerrar o resolver la conversación; por inactividad, solo si el cliente escribió en las últimas 24 h.'
        : definition.trigger;
}

const messageVariables = [
    '{{nombre}}',
    '{{departamento}}',
    '{{conexion}}',
    '{{saludo}}',
    '{{agente}}',
];

const enabledMessages = computed(
    () =>
        messageDefinitions.filter((definition) =>
            messageEnabled(definition.kind),
        ).length,
);

/** One line under the title; the details live in the help next to it. */
const automaticMessagesNote = computed(() =>
    isWhatsappWeb.value
        ? 'Se envían solos, como texto, desde el teléfono vinculado.'
        : isWebChat.value || isTelegram.value
          ? 'Se envían solos, como texto, con la conexión activa.'
          : 'Se envían solos, como texto, dentro de la ventana de 24 h de WhatsApp.',
);

const automaticMessagesHelp = computed(() => ({
    ...help.automaticMessages,
    warning: isWhatsappWeb.value
        ? 'Solo como texto, desde el teléfono vinculado y mientras la sesión está conectada. El mensaje de ausencia o bienvenida del departamento tiene prioridad.'
        : isWebChat.value || isTelegram.value
          ? 'Solo como texto y mientras la conexión está activa. El mensaje de ausencia o bienvenida del departamento tiene prioridad.'
          : `${help.automaticMessages.warning} El mensaje de ausencia o bienvenida del departamento tiene prioridad.`,
}));

/* Variables ({{nombre}}…) are shown with sample values, as the chatbot does. */
const previewSample = computed(() =>
    defaultSample({ conexion: form.name.trim() || 'Tu conexión' }),
);

/* Action messages join the preview once turned on, so it stays short. */
const previewMessages = computed(() =>
    messageDefinitions
        .filter(
            (definition) =>
                !definition.action || messageEnabled(definition.kind),
        )
        .map((definition) => ({
            key: definition.kind,
            label: definition.label,
            trigger: triggerFor(definition),
            text: renderChatbotText(
                messageText(definition.kind),
                previewSample.value,
            ),
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

/** The web chat card saves on its own request; the bar below saves it too. */
const widgetCard = ref<InstanceType<typeof WebChatWidgetCard> | null>(null);
const widgetDirty = ref(false);

/** Something to save: the connection, its widget, or both. */
const pageDirty = computed(() => validation.hasChanges || widgetDirty.value);

function discardAll(): void {
    form.reset();
    widgetCard.value?.discard();
}

function submit(): void {
    // Only the widget changed: save it, the connection has nothing new.
    if (connection.value && !validation.hasChanges && widgetDirty.value) {
        widgetCard.value?.save();

        return;
    }

    if (!validation.validate()) {
        toast.error('Revisa los campos marcados.');

        return;
    }

    const options = {
        preserveScroll: true,
        // Saved secrets are never sent back: the fields return to "keep".
        onSuccess: () => {
            validation.load({
                ...form.data(),
                access_token: '',
                app_secret: '',
                webhook_verify_token: '',
                bot_token: '',
            });

            // Then the widget, after the connection (one request at a time).
            if (widgetDirty.value) {
                widgetCard.value?.save();
            }
        },
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

        // Blank keeps the saved token; other channels have none.
        if (!isTelegram.value || payload.bot_token === '') {
            delete payload.bot_token;
        }

        if (!connection.value) {
            payload.channel = props.channel.value;

            // The way of connecting is also chosen only when creating.
            if (isWhatsappWeb.value) {
                payload.provider = props.provider.value;
            }
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
const purgeOpen = ref(false);
/** «¿Transferir mensajes a otra conexión?» of the permanent deletion. */
const purgeTransfer = ref<'yes' | 'no'>('yes');
const purgeTarget = ref<number | null>(null);
const purgeShowArchived = ref(true);

const hasConversations = computed(
    () => (connection.value?.conversations_count ?? 0) > 0,
);

const purgeTargetOptions = computed(() =>
    (props.moveTargets ?? []).map((target) => ({
        value: target.id,
        label: target.name,
        description: [
            target.provider,
            target.phone,
            target.active ? null : 'inactiva',
        ]
            .filter(Boolean)
            .join(' · '),
    })),
);

function openPurge(): void {
    const targets = props.moveTargets ?? [];
    purgeTransfer.value =
        hasConversations.value && targets.length > 0 ? 'yes' : 'no';
    purgeTarget.value = targets.length === 1 ? targets[0].id : null;
    purgeShowArchived.value = true;
    purgeOpen.value = true;
}

const purgeTargetName = computed(
    () =>
        (props.moveTargets ?? []).find(
            (target) => target.id === purgeTarget.value,
        )?.name ?? 'la conexión que elijas',
);

const transfersOnPurge = computed(
    () => hasConversations.value && purgeTransfer.value === 'yes',
);

const purgeErased = computed(() => [
    'La conexión y su configuración (credenciales, plantillas, widget y registro de webhooks)',
    ...(transfersOnPurge.value || !hasConversations.value
        ? []
        : [
              `${(connection.value?.conversations_count ?? 0).toLocaleString('es-MX')} conversaciones con todos sus mensajes, archivos, notas e historial, incluido lo importado de Whaticket`,
          ]),
]);

const purgeKept = computed(() => [
    ...(transfersOnPurge.value
        ? [
              `Sus ${(connection.value?.conversations_count ?? 0).toLocaleString('es-MX')} conversaciones, con todos sus mensajes, pasan a ${purgeTargetName.value}`,
          ]
        : []),
    'Los contactos, sus notas y sus otras conversaciones',
]);

const purgeData = computed(() =>
    purgeTransfer.value === 'yes' && purgeTarget.value !== null
        ? {
              transfer_to: purgeTarget.value,
              show_archived: purgeShowArchived.value,
          }
        : {},
);

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

    if (
        !current ||
        isTelegram.value ||
        current.checks.credentials.state !== 'verified'
    ) {
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

const departmentsSummary = computed(() => {
    const count = form.department_ids.length;

    return count === 0
        ? 'Sin autorizar'
        : `${count} ${count === 1 ? 'autorizado' : 'autorizados'}`;
});

/** What happens after creating it, shown in place of the status. */
const nextSteps = computed(() =>
    isWebChat.value
        ? [
              'Guarda la conexión.',
              'Autoriza tus dominios y personaliza el widget.',
              'Copia el código y actívala.',
          ]
        : isWhatsappWeb.value
          ? [
                'Guarda la conexión.',
                'Escanea el código QR con el teléfono.',
                'Actívala.',
            ]
          : isTelegram.value
            ? [
                  'Pega el token de @BotFather y guarda.',
                  'El webhook se conecta solo.',
                  'Actívala.',
              ]
            : [
                  'Guarda la conexión con sus credenciales.',
                  'Verifícalas con Meta y registra el número.',
                  'Actívala.',
              ],
);

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

    // A QR connection has nothing to verify with Meta: its session is linked
    // in the panel above.
    if (isWhatsappWeb.value) {
        if (
            props.can.changeStatus &&
            !current.is_active &&
            !current.has_required_credentials
        ) {
            hints.push({ text: 'Vincula el teléfono para poder activarla.' });
        }

        return hints;
    }

    if (isTelegram.value) {
        if (
            props.can.changeStatus &&
            !current.is_active &&
            !current.has_required_credentials
        ) {
            hints.push({
                text: 'Guarda el token del bot para poder activarla.',
            });
        }

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
const sections = computed<{ id: string; label: string; icon: LucideIcon }[]>(
    () => [
        { id: 'diagnostico', label: 'Estado', icon: Activity },
        { id: 'identificacion', label: 'Identificación', icon: IdCard },
        ...(isMeta.value && connection.value?.meta_account
            ? [{ id: 'cuenta-meta', label: 'Cuenta de Meta', icon: BadgeCheck }]
            : []),
        ...(isWhatsapp.value
            ? [{ id: 'credenciales', label: 'Credenciales', icon: KeyRound }]
            : []),
        ...(isTelegram.value
            ? [{ id: 'bot-telegram', label: 'Bot', icon: Bot }]
            : []),
        ...(isWebChat.value && connection.value && widget.value
            ? [
                  { id: 'codigo', label: 'Instalación', icon: CodeXml },
                  { id: 'widget-dominios', label: 'Dominios', icon: Globe },
                  {
                      id: 'widget-apariencia',
                      label: 'Apariencia',
                      icon: Palette,
                  },
                  {
                      id: 'widget-identificacion',
                      label: 'Visitantes',
                      icon: UserRound,
                  },
                  {
                      id: 'widget-formulario',
                      label: 'Formulario previo',
                      icon: ClipboardList,
                  },
                  {
                      id: 'widget-disponibilidad',
                      label: 'Disponibilidad',
                      icon: Clock,
                  },
              ]
            : []),
        { id: 'departamentos', label: 'Departamentos', icon: Users },
        {
            id: 'mensajes',
            label: 'Mensajes automáticos',
            icon: MessageSquareText,
        },
        { id: 'reapertura', label: 'Reapertura', icon: RotateCcwClock },
        ...(connection.value
            ? [
                  {
                      id: 'conversaciones',
                      label: 'Conversaciones',
                      icon: MessagesSquare,
                  },
              ]
            : []),
        ...(connection.value && props.diagnostics && !isWhatsappWeb.value
            ? [{ id: 'integracion', label: 'Integración', icon: Webhook }]
            : []),
    ],
);

/**
 * Whether the status section has buttons of its own. A QR connection has no
 * credentials to verify, and its deletion sits in the session panel, next
 * to the other two ways of ending the session.
 */
const showStatusActions = computed(
    () =>
        connection.value !== null &&
        (props.can.changeStatus ||
            props.can.restore ||
            props.can.deletePermanently ||
            (!isWhatsappWeb.value && (props.can.verify || props.can.delete))),
);

const SECTION_FIELDS: Record<string, string[]> = {
    identificacion: ['name', 'display_phone_number'],
    credenciales: [...META_FIELDS].filter(
        (field) => field !== 'display_phone_number',
    ),
    'bot-telegram': ['bot_token'],
    departamentos: ['department_ids', 'default_department_id'],
    mensajes: [
        'welcome_message',
        'away_message',
        'farewell_message',
        'agent_assigned_message',
        'department_transfer_message',
        'agent_transfer_message',
    ],
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
            class="connection-form page-shell"
            :data-channel-theme="channel.value"
        >
            <PageHeader
                help="conexiones"
                :title="pageTitle"
                :description="
                    isEditing
                        ? `${channel.label} · ${provider.label}`
                        : isWebChat
                          ? 'Chat para tu sitio web.'
                          : isTelegram
                            ? 'Un bot de Telegram creado con @BotFather.'
                            : isWhatsappWeb
                              ? 'WhatsApp normal vinculado por código QR.'
                              : 'WhatsApp Business por la API oficial de Meta.'
                "
            >
                <template #eyebrow>
                    <div class="flex flex-wrap items-center gap-3">
                        <Link
                            :href="connectionsIndex()"
                            prefetch
                            class="group/back inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                        >
                            <ArrowLeft class="size-4" aria-hidden="true" />
                            Conexiones
                        </Link>
                        <span class="h-4 w-px bg-border" aria-hidden="true" />
                        <span
                            class="inline-flex items-center gap-2 text-sm font-medium text-muted-foreground"
                        >
                            <span
                                class="channel-tile size-7 rounded-lg"
                                aria-hidden="true"
                            >
                                <ChannelIcon
                                    :channel="channelValue"
                                    class="size-3.5"
                                />
                            </span>
                            {{ channel.label }}
                        </span>
                        <span
                            v-if="connection?.telegram_bot?.username"
                            class="text-sm text-muted-foreground"
                            data-test="telegram-username"
                            >@{{ connection.telegram_bot.username }}</span
                        >
                    </div>
                </template>
                <template v-if="connection" #actions>
                    <DeletedBadge v-if="connection.is_deleted" feminine />
                    <StatusPill
                        :label="connection.is_active ? 'Activa' : 'Inactiva'"
                        :tone="connection.is_active ? 'success' : 'muted'"
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
                    El widget dejó de funcionar; el historial se conserva. Al
                    restaurarla quedará inactiva.
                </template>
                <template v-else-if="isWhatsappWeb">
                    La sesión se cerró; el historial se conserva. Al restaurarla
                    quedará inactiva y habrá que vincular el teléfono de nuevo.
                </template>
                <template v-else-if="isTelegram">
                    El bot dejó de recibir; el historial se conserva. Al
                    restaurarla quedará inactiva.
                </template>
                <template v-else-if="isMeta">
                    No envía ni recibe; el historial se conserva. Al restaurarla
                    quedará inactiva; vuelve a autorizarla si Meta retiró el
                    acceso.
                </template>
                <template v-else>
                    No envía ni recibe y su Phone number ID queda reservado; el
                    historial se conserva. Al restaurarla quedará inactiva y
                    deberás verificar sus credenciales.
                </template>
            </DeletedRecordNotice>

            <p
                v-else-if="!can.update"
                class="flex items-center gap-2 rounded-xl border bg-card px-4 py-3 text-sm text-muted-foreground shadow-card"
            >
                <Lock class="size-4 shrink-0" aria-hidden="true" />
                Solo lectura: puedes consultar esta conexión, pero no
                modificarla.
            </p>

            <SectionNav
                :sections="sections"
                :flagged="flaggedSections"
                aria-label="Secciones de la conexión"
                class="xl:hidden"
            />

            <div
                class="grid items-start gap-6 xl:grid-cols-[13.5rem_minmax(0,1fr)] xl:gap-8"
            >
                <!-- Side index (wide screens): where am I, and one click to any section. -->
                <aside
                    class="sticky top-24 hidden max-h-[calc(100dvh-8rem)] min-w-0 gap-4 overflow-y-auto xl:grid"
                    aria-label="Resumen de la conexión"
                >
                    <div
                        class="flex min-w-0 items-center gap-2.5 rounded-xl border bg-card p-3 shadow-card"
                    >
                        <span
                            class="channel-tile size-9 shrink-0 rounded-lg"
                            aria-hidden="true"
                        >
                            <ChannelIcon
                                :channel="channelValue"
                                class="size-4"
                            />
                        </span>
                        <div class="min-w-0">
                            <p
                                class="truncate text-sm font-semibold"
                                :title="form.name || pageTitle"
                            >
                                {{ form.name.trim() || pageTitle }}
                            </p>
                            <p class="truncate text-xs text-muted-foreground">
                                {{ provider.label }}
                            </p>
                        </div>
                    </div>
                    <SectionNav
                        vertical
                        :sections="sections"
                        :flagged="flaggedSections"
                        aria-label="Secciones de la conexión"
                    />
                </aside>

                <div class="grid min-w-0 gap-6">
                    <p
                        v-if="isWhatsappWeb"
                        class="flex items-center gap-2 rounded-xl border border-primary/20 bg-primary-soft/50 px-4 py-2.5 text-sm text-primary-soft-foreground"
                        role="note"
                        data-test="whatsapp-web-manual-only"
                    >
                        <UserRoundCheck
                            class="size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span class="min-w-0">
                            Modo QR: sin plantillas, campañas ni envíos masivos.
                            Solo salen mensajes de tu equipo, los automáticos y
                            el chatbot.
                        </span>
                    </p>

                    <!--
                      Control deck of the connection: what Meta has confirmed,
                      what is missing and every action on the connection itself
                      (apart from the form fields below, which are saved with
                      the bar at the bottom).
                    -->
                    <SettingsSection
                        id="diagnostico"
                        class="scroll-mt-32 xl:scroll-mt-24"
                        :icon="Activity"
                        title="Estado"
                        :description="
                            connection
                                ? connection.configuration_health.description
                                : 'Se creará inactiva. Estos son los pasos.'
                        "
                    >
                        <template v-if="connection" #actions>
                            <ConnectionHealthBadge
                                :health="connection.configuration_health"
                            />
                            <FieldHelp
                                title="Activa o inactiva"
                                :help="help.active"
                            />
                        </template>

                        <div v-if="connection" class="grid gap-5">
                            <WhatsAppWebSessionPanel
                                v-if="isWhatsappWeb"
                                :connection-id="connection.id"
                                :connection-name="connection.name"
                                :summary="connection.whatsapp_web"
                                :deleted="connection.is_deleted"
                                :can-delete="can.delete"
                                :auto-link="autoLink"
                                @delete="deletion.ask('delete', connection)"
                            />
                            <TelegramWebhookPanel
                                v-if="isTelegram && telegram"
                                :connection-id="connection.id"
                                :telegram="telegram"
                                :can-manage="can.update"
                                :deleted="connection.is_deleted"
                            />
                            <ConnectionChecklist
                                :class="
                                    (isWhatsappWeb || isTelegram) &&
                                    'border-t pt-5'
                                "
                                :checks="connection.checks"
                                :monitoring="connection.monitoring"
                                :channel="connection.channel.value"
                                :provider="connection.provider.value"
                            />

                            <dl
                                v-if="metaDetails.length > 0"
                                class="auto-grid border-t pt-5 [--col-min:10rem] [--grid-gap:0.625rem]"
                            >
                                <div
                                    v-for="detail in metaDetails"
                                    :key="detail.label"
                                    class="min-w-0 rounded-lg bg-surface-sunken/60 px-3 py-2.5"
                                >
                                    <dt class="text-xs text-muted-foreground">
                                        {{ detail.label }}
                                    </dt>
                                    <dd
                                        class="truncate text-sm font-semibold"
                                        :title="detail.value ?? undefined"
                                    >
                                        {{ detail.value }}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                        <ol
                            v-else
                            class="grid gap-3 sm:grid-cols-3"
                            aria-label="Pasos después de crearla"
                        >
                            <li
                                v-for="(step, index) in nextSteps"
                                :key="step"
                                class="flex items-start gap-3 rounded-xl bg-surface-sunken/55 p-3 ring-1 ring-border/50 ring-inset"
                            >
                                <span
                                    class="grid size-7 shrink-0 place-items-center rounded-full font-display text-sm font-semibold tabular-nums"
                                    :class="
                                        index === 0
                                            ? 'bg-primary text-primary-foreground'
                                            : 'bg-card text-muted-foreground shadow-(--shadow-control)'
                                    "
                                    aria-hidden="true"
                                    >{{ index + 1 }}</span
                                >
                                <span class="min-w-0 pt-0.5 text-sm">{{
                                    step
                                }}</span>
                            </li>
                        </ol>

                        <template
                            v-if="connection && showStatusActions"
                            #footer
                        >
                            <Button
                                v-if="can.verify && !isWhatsappWeb"
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
                                    :disabled="
                                        !connection.has_required_credentials
                                    "
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
                                v-if="can.delete && !isWhatsappWeb"
                                type="button"
                                variant="ghost"
                                class="text-destructive hover:bg-destructive/10 hover:text-destructive sm:ml-auto"
                                @click="deletion.ask('delete', connection)"
                            >
                                <Trash2 />
                                Eliminar conexión
                            </Button>
                            <Button
                                v-if="can.deletePermanently"
                                type="button"
                                variant="destructive"
                                data-test="purge-connection"
                                :class="
                                    (!can.delete || isWhatsappWeb) &&
                                    'sm:ml-auto'
                                "
                                @click="openPurge"
                            >
                                <Trash2 />
                                Eliminar definitivamente
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
                                    <Info
                                        v-else
                                        class="mt-px size-3.5 shrink-0"
                                        aria-hidden="true"
                                    />
                                    {{ hint.text }}
                                </p>
                            </div>
                        </template>
                    </SettingsSection>

                    <fieldset
                        :disabled="!can.update"
                        class="grid min-w-0 gap-6"
                    >
                        <SettingsSection
                            id="identificacion"
                            class="scroll-mt-32 xl:scroll-mt-24"
                            :icon="IdCard"
                            title="Identificación"
                            description="Cómo la reconoce tu equipo. Los clientes no ven el nombre."
                        >
                            <div class="field-grid [--col-min:15rem]">
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
                                    hint="No se puede cambiar."
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
                        </SettingsSection>

                        <SettingsSection
                            v-if="
                                isMeta && connection && connection.meta_account
                            "
                            id="cuenta-meta"
                            class="scroll-mt-32 xl:scroll-mt-24"
                            :icon="BadgeCheck"
                            title="Cuenta de Meta"
                            description="Vuelve a autorizarla si Meta retiró el acceso."
                            bare
                        >
                            <MetaAccountCard
                                :connection="connection"
                                :can-manage="can.update"
                            />
                        </SettingsSection>

                        <SettingsSection
                            v-if="isTelegram"
                            id="bot-telegram"
                            class="scroll-mt-32 xl:scroll-mt-24"
                            :icon="Bot"
                            title="Bot de Telegram"
                            description="Créalo en Telegram con @BotFather y pega aquí su token."
                        >
                            <FormField
                                id="connection-bot-token"
                                label="Token del bot"
                                :error="validation.error('bot_token')"
                                :hint="
                                    connection
                                        ? 'Déjalo vacío para conservar el guardado.'
                                        : 'Se guarda cifrado y no se vuelve a mostrar.'
                                "
                            >
                                <SecretInput
                                    id="connection-bot-token"
                                    v-model="form.bot_token"
                                    :saved="
                                        connection?.secrets.access_token ??
                                        false
                                    "
                                    :disabled="!can.update"
                                    :field-attrs="
                                        validation.field(
                                            'bot_token',
                                            'connection-bot-token',
                                        )
                                    "
                                    :maxlength="100"
                                    placeholder="123456789:AA…"
                                />
                            </FormField>
                        </SettingsSection>

                        <SettingsSection
                            v-if="isWhatsapp"
                            id="credenciales"
                            class="scroll-mt-32 xl:scroll-mt-24"
                            :icon="KeyRound"
                            title="Credenciales de Meta"
                            :description="
                                graphVersion
                                    ? `Datos de tu app y tu cuenta de WhatsApp Business (Graph API ${graphVersion}).`
                                    : 'Datos de tu app y tu cuenta de WhatsApp Business.'
                            "
                        >
                            <template #actions>
                                <span
                                    class="admin-pill"
                                    data-tone="success"
                                    title="Los secretos se guardan cifrados y nunca se vuelven a mostrar."
                                >
                                    <Lock class="size-3" aria-hidden="true" />
                                    Secretos cifrados
                                </span>
                            </template>

                            <div class="grid gap-6">
                                <div class="grid gap-3">
                                    <h3
                                        class="text-xs font-medium text-muted-foreground"
                                    >
                                        Identificadores
                                    </h3>
                                    <div class="field-grid [--col-min:15rem]">
                                        <FormField
                                            id="connection-phone-number-id"
                                            label="ID del número (Phone number ID)"
                                            :error="
                                                validation.error(
                                                    'phone_number_id',
                                                )
                                            "
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
                                            :error="
                                                validation.error(
                                                    'business_account_id',
                                                )
                                            "
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
                                                v-model="
                                                    form.business_account_id
                                                "
                                                inputmode="numeric"
                                                maxlength="64"
                                                class="font-mono"
                                                placeholder="102290129340398"
                                            />
                                        </FormField>
                                        <FormField
                                            id="connection-app-id"
                                            label="ID de la app de Meta (App ID, opcional)"
                                            :error="
                                                validation.error('meta_app_id')
                                            "
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
                                    </div>
                                </div>

                                <div class="grid gap-3 border-t pt-5">
                                    <h3
                                        class="flex flex-wrap items-baseline gap-x-2 text-xs font-medium text-muted-foreground"
                                    >
                                        Secretos
                                        <span class="font-normal"
                                            >Déjalos vacíos para conservar los
                                            guardados.</span
                                        >
                                    </h3>
                                    <div class="field-grid [--col-min:18rem]">
                                        <FormField
                                            id="connection-access-token"
                                            label="Token de acceso"
                                            :error="
                                                validation.error('access_token')
                                            "
                                            :help="help.accessToken"
                                            class="col-span-full"
                                        >
                                            <SecretInput
                                                id="connection-access-token"
                                                v-model="form.access_token"
                                                :saved="
                                                    connection?.secrets
                                                        .access_token ?? false
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
                                            :error="
                                                validation.error('app_secret')
                                            "
                                            :help="help.appSecret"
                                        >
                                            <SecretInput
                                                id="connection-app-secret"
                                                v-model="form.app_secret"
                                                :saved="
                                                    connection?.secrets
                                                        .app_secret ?? false
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
                                                validation.error(
                                                    'webhook_verify_token',
                                                )
                                            "
                                            :help="help.webhookVerifyToken"
                                        >
                                            <SecretInput
                                                id="connection-verify-token"
                                                v-model="
                                                    form.webhook_verify_token
                                                "
                                                :saved="
                                                    connection?.secrets
                                                        .webhook_verify_token ??
                                                    false
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
                                </div>
                            </div>
                        </SettingsSection>

                        <template v-if="isWebChat && connection && widget">
                            <SettingsSection
                                id="codigo"
                                class="scroll-mt-32 xl:scroll-mt-24"
                                :icon="CodeXml"
                                title="Instalación"
                                description="Solo funciona en los dominios autorizados."
                            >
                                <WebChatSnippet
                                    :snippet="widget.snippet"
                                    :configured="
                                        widget.allowed_origins.length > 0
                                    "
                                />
                            </SettingsSection>

                            <WebChatWidgetCard
                                v-if="widgetOptions"
                                :connection-id="connection.id"
                                :widget="widget"
                                :options="widgetOptions"
                                :can-update="can.update"
                                class="gap-6! [&>section]:scroll-mt-32 xl:[&>section]:scroll-mt-24"
                                ref="widgetCard"
                                @appearance="onWidgetAppearance"
                                @dirty="(dirty) => (widgetDirty = dirty)"
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

                        <SettingsSection
                            id="departamentos"
                            class="scroll-mt-32 xl:scroll-mt-24"
                            :icon="Users"
                            title="Departamentos"
                            description="Quién atiende lo que llega aquí."
                        >
                            <template #actions>
                                <StatusPill
                                    :label="departmentsSummary"
                                    :tone="
                                        form.department_ids.length > 0
                                            ? 'primary'
                                            : 'muted'
                                    "
                                />
                            </template>
                            <fieldset class="grid gap-3">
                                <legend
                                    class="mb-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm font-medium"
                                >
                                    <span
                                        class="inline-flex items-center gap-1.5"
                                    >
                                        Autorizados
                                        <FieldHelp
                                            title="Departamentos autorizados"
                                            :help="help.departments"
                                        />
                                    </span>
                                    <span
                                        class="inline-flex items-center gap-1 text-xs font-normal text-muted-foreground"
                                    >
                                        <Star
                                            class="size-3.5 fill-current text-warning"
                                            aria-hidden="true"
                                        />
                                        Predeterminado
                                        <FieldHelp
                                            title="Departamento predeterminado"
                                            :help="help.defaultDepartment"
                                        />
                                    </span>
                                </legend>
                                <ul
                                    v-if="departmentChoices.length > 0"
                                    class="auto-grid [--col-min:12rem] [--grid-gap:0.5rem]"
                                    role="list"
                                >
                                    <li
                                        v-for="department in departmentChoices"
                                        :key="department.id"
                                        class="flex items-center rounded-lg border transition-colors"
                                        :class="
                                            form.department_ids.includes(
                                                department.id,
                                            )
                                                ? [
                                                      'border-transparent',
                                                      colorPresentations(
                                                          department.color,
                                                      ).soft.class,
                                                  ]
                                                : 'hover:border-border-strong'
                                        "
                                        :style="
                                            form.department_ids.includes(
                                                department.id,
                                            )
                                                ? colorPresentations(
                                                      department.color,
                                                  ).soft.style
                                                : undefined
                                        "
                                    >
                                        <label
                                            class="flex min-w-0 flex-1 items-center gap-2.5 py-2.5 pr-1 pl-3 text-sm font-medium"
                                            :class="
                                                can.update
                                                    ? 'cursor-pointer'
                                                    : 'cursor-default'
                                            "
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
                                                class="size-2.5 shrink-0 rounded-full"
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
                                                class="text-xs font-normal opacity-75"
                                                >(eliminado)</span
                                            >
                                        </label>
                                        <button
                                            v-if="
                                                form.department_ids.includes(
                                                    department.id,
                                                )
                                            "
                                            type="button"
                                            class="mr-1.5 flex size-8 shrink-0 items-center justify-center rounded-md transition-colors outline-none hover:bg-background/60 focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none"
                                            :aria-pressed="
                                                form.default_department_id ===
                                                department.id
                                            "
                                            :aria-label="`Predeterminado: ${department.name}`"
                                            :title="
                                                form.default_department_id ===
                                                department.id
                                                    ? 'Predeterminado (quitar)'
                                                    : 'Hacer predeterminado'
                                            "
                                            :disabled="!can.update"
                                            @click="
                                                toggleDefaultDepartment(
                                                    department.id,
                                                )
                                            "
                                        >
                                            <Star
                                                class="size-4"
                                                :class="
                                                    form.default_department_id ===
                                                    department.id
                                                        ? 'fill-current'
                                                        : 'opacity-40'
                                                "
                                                aria-hidden="true"
                                            />
                                        </button>
                                    </li>
                                </ul>
                                <p
                                    v-else
                                    class="rounded-lg border border-dashed px-3 py-4 text-center text-sm text-muted-foreground"
                                >
                                    No hay departamentos activos.
                                </p>
                                <p
                                    v-if="
                                        form.default_department_id === null &&
                                        form.department_ids.length > 0
                                    "
                                    class="text-xs text-muted-foreground"
                                >
                                    Sin estrella: usa el predeterminado de la
                                    plataforma.
                                </p>
                                <p
                                    v-if="orphanDefault"
                                    class="flex flex-wrap items-center gap-1.5 text-xs text-warning-soft-foreground"
                                    role="status"
                                >
                                    <TriangleAlert
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    {{ orphanDefault.name }} es el
                                    predeterminado pero no está autorizado.
                                    <Button
                                        v-if="can.update"
                                        type="button"
                                        variant="link"
                                        size="sm"
                                        class="h-auto p-0 text-xs"
                                        @click="
                                            form.default_department_id = null
                                        "
                                    >
                                        Quitar
                                    </Button>
                                </p>
                                <InputError
                                    :message="
                                        validation.error('department_ids')
                                    "
                                />
                                <InputError
                                    :message="
                                        validation.error(
                                            'default_department_id',
                                        )
                                    "
                                />
                            </fieldset>
                        </SettingsSection>

                        <SettingsSection
                            id="mensajes"
                            class="scroll-mt-32 xl:scroll-mt-24"
                            :icon="MessageSquareText"
                            title="Mensajes automáticos"
                            :description="automaticMessagesNote"
                        >
                            <template #actions>
                                <StatusPill
                                    :label="`${enabledMessages} de ${messageDefinitions.length} activos`"
                                    :tone="
                                        enabledMessages > 0
                                            ? 'primary'
                                            : 'muted'
                                    "
                                />
                                <FieldHelp
                                    title="Mensajes automáticos"
                                    :help="automaticMessagesHelp"
                                />
                            </template>

                            <div
                                class="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_17rem]"
                            >
                                <div class="grid min-w-0 gap-3">
                                    <p
                                        class="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground"
                                        data-test="automatic-message-variables"
                                    >
                                        <Braces
                                            class="size-3.5 shrink-0"
                                            aria-hidden="true"
                                        />
                                        Variables:
                                        <code
                                            v-for="variable in messageVariables"
                                            :key="variable"
                                            class="rounded-md bg-muted px-1.5 py-0.5 font-mono text-[0.6875rem] text-foreground"
                                            >{{ variable }}</code
                                        >
                                    </p>

                                    <template
                                        v-for="definition in messageDefinitions"
                                        :key="definition.kind"
                                    >
                                        <p
                                            v-if="
                                                definition.kind ===
                                                'agent_assigned'
                                            "
                                            class="mt-3 text-xs font-semibold text-muted-foreground"
                                            data-test="action-messages-heading"
                                        >
                                            Al tomar o transferir la
                                            conversación
                                        </p>
                                        <div
                                            class="connection-message grid content-start gap-3 rounded-xl border bg-card p-3 transition-[border-color,box-shadow] duration-(--duration-base) sm:p-4"
                                            :data-enabled="
                                                messageEnabled(definition.kind)
                                                    ? ''
                                                    : undefined
                                            "
                                        >
                                            <div class="flex items-start gap-3">
                                                <span
                                                    class="admin-icon-tile size-8 max-sm:hidden"
                                                    aria-hidden="true"
                                                >
                                                    <component
                                                        :is="definition.icon"
                                                        class="size-4"
                                                    />
                                                </span>
                                                <div
                                                    class="grid min-w-0 flex-1"
                                                >
                                                    <div
                                                        class="flex min-w-0 items-center gap-1.5"
                                                    >
                                                        <label
                                                            :for="`connection-${definition.kind}-message`"
                                                            class="font-display text-base font-semibold"
                                                            >{{
                                                                definition.label
                                                            }}</label
                                                        >
                                                        <FieldHelp
                                                            :title="
                                                                definition.label
                                                            "
                                                            :help="
                                                                help[
                                                                    definition
                                                                        .kind
                                                                ]
                                                            "
                                                        />
                                                    </div>
                                                    <p
                                                        class="text-xs text-muted-foreground"
                                                    >
                                                        {{
                                                            triggerFor(
                                                                definition,
                                                            )
                                                        }}
                                                    </p>
                                                </div>
                                                <Switch
                                                    :model-value="
                                                        messageEnabled(
                                                            definition.kind,
                                                        )
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
                                            <div class="grid gap-1.5">
                                                <Textarea
                                                    :id="`connection-${definition.kind}-message`"
                                                    :model-value="
                                                        messageText(
                                                            definition.kind,
                                                        )
                                                    "
                                                    :rows="
                                                        definition.action
                                                            ? 2
                                                            : 3
                                                    "
                                                    :maxlength="limits.message"
                                                    :placeholder="
                                                        definition.placeholder
                                                    "
                                                    v-bind="
                                                        validation.field(
                                                            `${definition.kind}_message`,
                                                            `connection-${definition.kind}-message`,
                                                        )
                                                    "
                                                    @update:model-value="
                                                        (
                                                            value:
                                                                string | number,
                                                        ) =>
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
                                                            messageText(
                                                                definition.kind,
                                                            ).length >
                                                                limits.message *
                                                                    0.9 &&
                                                            'text-warning-soft-foreground'
                                                        "
                                                    >
                                                        {{
                                                            messageText(
                                                                definition.kind,
                                                            ).length
                                                        }}/{{ limits.message }}
                                                    </span>
                                                </div>
                                            </div>
                                            <label
                                                v-if="
                                                    definition.kind === 'away'
                                                "
                                                class="flex items-center justify-between gap-3 rounded-lg bg-surface-sunken px-3 py-2 text-sm"
                                                data-away-in-open
                                            >
                                                <span class="min-w-0">
                                                    <span
                                                        class="block font-medium"
                                                        >También en
                                                        conversaciones
                                                        abiertas</span
                                                    >
                                                    <span
                                                        class="block text-xs text-muted-foreground"
                                                    >
                                                        Una vez por cada periodo
                                                        fuera de horario.
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
                                    </template>
                                </div>

                                <div class="min-w-0 xl:sticky xl:top-24">
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
                        </SettingsSection>

                        <SettingsSection
                            id="reapertura"
                            class="scroll-mt-32 xl:scroll-mt-24"
                            :icon="RotateCcwClock"
                            title="Reapertura"
                            :description="reopenSummary"
                        >
                            <template #actions>
                                <FieldHelp
                                    title="Ventana de reapertura"
                                    :help="help.reopenWindow"
                                />
                            </template>
                            <div class="grid gap-4">
                                <label
                                    class="flex items-center justify-between gap-4 rounded-lg bg-surface-sunken px-3 py-2.5"
                                >
                                    <span class="text-sm font-medium"
                                        >Reabrir conversaciones cerradas
                                        recientemente</span
                                    >
                                    <Switch
                                        v-model="reopenEnabled"
                                        :disabled="!can.update"
                                        aria-label="Reabrir conversaciones cerradas recientemente"
                                    />
                                </label>
                                <div
                                    v-if="reopenEnabled"
                                    class="flex flex-wrap items-end gap-3"
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
                                    <div
                                        class="flex flex-wrap gap-2 pb-0.5"
                                        role="group"
                                        aria-label="Atajos de la ventana"
                                    >
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
                        </SettingsSection>
                    </fieldset>

                    <SettingsSection
                        v-if="connection"
                        id="conversaciones"
                        class="scroll-mt-32 xl:scroll-mt-24"
                        :icon="MessagesSquare"
                        title="Conversaciones"
                        description="Ábrelas o muévelas en bloque a otra conexión. Incluye lo importado de Whaticket."
                    >
                        <ConnectionConversationsPanel
                            :connection-id="connection.id"
                            :can-move="can.moveConversations"
                            :targets="moveTargets ?? []"
                        />
                    </SettingsSection>

                    <SettingsSection
                        v-if="connection && diagnostics && !isWhatsappWeb"
                        id="integracion"
                        class="scroll-mt-32 xl:scroll-mt-24"
                        :icon="Webhook"
                        title="Integración con WhatsApp Cloud API"
                        description="Pasos para activarla, confirmados con Meta."
                    >
                        <ConnectionDiagnosticsCard
                            :connection="connection"
                            :diagnostics="diagnostics"
                            :can="can"
                        />
                    </SettingsSection>
                </div>
            </div>
        </div>

        <div
            v-if="can.update"
            data-dock-bottom
            class="sticky bottom-0 z-10 flex flex-wrap items-center gap-x-3 gap-y-2 border-t bg-background/95 px-4 py-3 backdrop-blur md:px-6 lg:px-8 min-[100rem]:px-10"
        >
            <span
                v-if="pageDirty"
                class="flex items-center gap-2 text-sm font-medium text-warning-soft-foreground max-sm:sr-only"
            >
                <span
                    class="live-dot size-1.5 rounded-full bg-warning text-warning"
                    aria-hidden="true"
                />
                {{
                    validation.hasChanges
                        ? 'Cambios sin guardar'
                        : 'Cambios del widget sin guardar'
                }}
            </span>
            <span
                v-else-if="isEditing"
                class="flex items-center gap-2 text-sm text-muted-foreground max-sm:sr-only"
                aria-live="polite"
                ><Check class="size-4" aria-hidden="true" />Sin cambios por
                guardar</span
            >
            <div class="flex flex-1 items-center gap-3 sm:ml-auto sm:flex-none">
                <Button
                    v-if="isEditing"
                    type="button"
                    variant="outline"
                    class="max-sm:flex-1"
                    :disabled="!pageDirty || form.processing"
                    @click="discardAll"
                >
                    Descartar
                </Button>
                <Button v-else as-child variant="outline" class="max-sm:flex-1">
                    <Link :href="connectionsIndex()">Cancelar</Link>
                </Button>
                <Button
                    type="submit"
                    class="max-sm:flex-1"
                    :disabled="validation.requiresChanges && !pageDirty"
                    :loading="form.processing"
                >
                    <Save />
                    {{ isEditing ? 'Guardar cambios' : 'Crear conexión' }}
                </Button>
            </div>
        </div>

        <ConfirmDialog
            v-model:open="confirmDeactivateOpen"
            :title="`¿Desactivar ${connection?.name ?? 'la conexión'}?`"
            :description="
                isWebChat
                    ? 'El widget se mostrará fuera de línea y no aceptará mensajes nuevos. La configuración y el historial se conservan.'
                    : isWhatsappWeb
                      ? 'La plataforma dejará de enviar por este número. La vinculación del teléfono, la configuración y el historial se conservan.'
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

        <PermanentDeleteDialog
            v-if="connection && can.deletePermanently"
            v-model:open="purgeOpen"
            :title="`¿Eliminar ${connection.name} definitivamente?`"
            description="La conexión deja de funcionar al instante y se borra en segundo plano. A diferencia de «Eliminar conexión», no se puede restaurar."
            :erased="purgeErased"
            :kept="purgeKept"
            :blocked="
                purgeTransfer === 'yes' &&
                hasConversations &&
                purgeTarget === null
            "
            :url="destroyConnectionPermanently.url(connection.id)"
            :data="purgeData"
            confirm-label="Eliminar conexión definitivamente"
        >
            <fieldset
                v-if="hasConversations"
                class="grid gap-2 rounded-lg border px-3.5 py-3"
                data-test="purge-transfer"
            >
                <legend class="px-1 font-medium text-foreground">
                    ¿Transferir mensajes a otra conexión?
                </legend>
                <label class="flex cursor-pointer items-center gap-2">
                    <input
                        v-model="purgeTransfer"
                        type="radio"
                        value="yes"
                        :disabled="(moveTargets ?? []).length === 0"
                    />
                    Sí, pasar todas sus conversaciones a:
                </label>
                <AppSelect
                    v-if="purgeTransfer === 'yes'"
                    v-model="purgeTarget"
                    :options="purgeTargetOptions"
                    placeholder="Elige la conexión que se queda con todo"
                    aria-label="Conexión que recibe las conversaciones"
                />
                <label
                    v-if="purgeTransfer === 'yes'"
                    class="flex cursor-pointer items-center gap-2 text-xs text-muted-foreground"
                >
                    <Checkbox
                        :model-value="purgeShowArchived"
                        @update:model-value="
                            (checked) => (purgeShowArchived = checked === true)
                        "
                    />
                    Mostrar también en la bandeja el historial archivado de
                    Whaticket
                </label>
                <p
                    v-if="(moveTargets ?? []).length === 0"
                    class="text-xs text-muted-foreground"
                >
                    No hay otra conexión del mismo canal: crea o restaura una
                    para poder transferir.
                </p>
                <label class="flex cursor-pointer items-center gap-2">
                    <input v-model="purgeTransfer" type="radio" value="no" />
                    No, borrarlas junto con la conexión
                </label>
            </fieldset>
        </PermanentDeleteDialog>

        <RegisterNumberDialog
            v-if="connection && isWhatsapp && can.verify"
            v-model:open="registerOpen"
            :connection-id="connection.id"
            :display-number="connection.display_phone_number"
            :phone-status="connection.meta.phone_status"
        />
    </form>
</template>
