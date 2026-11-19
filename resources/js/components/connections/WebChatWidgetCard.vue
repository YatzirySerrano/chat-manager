<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import {
    Check,
    Copy,
    Info,
    KeyRound,
    Plus,
    RefreshCw,
    ShieldCheck,
    Trash2,
    TriangleAlert,
    Upload,
} from '@lucide/vue';
import { useClipboard } from '@vueuse/core';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import WebChatColorField from '@/components/connections/WebChatColorField.vue';
import type { WebChatPreviewAppearance } from '@/components/connections/WebChatPreview.vue';
import FormField from '@/components/FormField.vue';
import FormSection from '@/components/FormSection.vue';
import InputError from '@/components/InputError.vue';
import HexColorField from '@/components/personalization/HexColorField.vue';
import { AppSelect } from '@/components/ui/app-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { FileDropzone } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { RadioCards } from '@/components/ui/radio-cards';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { useFormValidation } from '@/composables/useFormValidation';
import { toastFirstError } from '@/lib/toastErrors';
import { integerBetween, maxLength, pattern, required } from '@/lib/validation';
import type { FieldRule, FieldRules } from '@/lib/validation';
import {
    rotateIdentitySecret,
    rotateKey,
    update as updateWidget,
} from '@/routes/connections/widget';
import {
    LAUNCHER_WIDTH,
    MAX_LAUNCHER_OFFSET,
    PANEL_HEIGHT,
    PANEL_WIDTH,
    readableOn,
} from '@/widget/launcher';
import type {
    ConnectionOption,
    WebChatAvailabilityMode,
    WebChatIdentityMode,
    WebChatPosition,
    WebChatPrechatField,
    WebChatWidgetSettings,
} from '@/types';

export type WebChatWidgetOptions = {
    positions: ConnectionOption[];
    identityModes: ConnectionOption[];
    availabilityModes: ConnectionOption[];
    limits: {
        origins: number;
        avatar_kb: number;
        min_contrast: number;
        session_idle_days: number;
    };
    /** What visitors may upload (absent on older servers). */
    visitorFiles?: { max_mb: string; video_max_mb: string };
};

/**
 * Widget settings of a web chat connection: authorized origins,
 * appearance, visitor identification, pre-chat form and availability, plus
 * the rotation of the public key and of the identity secret. It saves on
 * its own endpoint, apart from the connection form that contains it, and
 * reports the unsaved appearance so the preview can follow it live:
 * texts, the colors of the conversation, the floating button (colors,
 * shape, icon, size, width, text, corner and offsets) and the size of the
 * chat window. Empty colors keep the widget's own; a very low contrast is
 * only warned about (the choice is the administrator's).
 */
const props = defineProps<{
    connectionId: number;
    widget: WebChatWidgetSettings;
    options: WebChatWidgetOptions;
    canUpdate: boolean;
}>();

const emit = defineEmits<{
    appearance: [appearance: WebChatPreviewAppearance, dirty: boolean];
    /** Whether the widget has unsaved changes (the page's save bar saves them too). */
    dirty: [dirty: boolean];
}>();

type WidgetForm = {
    allowed_origins: string[];
    title: string;
    subtitle: string;
    welcome_text: string;
    offline_text: string;
    position: WebChatPosition;
    accent_color: string;
    dark_mode: WebChatWidgetSettings['dark_mode'];
    launcher_enabled: boolean;
    launcher_label: string;
    background_color: string;
    visitor_bubble_color: string;
    visitor_text_color: string;
    agent_bubble_color: string;
    agent_text_color: string;
    launcher_color: string;
    launcher_icon_color: string;
    launcher_shape: WebChatWidgetSettings['launcher_shape'];
    launcher_icon: WebChatWidgetSettings['launcher_icon'];
    launcher_size: WebChatWidgetSettings['launcher_size'];
    launcher_font_size: WebChatWidgetSettings['launcher_font_size'];
    launcher_width: number | null;
    launcher_offset_x: number | null;
    launcher_offset_y: number | null;
    launcher_pulse: boolean;
    panel_width: number | null;
    panel_height: number | null;
    online_text: string;
    offline_status_text: string;
    composer_placeholder: string;
    allow_visitor_files: boolean;
    avatar: File | null;
    remove_avatar: boolean;
    launcher_image: File | null;
    remove_launcher_image: boolean;
    identity_mode: WebChatIdentityMode;
    prechat: {
        fields: WebChatPrechatField[];
        required: boolean;
        privacy_notice: string;
    };
    availability_mode: WebChatAvailabilityMode;
    session_idle_days: number | null;
};

function initialValues(widget: WebChatWidgetSettings): WidgetForm {
    return {
        allowed_origins:
            widget.allowed_origins.length > 0
                ? [...widget.allowed_origins]
                : [''],
        title: widget.title,
        subtitle: widget.subtitle ?? '',
        welcome_text: widget.welcome_text ?? '',
        offline_text: widget.offline_text ?? '',
        position: widget.position,
        accent_color: widget.accent_color,
        dark_mode: widget.dark_mode ?? 'allow',
        launcher_enabled: widget.launcher_enabled,
        launcher_label: widget.launcher_label ?? '',
        background_color: widget.background_color ?? '',
        visitor_bubble_color: widget.visitor_bubble_color ?? '',
        visitor_text_color: widget.visitor_text_color ?? '',
        agent_bubble_color: widget.agent_bubble_color ?? '',
        agent_text_color: widget.agent_text_color ?? '',
        launcher_color: widget.launcher_color ?? '',
        launcher_icon_color: widget.launcher_icon_color ?? '',
        launcher_shape: widget.launcher_shape ?? 'circle',
        launcher_icon: widget.launcher_icon ?? 'chat',
        launcher_size: widget.launcher_size ?? 'medium',
        launcher_font_size: widget.launcher_font_size ?? 'medium',
        launcher_width: widget.launcher_width ?? null,
        launcher_offset_x: widget.launcher_offset_x ?? 0,
        launcher_offset_y: widget.launcher_offset_y ?? 0,
        launcher_pulse: widget.launcher_pulse ?? false,
        panel_width: widget.panel_width ?? PANEL_WIDTH.default,
        panel_height: widget.panel_height ?? PANEL_HEIGHT.default,
        online_text: widget.online_text ?? '',
        offline_status_text: widget.offline_status_text ?? '',
        composer_placeholder: widget.composer_placeholder ?? '',
        allow_visitor_files: widget.allow_visitor_files ?? true,
        avatar: null,
        remove_avatar: false,
        launcher_image: null,
        remove_launcher_image: false,
        identity_mode: widget.identity_mode,
        prechat: {
            fields: [...widget.prechat.fields],
            required: widget.prechat.required,
            privacy_notice: widget.prechat.privacy_notice ?? '',
        },
        availability_mode: widget.availability_mode,
        session_idle_days: widget.session_idle_days,
    };
}

const form = useForm<WidgetForm>(initialValues(props.widget));

const LOCAL_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

/**
 * Mirrors WebChatWidget::normalizeOrigin and WebChatWidgetRequest: an
 * http(s) origin without path, query, credentials or wildcard; plain http
 * only for local development hosts. The server validates again.
 */
function originProblem(value: string): string | null {
    const text = value.trim();

    if (text === '') {
        return null;
    }

    if (text.includes('*')) {
        return 'No se admiten comodines: escribe cada dominio completo, por ejemplo https://tienda.example.com.';
    }

    let url: URL;

    try {
        url = new URL(text);
    } catch {
        return 'Escribe solo el origen (protocolo y dominio), por ejemplo https://tienda.example.com.';
    }

    const hasExtras =
        url.username !== '' ||
        url.password !== '' ||
        url.search !== '' ||
        url.hash !== '' ||
        text.includes('?') ||
        text.includes('#') ||
        (url.pathname !== '/' && url.pathname !== '');

    if (!['http:', 'https:'].includes(url.protocol) || hasExtras) {
        return 'Escribe solo el origen (protocolo y dominio, y el puerto si no es el habitual), sin ruta ni parámetros: por ejemplo https://tienda.example.com.';
    }

    if (url.protocol === 'http:' && !LOCAL_HOSTS.includes(url.hostname)) {
        return 'El dominio debe usar https. Solo localhost y 127.0.0.1 se admiten con http, para desarrollo.';
    }

    return null;
}

function normalizeOrigin(value: string): string {
    try {
        return new URL(value.trim()).origin;
    } catch {
        return value.trim().toLowerCase();
    }
}

function luminanceOf(hex: string): number {
    const channels = [1, 3, 5].map((start) => {
        const value = parseInt(hex.slice(start, start + 2), 16) / 255;

        return value <= 0.03928
            ? value / 12.92
            : ((value + 0.055) / 1.055) ** 2.4;
    });

    return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
}

/** WCAG contrast ratio between two #rrggbb colors. */
function contrastBetween(first: string, second: string): number {
    const [light, dark] = [luminanceOf(first), luminanceOf(second)].sort(
        (a, b) => b - a,
    );

    return (light + 0.05) / (dark + 0.05);
}

/** WCAG contrast ratio between a #rrggbb color and white. */
function contrastWithWhite(hex: string): number {
    const channels = [1, 3, 5].map((start) => {
        const value = parseInt(hex.slice(start, start + 2), 16) / 255;

        return value <= 0.03928
            ? value / 12.92
            : ((value + 0.055) / 1.055) ** 2.4;
    });
    const luminance =
        0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];

    return 1.05 / (luminance + 0.05);
}

const HEX_COLOR = /^#[0-9a-fA-F]{6}$/;

const contrast = computed(() =>
    HEX_COLOR.test(form.accent_color)
        ? contrastWithWhite(form.accent_color)
        : null,
);

const COLOR_FIELDS = [
    'background_color',
    'visitor_bubble_color',
    'visitor_text_color',
    'agent_bubble_color',
    'agent_text_color',
    'launcher_color',
    'launcher_icon_color',
] as const;

type ColorField = (typeof COLOR_FIELDS)[number];

/** The color a field stands for: the one chosen, or what the widget uses. */
function colorOf(field: ColorField): string | null {
    const value = form[field].trim();

    return HEX_COLOR.test(value) ? value.toLowerCase() : null;
}

const accent = computed(() =>
    HEX_COLOR.test(form.accent_color)
        ? form.accent_color.toLowerCase()
        : props.widget.accent_color,
);

/** What each color shows while it is left empty (light theme). */
const fallbacks = computed<Record<ColorField, string>>(() => {
    const visitorBubble = colorOf('visitor_bubble_color') ?? accent.value;
    const agentBubble = colorOf('agent_bubble_color') ?? '#f3f4f6';
    const launcher = colorOf('launcher_color') ?? accent.value;

    return {
        background_color: '#ffffff',
        visitor_bubble_color: accent.value,
        visitor_text_color: readableOn(visitorBubble),
        agent_bubble_color: '#f3f4f6',
        agent_text_color:
            colorOf('agent_bubble_color') !== null
                ? readableOn(agentBubble)
                : '#111827',
        launcher_color: accent.value,
        launcher_icon_color: readableOn(launcher),
    };
});

/** Pairs whose contrast is very low (under 3:1): only a warning. */
const lowContrast = computed(() => {
    const pairs: [string, ColorField, ColorField][] = [
        [
            'mensajes del visitante',
            'visitor_bubble_color',
            'visitor_text_color',
        ],
        ['mensajes del agente', 'agent_bubble_color', 'agent_text_color'],
        ['botón flotante', 'launcher_color', 'launcher_icon_color'],
    ];

    return pairs
        .map(([label, bubble, text]) => ({
            label,
            ratio: contrastBetween(
                colorOf(bubble) ?? fallbacks.value[bubble],
                colorOf(text) ?? fallbacks.value[text],
            ),
        }))
        .filter((pair) => pair.ratio < 3);
});

const COLOR_RULE = pattern(HEX_COLOR, 'Usa formato #RRGGBB o déjalo vacío.');

const SHAPE_OPTIONS: { value: WidgetForm['launcher_shape']; label: string }[] =
    [
        { value: 'circle', label: 'Círculo' },
        { value: 'rounded', label: 'Cuadrado redondeado' },
        { value: 'pill', label: 'Píldora' },
    ];

const ICON_OPTIONS: { value: WidgetForm['launcher_icon']; label: string }[] = [
    { value: 'chat', label: 'Globo de chat' },
    { value: 'message', label: 'Mensaje con teléfono' },
    { value: 'headset', label: 'Audífonos' },
    { value: 'help', label: 'Ayuda' },
];

const SIZE_OPTIONS: { value: WidgetForm['launcher_size']; label: string }[] = [
    { value: 'small', label: 'Chico' },
    { value: 'medium', label: 'Mediano' },
    { value: 'large', label: 'Grande' },
];

const DARK_MODE_OPTIONS: {
    value: WidgetForm['dark_mode'];
    label: string;
}[] = [
    { value: 'allow', label: 'Permitir (el visitante elige)' },
    { value: 'off', label: 'Solo claro' },
];

const WIDTH_OPTIONS = [
    { value: 'auto', label: 'Automático' },
    { value: 'fixed', label: 'Fijo' },
];

/** Automatic width (fits the text) or a fixed one. */
const widthMode = computed<string>({
    get: () => (form.launcher_width === null ? 'auto' : 'fixed'),
    set: (mode: string) => {
        form.launcher_width = mode === 'auto' ? null : 160;
    },
});

/** With a text the button shows icon and text, whatever its shape. */
const hasLabel = computed(() => form.launcher_label.trim() !== '');

const launcherImagePreview = ref<string | null>(
    props.widget.launcher_image_url ?? null,
);
const launcherImageError = ref<string | null>(null);
const launcherImageInput = ref<HTMLInputElement | null>(null);

/** Width / height of an image file, read by the browser. */
function imageRatio(url: string): Promise<number> {
    return new Promise((resolve) => {
        const image = new Image();
        image.onload = () =>
            resolve(
                image.naturalHeight > 0
                    ? image.naturalWidth / image.naturalHeight
                    : 0,
            );
        image.onerror = () => resolve(0);
        image.src = url;
    });
}

/** The button's own image: PNG, JPG or WebP, small and almost square (no SVG). */
async function pickLauncherImage(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    input.value = '';
    launcherImageError.value = null;

    if (!file) {
        return;
    }

    if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type)) {
        launcherImageError.value = 'El ícono debe ser PNG, JPG o WebP.';

        return;
    }

    if (file.size > props.options.limits.avatar_kb * 1024) {
        launcherImageError.value = `El ícono no debe pesar más de ${props.options.limits.avatar_kb} KB.`;

        return;
    }

    const url = await readAsDataUrl(file);
    const ratio = await imageRatio(url);

    if (ratio < 0.5 || ratio > 2) {
        launcherImageError.value = 'Usa una imagen casi cuadrada.';

        return;
    }

    form.launcher_image = file;
    form.remove_launcher_image = false;
    launcherImagePreview.value = url;
}

function removeLauncherImage(): void {
    form.launcher_image = null;
    form.remove_launcher_image = props.widget.launcher_image_url !== null;
    launcherImagePreview.value = null;
    launcherImageError.value = null;
}

function numberOrNull(value: string | number): number | null {
    return value === '' ? null : Number(value);
}

const originRule: FieldRule = (value) =>
    typeof value === 'string' ? originProblem(value) : null;

const validation = useFormValidation(form, {
    rules: (): FieldRules => {
        const rules: FieldRules = {
            title: [
                required('Escribe el título que verán los visitantes.'),
                maxLength(60),
            ],
            subtitle: [maxLength(120)],
            welcome_text: [maxLength(500)],
            offline_text: [maxLength(500)],
            launcher_label: [maxLength(40)],
            accent_color: [
                pattern(
                    HEX_COLOR,
                    'Escribe el color en formato hexadecimal, por ejemplo #2563EB.',
                ),
                () =>
                    contrast.value !== null &&
                    contrast.value < props.options.limits.min_contrast
                        ? `El color es demasiado claro para leer texto blanco encima (contraste ${contrast.value.toFixed(1)}:1; se necesita al menos ${props.options.limits.min_contrast.toFixed(1)}:1). Elige un tono más oscuro.`
                        : null,
            ],
            'prechat.privacy_notice': [maxLength(500)],
            online_text: [maxLength(40)],
            offline_status_text: [maxLength(40)],
            composer_placeholder: [maxLength(80)],
            launcher_width: [
                integerBetween(LAUNCHER_WIDTH.min, LAUNCHER_WIDTH.max),
            ],
            launcher_offset_x: [integerBetween(0, MAX_LAUNCHER_OFFSET)],
            launcher_offset_y: [integerBetween(0, MAX_LAUNCHER_OFFSET)],
            panel_width: [
                required('Indica el ancho de la ventana.'),
                integerBetween(PANEL_WIDTH.min, PANEL_WIDTH.max),
            ],
            panel_height: [
                required('Indica el alto de la ventana.'),
                integerBetween(PANEL_HEIGHT.min, PANEL_HEIGHT.max),
            ],
            ...Object.fromEntries(
                COLOR_FIELDS.map((field) => [field, [COLOR_RULE]]),
            ),
            session_idle_days: [
                required('Indica cuántos días dura una sesión sin actividad.'),
                integerBetween(1, props.options.limits.session_idle_days),
            ],
        };

        form.allowed_origins.forEach((_, index) => {
            rules[`allowed_origins.${index}`] = [originRule];
        });

        return rules;
    },
    ignore: [
        'avatar',
        'remove_avatar',
        'launcher_image',
        'remove_launcher_image',
    ],
    requireChanges: true,
});

const hasChanges = computed(
    () =>
        validation.hasChanges ||
        form.avatar !== null ||
        form.remove_avatar ||
        form.launcher_image !== null ||
        form.remove_launcher_image,
);

const filledOrigins = computed(() =>
    form.allowed_origins.map((origin) => origin.trim()).filter(Boolean),
);

const duplicateOrigins = computed(() => {
    const normalized = filledOrigins.value.map(normalizeOrigin);

    return normalized.length !== new Set(normalized).size;
});

function addOrigin(): void {
    if (form.allowed_origins.length >= props.options.limits.origins) {
        return;
    }

    form.allowed_origins = [...form.allowed_origins, ''];
}

function removeOrigin(index: number): void {
    const next = form.allowed_origins.filter((_, i) => i !== index);
    form.allowed_origins = next.length > 0 ? next : [''];
}

function setOrigin(index: number, value: string): void {
    form.allowed_origins = form.allowed_origins.map((origin, i) =>
        i === index ? value : origin,
    );
}

function togglePrechatField(
    field: WebChatPrechatField,
    checked: boolean,
): void {
    const fields = checked
        ? [...form.prechat.fields, field]
        : form.prechat.fields.filter((current) => current !== field);

    form.prechat.fields = (['name', 'email'] as const).filter((candidate) =>
        fields.includes(candidate),
    );
}

const avatarPreview = ref<string | null>(props.widget.avatar_url);
const avatarError = ref<string | null>(null);

function readAsDataUrl(file: File): Promise<string> {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(String(reader.result));
        reader.onerror = () => reject(reader.error);
        reader.readAsDataURL(file);
    });
}

async function setAvatar(file: File | null): Promise<void> {
    avatarError.value = null;

    if (!file) {
        // The chosen file was taken back: the saved image shows again.
        if (form.avatar !== null) {
            form.avatar = null;
            avatarPreview.value = form.remove_avatar
                ? null
                : props.widget.avatar_url;
        }

        return;
    }

    if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type)) {
        avatarError.value = 'La imagen debe ser PNG, JPG o WebP.';

        return;
    }

    if (file.size > props.options.limits.avatar_kb * 1024) {
        avatarError.value = `La imagen no debe pesar más de ${props.options.limits.avatar_kb} KB.`;

        return;
    }

    form.avatar = file;
    form.remove_avatar = false;
    avatarPreview.value = await readAsDataUrl(file);
}

/** Model of the dropzone: the image chosen and not yet saved. */
const avatarFile = computed<File | File[] | null | undefined>({
    get: () => form.avatar,
    set: (value) => {
        void setAvatar(
            Array.isArray(value) ? (value[0] ?? null) : (value ?? null),
        );
    },
});

const ACCENT_SWATCHES = [
    '#2563eb',
    '#0f766e',
    '#15803d',
    '#b45309',
    '#be123c',
    '#7c3aed',
    '#334155',
    '#111827',
];

function removeAvatar(): void {
    form.avatar = null;
    form.remove_avatar = props.widget.avatar_url !== null;
    avatarPreview.value = null;
}

function bounded(
    value: number | null,
    bounds: { min: number; max: number },
    fallback: number,
): number {
    return value === null || !Number.isFinite(value)
        ? fallback
        : Math.min(bounds.max, Math.max(bounds.min, Math.round(value)));
}

const appearance = computed<WebChatPreviewAppearance>(() => ({
    title: form.title,
    subtitle: form.subtitle || null,
    welcome_text: form.welcome_text || null,
    offline_text: form.offline_text || null,
    online_text: form.online_text.trim() || null,
    offline_status_text: form.offline_status_text.trim() || null,
    composer_placeholder: form.composer_placeholder.trim() || null,
    accent_color: accent.value,
    dark_mode: form.dark_mode,
    position: form.position,
    launcher_enabled: form.launcher_enabled,
    launcher_label: form.launcher_label || null,
    avatar: avatarPreview.value,
    background_color: colorOf('background_color'),
    visitor_bubble_color: colorOf('visitor_bubble_color'),
    visitor_text_color: colorOf('visitor_text_color'),
    agent_bubble_color: colorOf('agent_bubble_color'),
    agent_text_color: colorOf('agent_text_color'),
    launcher_color: colorOf('launcher_color'),
    launcher_icon_color: colorOf('launcher_icon_color'),
    launcher_shape: form.launcher_shape,
    launcher_icon: form.launcher_icon,
    launcher_image: launcherImagePreview.value,
    launcher_size: form.launcher_size,
    launcher_font_size: form.launcher_font_size,
    launcher_width:
        form.launcher_width === null
            ? null
            : bounded(form.launcher_width, LAUNCHER_WIDTH, 160),
    launcher_offset: {
        x: bounded(
            form.launcher_offset_x,
            { min: 0, max: MAX_LAUNCHER_OFFSET },
            0,
        ),
        y: bounded(
            form.launcher_offset_y,
            { min: 0, max: MAX_LAUNCHER_OFFSET },
            0,
        ),
    },
    launcher_pulse: form.launcher_pulse,
    panel: {
        width: bounded(form.panel_width, PANEL_WIDTH, PANEL_WIDTH.default),
        height: bounded(form.panel_height, PANEL_HEIGHT, PANEL_HEIGHT.default),
    },
}));

watch(
    [appearance, hasChanges],
    () => emit('appearance', appearance.value, hasChanges.value),
    { immediate: true, deep: true },
);

/**
 * When the server sends new settings (after saving or rotating), they become
 * the unchanged baseline.
 */
watch(
    () => props.widget,
    (widget) => {
        const values = initialValues(widget);
        form.defaults(values);
        form.reset();
        avatarPreview.value = widget.avatar_url;
        launcherImagePreview.value = widget.launcher_image_url ?? null;
        validation.load(values);
    },
);

watch(
    () => hasChanges.value,
    (dirty) => emit('dirty', dirty),
    { immediate: true },
);

defineExpose({
    save: () => save(),
    discard: () => discard(),
    processing: () => form.processing,
});

function save(): void {
    if (!validation.validate() || duplicateOrigins.value) {
        toast.error('Revisa los campos marcados del widget.');

        return;
    }

    form.transform((data) => ({
        ...data,
        allowed_origins: data.allowed_origins
            .map((origin) => origin.trim())
            .filter(Boolean),
        _method: 'put',
    })).post(updateWidget.url(props.connectionId), {
        preserveScroll: true,
        onError: () => toast.error('Revisa los campos marcados del widget.'),
    });
}

/** Enter inside a widget field saves the widget, not the connection form around it. */
function onEnter(event: KeyboardEvent): void {
    const target = event.target as HTMLElement | null;

    if (target instanceof HTMLInputElement) {
        event.preventDefault();

        if (hasChanges.value && props.canUpdate) {
            save();
        }
    }
}

function discard(): void {
    form.reset();
    form.clearErrors();
    avatarPreview.value = props.widget.avatar_url;
    avatarError.value = null;
    launcherImagePreview.value = props.widget.launcher_image_url ?? null;
    launcherImageError.value = null;
    validation.load(initialValues(props.widget));
}

const confirmKeyOpen = ref(false);
const confirmSecretOpen = ref(false);
const rotating = ref<'key' | 'secret' | null>(null);
const revealedSecret = ref<string | null>(null);

const stopFlashListener = router.on('flash', (event) => {
    const secret = event.detail.flash?.identity_secret;

    if (typeof secret === 'string' && secret !== '') {
        revealedSecret.value = secret;
    }
});

onBeforeUnmount(() => {
    stopFlashListener();
    revealedSecret.value = null;
});

function rotate(kind: 'key' | 'secret'): void {
    const route =
        kind === 'key'
            ? rotateKey(props.connectionId)
            : rotateIdentitySecret(props.connectionId);

    router.visit(route, {
        preserveScroll: true,
        onStart: () => (rotating.value = kind),
        onFinish: () => {
            rotating.value = null;
            confirmKeyOpen.value = false;
            confirmSecretOpen.value = false;
        },
        onError: toastFirstError,
    });
}

const { copy, copied, isSupported: canCopy } = useClipboard({ legacy: true });

async function copySecret(): Promise<void> {
    if (revealedSecret.value) {
        await copy(revealedSecret.value);
        toast.success('Secreto copiado.');
    }
}

/** Built apart so the SFC parser never sees a script tag inside this block. */
const SCRIPT_TAG = ['scr', 'ipt'].join('');

const signatureExample = computed(
    () => `<?php
// En tu servidor, nunca en el navegador.
$secret = getenv('CHAT_MANAGER_IDENTITY_SECRET');
$publicKey = '${props.widget.public_key}';
$externalId = (string) $usuario->id;   // Identificador estable en tu sitio
$email = $usuario->email ?? '';
$name = $usuario->nombre ?? '';
$iat = time();
$nonce = bin2hex(random_bytes(16));    // Único en cada carga

$payload = implode("\\n", ['v1', $publicKey, $externalId, $email, $name, $iat, $nonce]);
$signature = hash_hmac('sha256', $payload, $secret);
?>
<${SCRIPT_TAG}>
  ChatManager('identify', {
    external_id: <?= json_encode($externalId) ?>,
    email: <?= json_encode($email) ?>,
    name: <?= json_encode($name) ?>,
    iat: <?= $iat ?>,
    nonce: <?= json_encode($nonce) ?>,
    signature: <?= json_encode($signature) ?>
  });
</${SCRIPT_TAG}>`,
);

const fieldError = (path: string): string | undefined =>
    validation.error(path) ?? form.errors[path as keyof WidgetForm];
</script>

<template>
    <div
        class="grid gap-10"
        data-slot="web-chat-widget"
        @keydown.enter="onEnter"
    >
        <FormSection
            id="widget-dominios"
            title="Dominios autorizados"
            description="Solo estos sitios pueden mostrar el chat. Escribe el origen exacto (https://tienda.example.com); los subdominios no se incluyen solos. Usa http únicamente con localhost o 127.0.0.1 para desarrollo."
        >
            <section class="grid gap-3" aria-labelledby="widget-origins-title">
                <h3 id="widget-origins-title" class="sr-only">
                    Dominios autorizados
                </h3>
                <ul class="grid gap-2">
                    <li
                        v-for="(origin, index) in form.allowed_origins"
                        :key="index"
                        class="grid gap-1"
                    >
                        <div class="flex gap-2">
                            <Input
                                :id="`widget-origin-${index}`"
                                :model-value="origin"
                                v-bind="
                                    validation.field(
                                        `allowed_origins.${index}`,
                                        `widget-origin-${index}`,
                                    )
                                "
                                type="url"
                                inputmode="url"
                                maxlength="255"
                                class="font-mono"
                                placeholder="https://tienda.example.com"
                                :aria-label="`Dominio autorizado ${index + 1}`"
                                @update:model-value="
                                    (value: string | number) =>
                                        setOrigin(index, String(value))
                                "
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                :aria-label="`Quitar el dominio ${index + 1}`"
                                :disabled="
                                    !canUpdate ||
                                    (form.allowed_origins.length === 1 &&
                                        origin === '')
                                "
                                @click="removeOrigin(index)"
                            >
                                <Trash2 />
                            </Button>
                        </div>
                        <InputError
                            :id="`widget-origin-${index}-error`"
                            :message="fieldError(`allowed_origins.${index}`)"
                        />
                    </li>
                </ul>
                <InputError
                    :message="
                        duplicateOrigins
                            ? 'Hay dominios repetidos. Escribe cada origen una sola vez.'
                            : form.errors.allowed_origins
                    "
                />
                <div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="
                            !canUpdate ||
                            form.allowed_origins.length >=
                                options.limits.origins
                        "
                        @click="addOrigin"
                    >
                        <Plus />
                        Agregar dominio
                    </Button>
                </div>
            </section>
        </FormSection>

        <FormSection
            id="widget-apariencia"
            title="Apariencia del widget"
            description="Cómo se ve el chat en tu sitio. La vista previa sigue lo que escribes, aun antes de guardar."
        >
            <template v-if="$slots.preview" #aside>
                <div class="pt-3">
                    <slot name="preview" />
                </div>
            </template>
            <section
                class="field-grid [--col-min:16rem]"
                aria-labelledby="widget-appearance-title"
            >
                <h3 id="widget-appearance-title" class="sr-only">Apariencia</h3>
                <FormField
                    id="widget-title"
                    label="Título"
                    :error="fieldError('title')"
                >
                    <Input
                        id="widget-title"
                        v-model="form.title"
                        v-bind="validation.field('title', 'widget-title')"
                        maxlength="60"
                        placeholder="Chatea con nosotros"
                    />
                </FormField>
                <FormField
                    id="widget-subtitle"
                    label="Subtítulo (opcional)"
                    :error="fieldError('subtitle')"
                >
                    <Input
                        id="widget-subtitle"
                        v-model="form.subtitle"
                        v-bind="validation.field('subtitle', 'widget-subtitle')"
                        maxlength="120"
                        placeholder="Te respondemos en minutos"
                    />
                </FormField>
                <FormField
                    id="widget-welcome"
                    label="Mensaje de bienvenida (opcional)"
                    :error="fieldError('welcome_text')"
                    class="col-span-full"
                >
                    <Textarea
                        id="widget-welcome"
                        v-model="form.welcome_text"
                        v-bind="
                            validation.field('welcome_text', 'widget-welcome')
                        "
                        rows="2"
                        maxlength="500"
                        placeholder="¡Hola! ¿En qué podemos ayudarte?"
                    />
                </FormField>
                <div
                    class="col-span-full grid [grid-template-columns:repeat(auto-fit,minmax(11rem,1fr))] gap-3"
                >
                    <FormField
                        id="widget-online-text"
                        label="Estado en línea"
                        :error="fieldError('online_text')"
                    >
                        <Input
                            id="widget-online-text"
                            v-model="form.online_text"
                            v-bind="
                                validation.field(
                                    'online_text',
                                    'widget-online-text',
                                )
                            "
                            maxlength="40"
                            placeholder="En línea"
                        />
                    </FormField>
                    <FormField
                        id="widget-offline-status"
                        label="Estado fuera de línea"
                        :error="fieldError('offline_status_text')"
                    >
                        <Input
                            id="widget-offline-status"
                            v-model="form.offline_status_text"
                            v-bind="
                                validation.field(
                                    'offline_status_text',
                                    'widget-offline-status',
                                )
                            "
                            maxlength="40"
                            placeholder="Fuera de línea"
                        />
                    </FormField>
                    <FormField
                        id="widget-placeholder"
                        label="Caja de mensaje"
                        :error="fieldError('composer_placeholder')"
                    >
                        <Input
                            id="widget-placeholder"
                            v-model="form.composer_placeholder"
                            v-bind="
                                validation.field(
                                    'composer_placeholder',
                                    'widget-placeholder',
                                )
                            "
                            maxlength="80"
                            placeholder="Escribe tu mensaje…"
                        />
                    </FormField>
                </div>
                <FormField
                    id="widget-accent"
                    label="Color de acento"
                    :error="fieldError('accent_color')"
                    :hint="
                        contrast !== null
                            ? `Contraste con texto blanco: ${contrast.toFixed(1)}:1`
                            : undefined
                    "
                >
                    <div class="flex gap-2">
                        <HexColorField
                            v-model="form.accent_color"
                            aria-label="Elegir el color de acento"
                            :fallback="widget.accent_color"
                            :swatches="ACCENT_SWATCHES"
                            :disabled="!canUpdate"
                        />
                        <Input
                            id="widget-accent"
                            v-model="form.accent_color"
                            v-bind="
                                validation.field(
                                    'accent_color',
                                    'widget-accent',
                                )
                            "
                            maxlength="7"
                            class="font-mono"
                            placeholder="#2563eb"
                        />
                    </div>
                </FormField>
                <FormField
                    id="widget-dark-mode"
                    label="Modo oscuro"
                    :error="fieldError('dark_mode')"
                >
                    <AppSelect
                        id="widget-dark-mode"
                        v-model="form.dark_mode"
                        :options="DARK_MODE_OPTIONS"
                        :disabled="!canUpdate"
                    />
                </FormField>
                <FormField
                    id="widget-avatar"
                    label="Imagen (opcional)"
                    :error="avatarError ?? form.errors.avatar"
                    class="col-span-full"
                >
                    <div class="flex flex-wrap items-start gap-3">
                        <img
                            v-if="avatarPreview"
                            :src="avatarPreview"
                            alt="Imagen actual del chat"
                            class="size-14 shrink-0 rounded-full border object-cover"
                        />
                        <div class="grid min-w-0 flex-1 basis-64 gap-2">
                            <FileDropzone
                                id="widget-avatar"
                                v-model="avatarFile"
                                compact
                                accept="image/png,image/jpeg,image/webp"
                                :max-size-mb="options.limits.avatar_kb / 1024"
                                title="Arrastra una imagen o elige un archivo"
                                :hint="`PNG, JPG o WebP de hasta ${options.limits.avatar_kb} KB.`"
                                :invalid="
                                    Boolean(avatarError ?? form.errors.avatar)
                                "
                                :disabled="!canUpdate"
                            />
                            <div v-if="avatarPreview">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    :disabled="!canUpdate"
                                    @click="removeAvatar"
                                >
                                    <Trash2 />
                                    Quitar imagen
                                </Button>
                            </div>
                        </div>
                    </div>
                </FormField>

                <h4 class="widget-group-title">Colores de la conversación</h4>
                <div class="widget-color-grid col-span-full">
                    <WebChatColorField
                        id="widget-background-color"
                        v-model="form.background_color"
                        label="Fondo"
                        :fallback="fallbacks.background_color"
                        :error="fieldError('background_color')"
                        :disabled="!canUpdate"
                    />
                    <WebChatColorField
                        id="widget-visitor-bubble"
                        v-model="form.visitor_bubble_color"
                        label="Burbuja visitante"
                        :fallback="fallbacks.visitor_bubble_color"
                        :swatches="ACCENT_SWATCHES"
                        :error="fieldError('visitor_bubble_color')"
                        :disabled="!canUpdate"
                    />
                    <WebChatColorField
                        id="widget-visitor-text"
                        v-model="form.visitor_text_color"
                        label="Texto visitante"
                        :fallback="fallbacks.visitor_text_color"
                        :error="fieldError('visitor_text_color')"
                        :disabled="!canUpdate"
                    />
                    <WebChatColorField
                        id="widget-agent-bubble"
                        v-model="form.agent_bubble_color"
                        label="Burbuja agente"
                        :fallback="fallbacks.agent_bubble_color"
                        :error="fieldError('agent_bubble_color')"
                        :disabled="!canUpdate"
                    />
                    <WebChatColorField
                        id="widget-agent-text"
                        v-model="form.agent_text_color"
                        label="Texto agente"
                        :fallback="fallbacks.agent_text_color"
                        :error="fieldError('agent_text_color')"
                        :disabled="!canUpdate"
                    />
                </div>

                <h4 class="widget-group-title">Botón flotante</h4>
                <div
                    class="col-span-full flex min-h-9 flex-wrap items-center gap-x-6 gap-y-2"
                >
                    <label
                        for="widget-launcher"
                        class="inline-flex items-center gap-2 text-sm"
                    >
                        <Switch
                            id="widget-launcher"
                            v-model="form.launcher_enabled"
                            :disabled="!canUpdate"
                        />
                        Mostrar botón
                    </label>
                    <label
                        for="widget-launcher-pulse"
                        class="inline-flex items-center gap-2 text-sm"
                        title="El botón late hasta que el visitante abre el chat o mientras tiene mensajes sin leer."
                    >
                        <Switch
                            id="widget-launcher-pulse"
                            v-model="form.launcher_pulse"
                            :disabled="!canUpdate || !form.launcher_enabled"
                        />
                        Animación de atención
                    </label>
                </div>
                <div class="widget-color-grid col-span-full">
                    <FormField
                        id="widget-launcher-label"
                        label="Texto del botón"
                        :error="fieldError('launcher_label')"
                        class="col-span-2 max-sm:col-span-full"
                    >
                        <Input
                            id="widget-launcher-label"
                            v-model="form.launcher_label"
                            v-bind="
                                validation.field(
                                    'launcher_label',
                                    'widget-launcher-label',
                                )
                            "
                            maxlength="40"
                            placeholder="Vacío: solo el ícono"
                            :disabled="!form.launcher_enabled"
                        />
                    </FormField>
                    <FormField
                        v-if="hasLabel"
                        id="widget-launcher-font"
                        label="Tamaño del texto"
                        :error="fieldError('launcher_font_size')"
                    >
                        <AppSelect
                            id="widget-launcher-font"
                            v-model="form.launcher_font_size"
                            :options="SIZE_OPTIONS"
                            :disabled="!canUpdate"
                        />
                    </FormField>
                    <FormField
                        v-if="hasLabel"
                        id="widget-launcher-width-mode"
                        label="Ancho"
                        :error="fieldError('launcher_width')"
                    >
                        <div class="flex gap-1.5">
                            <AppSelect
                                id="widget-launcher-width-mode"
                                v-model="widthMode"
                                :options="WIDTH_OPTIONS"
                                :disabled="!canUpdate"
                                class="min-w-0 flex-1"
                            />
                            <Input
                                v-if="form.launcher_width !== null"
                                id="widget-launcher-width"
                                :model-value="form.launcher_width"
                                v-bind="
                                    validation.field(
                                        'launcher_width',
                                        'widget-launcher-width',
                                    )
                                "
                                type="number"
                                :min="LAUNCHER_WIDTH.min"
                                :max="LAUNCHER_WIDTH.max"
                                step="1"
                                class="w-20"
                                aria-label="Ancho fijo en píxeles"
                                @update:model-value="
                                    (value: string | number) =>
                                        (form.launcher_width =
                                            value === '' ? 0 : Number(value))
                                "
                            />
                        </div>
                    </FormField>
                </div>

                <div class="widget-color-grid col-span-full">
                    <WebChatColorField
                        id="widget-launcher-color"
                        v-model="form.launcher_color"
                        label="Color del botón"
                        :fallback="fallbacks.launcher_color"
                        :swatches="ACCENT_SWATCHES"
                        :error="fieldError('launcher_color')"
                        :disabled="!canUpdate"
                    />
                    <WebChatColorField
                        id="widget-launcher-icon-color"
                        v-model="form.launcher_icon_color"
                        label="Color del ícono"
                        :fallback="fallbacks.launcher_icon_color"
                        :error="fieldError('launcher_icon_color')"
                        :disabled="!canUpdate"
                    />
                    <FormField
                        id="widget-launcher-icon"
                        label="Ícono"
                        :error="fieldError('launcher_icon')"
                    >
                        <AppSelect
                            id="widget-launcher-icon"
                            v-model="form.launcher_icon"
                            :options="ICON_OPTIONS"
                            :disabled="
                                !canUpdate || launcherImagePreview !== null
                            "
                        />
                    </FormField>
                    <FormField
                        id="widget-launcher-image"
                        label="Ícono propio"
                        :error="
                            launcherImageError ?? form.errors.launcher_image
                        "
                    >
                        <div class="flex items-center gap-1.5">
                            <img
                                v-if="launcherImagePreview"
                                :src="launcherImagePreview"
                                alt="Ícono propio del botón"
                                class="size-9 shrink-0 rounded-md border object-contain p-0.5"
                            />
                            <input
                                id="widget-launcher-image"
                                ref="launcherImageInput"
                                type="file"
                                accept="image/png,image/jpeg,image/webp"
                                class="sr-only"
                                :disabled="!canUpdate"
                                @change="pickLauncherImage"
                            />
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                class="h-9"
                                :disabled="!canUpdate"
                                :title="`PNG, JPG o WebP cuadrado, hasta ${options.limits.avatar_kb} KB`"
                                @click="launcherImageInput?.click()"
                            >
                                <Upload />
                                {{ launcherImagePreview ? 'Cambiar' : 'Subir' }}
                            </Button>
                            <Button
                                v-if="launcherImagePreview"
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="size-9"
                                aria-label="Quitar ícono propio"
                                title="Quitar"
                                :disabled="!canUpdate"
                                @click="removeLauncherImage"
                            >
                                <Trash2 />
                            </Button>
                        </div>
                    </FormField>
                    <FormField
                        id="widget-launcher-shape"
                        label="Forma"
                        :error="fieldError('launcher_shape')"
                    >
                        <AppSelect
                            id="widget-launcher-shape"
                            v-model="form.launcher_shape"
                            :options="SHAPE_OPTIONS"
                            :disabled="!canUpdate"
                        />
                    </FormField>
                    <FormField
                        id="widget-launcher-size"
                        label="Tamaño"
                        :error="fieldError('launcher_size')"
                    >
                        <AppSelect
                            id="widget-launcher-size"
                            v-model="form.launcher_size"
                            :options="SIZE_OPTIONS"
                            :disabled="!canUpdate"
                        />
                    </FormField>
                    <FormField
                        id="widget-position"
                        label="Posición"
                        :error="fieldError('position')"
                    >
                        <AppSelect
                            id="widget-position"
                            v-model="form.position"
                            :options="options.positions"
                            :disabled="!canUpdate"
                        />
                    </FormField>
                    <FormField
                        id="widget-offset-x"
                        label="Margen lateral (px)"
                        :error="fieldError('launcher_offset_x')"
                    >
                        <Input
                            id="widget-offset-x"
                            :model-value="form.launcher_offset_x ?? ''"
                            v-bind="
                                validation.field(
                                    'launcher_offset_x',
                                    'widget-offset-x',
                                )
                            "
                            type="number"
                            min="0"
                            :max="MAX_LAUNCHER_OFFSET"
                            step="1"
                            @update:model-value="
                                (value: string | number) =>
                                    (form.launcher_offset_x =
                                        numberOrNull(value))
                            "
                        />
                    </FormField>
                    <FormField
                        id="widget-offset-y"
                        label="Margen inferior (px)"
                        :error="fieldError('launcher_offset_y')"
                    >
                        <Input
                            id="widget-offset-y"
                            :model-value="form.launcher_offset_y ?? ''"
                            v-bind="
                                validation.field(
                                    'launcher_offset_y',
                                    'widget-offset-y',
                                )
                            "
                            type="number"
                            min="0"
                            :max="MAX_LAUNCHER_OFFSET"
                            step="1"
                            @update:model-value="
                                (value: string | number) =>
                                    (form.launcher_offset_y =
                                        numberOrNull(value))
                            "
                        />
                    </FormField>
                </div>
                <p
                    v-if="lowContrast.length > 0"
                    class="col-span-full flex items-start gap-1.5 text-xs text-warning-soft-foreground"
                    role="status"
                >
                    <TriangleAlert
                        class="mt-px size-3.5 shrink-0"
                        aria-hidden="true"
                    />
                    <span
                        >Contraste bajo en
                        {{
                            lowContrast
                                .map(
                                    (pair) =>
                                        `${pair.label} (${pair.ratio.toFixed(1)}:1)`,
                                )
                                .join(', ')
                        }}: podría leerse con dificultad.</span
                    >
                </p>

                <h4 class="widget-group-title">Ventana del chat</h4>
                <div class="widget-color-grid col-span-full">
                    <FormField
                        id="widget-panel-width"
                        :label="`Ancho (${PANEL_WIDTH.min}–${PANEL_WIDTH.max} px)`"
                        :error="fieldError('panel_width')"
                    >
                        <Input
                            id="widget-panel-width"
                            :model-value="form.panel_width ?? ''"
                            v-bind="
                                validation.field(
                                    'panel_width',
                                    'widget-panel-width',
                                )
                            "
                            type="number"
                            :min="PANEL_WIDTH.min"
                            :max="PANEL_WIDTH.max"
                            step="10"
                            @update:model-value="
                                (value: string | number) =>
                                    (form.panel_width = numberOrNull(value))
                            "
                        />
                    </FormField>
                    <FormField
                        id="widget-panel-height"
                        :label="`Alto (${PANEL_HEIGHT.min}–${PANEL_HEIGHT.max} px)`"
                        :error="fieldError('panel_height')"
                    >
                        <Input
                            id="widget-panel-height"
                            :model-value="form.panel_height ?? ''"
                            v-bind="
                                validation.field(
                                    'panel_height',
                                    'widget-panel-height',
                                )
                            "
                            type="number"
                            :min="PANEL_HEIGHT.min"
                            :max="PANEL_HEIGHT.max"
                            step="10"
                            @update:model-value="
                                (value: string | number) =>
                                    (form.panel_height = numberOrNull(value))
                            "
                        />
                    </FormField>
                </div>
            </section>
        </FormSection>

        <FormSection
            id="widget-identificacion"
            title="Identificación de visitantes"
            description="Si tu sitio tiene usuarios con sesión, puede identificarlos con una firma para que sus conversaciones los sigan entre dispositivos. Un visitante identificado nunca se mezcla con contactos de WhatsApp."
        >
            <section class="grid gap-4" aria-labelledby="widget-identity-title">
                <h3 id="widget-identity-title" class="sr-only">
                    Identificación de visitantes
                </h3>
                <FormField
                    id="widget-identity-mode"
                    label="Modo"
                    :error="fieldError('identity_mode')"
                >
                    <RadioCards
                        id="widget-identity-mode"
                        v-model="form.identity_mode"
                        :options="options.identityModes"
                        aria-label="Modo de identificación de visitantes"
                        class="[--col-min:13rem]"
                        :disabled="!canUpdate"
                    />
                </FormField>

                <div
                    v-if="form.identity_mode !== 'anonymous'"
                    class="grid gap-3 rounded-lg border p-4"
                >
                    <div class="flex gap-2 text-xs text-muted-foreground">
                        <Info
                            class="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <p class="max-w-prose">
                            <strong class="font-medium text-foreground"
                                >Instrucciones para el desarrollador de tu
                                sitio:</strong
                            >
                            tu servidor firma los datos del usuario con
                            HMAC-SHA256 usando el secreto de identificación. La
                            firma vale 5 minutos y cada nonce se acepta una sola
                            vez. El secreto nunca debe llegar al navegador ni al
                            código del sitio.
                        </p>
                    </div>
                    <pre
                        class="max-h-72 overflow-auto rounded-md border bg-surface-sunken p-3 text-xs leading-relaxed"
                    ><code class="font-mono whitespace-pre">{{ signatureExample }}</code></pre>
                </div>

                <div
                    class="flex flex-wrap items-center justify-between gap-3 rounded-lg border px-3 py-2"
                >
                    <div class="flex items-center gap-2 text-sm">
                        <ShieldCheck
                            class="size-4 text-muted-foreground"
                            aria-hidden="true"
                        />
                        Secreto de identificación:
                        <strong>{{
                            widget.identity_secret_set
                                ? 'generado'
                                : 'sin generar'
                        }}</strong>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="
                            !canUpdate || rotating !== null || hasChanges
                        "
                        :aria-describedby="
                            hasChanges ? 'widget-secret-blocked' : undefined
                        "
                        @click="confirmSecretOpen = true"
                    >
                        <KeyRound />
                        {{
                            widget.identity_secret_set
                                ? 'Rotar secreto'
                                : 'Generar secreto'
                        }}
                    </Button>
                </div>
                <p
                    v-if="canUpdate && hasChanges"
                    id="widget-secret-blocked"
                    class="text-xs text-muted-foreground"
                >
                    Guarda o descarta los cambios del widget antes de generar o
                    rotar el secreto.
                </p>

                <div
                    v-if="revealedSecret"
                    class="grid gap-2 rounded-lg border border-transparent bg-warning-soft p-3"
                >
                    <p class="flex gap-1.5 text-xs font-medium" role="status">
                        <TriangleAlert
                            class="mt-px size-3.5 shrink-0"
                            aria-hidden="true"
                        />
                        Copia el secreto ahora y guárdalo en tu servidor: no se
                        volverá a mostrar.
                    </p>
                    <div class="flex gap-2">
                        <code
                            class="min-w-0 flex-1 rounded-md border bg-background px-2 py-1.5 font-mono text-xs break-all select-all"
                            data-testid="identity-secret"
                            >{{ revealedSecret }}</code
                        >
                        <Button
                            v-if="canCopy"
                            type="button"
                            variant="outline"
                            size="icon"
                            aria-label="Copiar el secreto"
                            @click="copySecret"
                        >
                            <Check v-if="copied" />
                            <Copy v-else />
                        </Button>
                    </div>
                </div>
            </section>
        </FormSection>

        <FormSection
            id="widget-formulario"
            title="Formulario previo"
            description="Datos que se piden al visitante antes de su primer mensaje. Sin campos, puede escribir directamente."
        >
            <section class="grid gap-4" aria-labelledby="widget-prechat-title">
                <h3 id="widget-prechat-title" class="sr-only">
                    Formulario previo
                </h3>
                <div class="auto-grid [--col-min:13rem]">
                    <label
                        v-for="field in [
                            { value: 'name', label: 'Nombre' },
                            { value: 'email', label: 'Correo electrónico' },
                        ] as const"
                        :key="field.value"
                        class="flex cursor-pointer items-center gap-2.5 rounded-lg border px-3 py-2.5 text-sm transition-colors hover:border-border-strong has-[[data-state=checked]]:border-primary/50 has-[[data-state=checked]]:bg-primary-soft/40"
                    >
                        <Checkbox
                            :model-value="
                                form.prechat.fields.includes(field.value)
                            "
                            :disabled="!canUpdate"
                            @update:model-value="
                                (checked) =>
                                    togglePrechatField(
                                        field.value,
                                        checked === true,
                                    )
                            "
                        />
                        {{ field.label }}
                    </label>
                    <div
                        v-if="form.prechat.fields.length > 0"
                        class="flex items-center justify-between gap-3 rounded-lg border px-3 py-2.5"
                    >
                        <label for="widget-prechat-required" class="text-sm"
                            >Datos obligatorios</label
                        >
                        <Switch
                            id="widget-prechat-required"
                            v-model="form.prechat.required"
                            :disabled="!canUpdate"
                        />
                    </div>
                </div>
                <FormField
                    id="widget-privacy"
                    label="Aviso de privacidad (opcional)"
                    :error="fieldError('prechat.privacy_notice')"
                >
                    <Textarea
                        id="widget-privacy"
                        v-model="form.prechat.privacy_notice"
                        v-bind="
                            validation.field(
                                'prechat.privacy_notice',
                                'widget-privacy',
                            )
                        "
                        rows="2"
                        maxlength="500"
                        placeholder="Usaremos tus datos solo para atender tu solicitud."
                    />
                </FormField>
            </section>
        </FormSection>

        <FormSection
            id="widget-disponibilidad"
            title="Disponibilidad"
            description="Cuándo se muestra el chat en línea y cuánto conserva el visitante su conversación."
        >
            <section
                class="field-grid [--col-min:16rem]"
                aria-labelledby="widget-availability-title"
            >
                <h3 id="widget-availability-title" class="sr-only">
                    Disponibilidad
                </h3>
                <FormField
                    id="widget-availability"
                    label="Cuándo se muestra en línea"
                    :error="fieldError('availability_mode')"
                    hint="Según el horario usa el horario de atención del departamento predeterminado."
                >
                    <AppSelect
                        id="widget-availability"
                        v-model="form.availability_mode"
                        :options="options.availabilityModes"
                        :disabled="!canUpdate"
                    />
                </FormField>
                <FormField
                    id="widget-idle-days"
                    label="Días de sesión sin actividad"
                    :error="fieldError('session_idle_days')"
                    :hint="`El visitante conserva su conversación hasta ${options.limits.session_idle_days} días como máximo.`"
                >
                    <Input
                        id="widget-idle-days"
                        :model-value="form.session_idle_days ?? ''"
                        v-bind="
                            validation.field(
                                'session_idle_days',
                                'widget-idle-days',
                            )
                        "
                        type="number"
                        min="1"
                        :max="options.limits.session_idle_days"
                        step="1"
                        class="w-32"
                        @update:model-value="
                            (value: string | number) =>
                                (form.session_idle_days =
                                    value === '' ? null : Number(value))
                        "
                    />
                </FormField>
                <div class="grid gap-2">
                    <span class="text-sm font-medium" aria-hidden="true"
                        >Archivos de visitantes</span
                    >
                    <div
                        class="flex min-h-9 items-center justify-between gap-3 rounded-md border px-3 py-1.5"
                        :title="
                            options.visitorFiles
                                ? `Imágenes, PDF, Office y audio hasta ${options.visitorFiles.max_mb} MB; videos hasta ${options.visitorFiles.video_max_mb} MB.`
                                : undefined
                        "
                    >
                        <label for="widget-visitor-files" class="text-sm"
                            >Permitir que el visitante envíe archivos</label
                        >
                        <Switch
                            id="widget-visitor-files"
                            v-model="form.allow_visitor_files"
                            :disabled="!canUpdate"
                        />
                    </div>
                </div>
                <FormField
                    id="widget-offline"
                    label="Mensaje fuera de línea (opcional)"
                    :error="fieldError('offline_text')"
                    class="col-span-full"
                >
                    <Textarea
                        id="widget-offline"
                        v-model="form.offline_text"
                        v-bind="
                            validation.field('offline_text', 'widget-offline')
                        "
                        rows="2"
                        maxlength="500"
                        placeholder="Ahora no hay nadie disponible. Déjanos tu mensaje y te responderemos."
                    />
                </FormField>
            </section>
        </FormSection>

        <FormSection
            id="widget-clave"
            title="Clave pública"
            description="Identifica al widget en el fragmento de código; no es un secreto. Rótala si se insertó donde no debía: el código anterior dejará de funcionar y se cerrarán las sesiones abiertas de los visitantes."
        >
            <section class="grid gap-3" aria-labelledby="widget-key-title">
                <h3 id="widget-key-title" class="sr-only">Clave pública</h3>
                <div class="flex flex-wrap items-center gap-2">
                    <code
                        class="min-w-0 flex-1 truncate rounded-md border bg-surface-sunken px-2 py-1.5 font-mono text-xs"
                        >{{ widget.public_key }}</code
                    >
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="text-destructive hover:bg-destructive/10 hover:text-destructive"
                        :disabled="
                            !canUpdate || rotating !== null || hasChanges
                        "
                        :aria-describedby="
                            hasChanges ? 'widget-rotate-blocked' : undefined
                        "
                        @click="confirmKeyOpen = true"
                    >
                        <RefreshCw />
                        Rotar clave
                    </Button>
                </div>
                <p
                    v-if="canUpdate && hasChanges"
                    id="widget-rotate-blocked"
                    class="text-xs text-muted-foreground"
                >
                    Guarda o descarta los cambios del widget antes de rotar la
                    clave.
                </p>
            </section>

            <template v-if="canUpdate" #footer>
                <Button
                    type="button"
                    :disabled="!hasChanges"
                    :loading="form.processing"
                    @click="save"
                >
                    Guardar widget
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="!hasChanges || form.processing"
                    @click="discard"
                >
                    Descartar
                </Button>
                <span
                    class="text-sm text-muted-foreground max-sm:sr-only"
                    aria-live="polite"
                    >{{
                        hasChanges
                            ? 'Cambios del widget sin guardar'
                            : 'Sin cambios en el widget'
                    }}</span
                >
            </template>
        </FormSection>

        <ConfirmDialog
            v-model:open="confirmKeyOpen"
            title="¿Rotar la clave pública?"
            description="El fragmento de código instalado dejará de funcionar y se cerrarán las sesiones de todos los visitantes. Tendrás que pegar el código nuevo en tu sitio."
            confirm-label="Rotar clave"
            destructive
            :processing="rotating === 'key'"
            @confirm="rotate('key')"
        />

        <ConfirmDialog
            v-model:open="confirmSecretOpen"
            :title="
                widget.identity_secret_set
                    ? '¿Rotar el secreto de identificación?'
                    : '¿Generar el secreto de identificación?'
            "
            description="Se mostrará una sola vez. Las firmas hechas con el secreto anterior dejarán de aceptarse: actualízalo en tu servidor de inmediato."
            :confirm-label="
                widget.identity_secret_set ? 'Rotar secreto' : 'Generar secreto'
            "
            :destructive="widget.identity_secret_set"
            :processing="rotating === 'secret'"
            @confirm="rotate('secret')"
        />
    </div>
</template>

<style scoped>
.widget-group-title {
    grid-column: 1 / -1;
    margin-top: 0.5rem;
    padding-top: 0.75rem;
    border-top: 1px solid var(--border);
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--muted-foreground);
}

.widget-color-grid {
    display: grid;
    gap: 0.75rem;
    grid-template-columns: repeat(auto-fill, minmax(9.5rem, 1fr));
}
</style>
