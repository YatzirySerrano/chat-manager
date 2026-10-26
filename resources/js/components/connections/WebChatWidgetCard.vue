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
} from '@lucide/vue';
import { useClipboard } from '@vueuse/core';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import type { WebChatPreviewAppearance } from '@/components/connections/WebChatPreview.vue';
import FormField from '@/components/FormField.vue';
import InputError from '@/components/InputError.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
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
};

/**
 * Widget settings of a web chat connection: authorized origins,
 * appearance, visitor identification, pre-chat form and availability, plus
 * the rotation of the public key and of the identity secret. It saves on
 * its own endpoint, apart from the connection form that contains it, and
 * reports the unsaved appearance so the preview can follow it live.
 */
const props = defineProps<{
    connectionId: number;
    widget: WebChatWidgetSettings;
    options: WebChatWidgetOptions;
    canUpdate: boolean;
}>();

const emit = defineEmits<{
    appearance: [appearance: WebChatPreviewAppearance, dirty: boolean];
}>();

type WidgetForm = {
    allowed_origins: string[];
    title: string;
    subtitle: string;
    welcome_text: string;
    offline_text: string;
    position: WebChatPosition;
    accent_color: string;
    launcher_enabled: boolean;
    launcher_label: string;
    avatar: File | null;
    remove_avatar: boolean;
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
        launcher_enabled: widget.launcher_enabled,
        launcher_label: widget.launcher_label ?? '',
        avatar: null,
        remove_avatar: false,
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
    ignore: ['avatar', 'remove_avatar'],
    requireChanges: true,
});

const hasChanges = computed(
    () => validation.hasChanges || form.avatar !== null || form.remove_avatar,
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

async function onAvatarChange(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;
    avatarError.value = null;

    if (!file) {
        return;
    }

    if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type)) {
        avatarError.value = 'La imagen debe ser PNG, JPG o WebP.';
        input.value = '';

        return;
    }

    if (file.size > props.options.limits.avatar_kb * 1024) {
        avatarError.value = `La imagen no debe pesar más de ${props.options.limits.avatar_kb} KB.`;
        input.value = '';

        return;
    }

    form.avatar = file;
    form.remove_avatar = false;
    avatarPreview.value = await readAsDataUrl(file);
}

function removeAvatar(): void {
    form.avatar = null;
    form.remove_avatar = props.widget.avatar_url !== null;
    avatarPreview.value = null;
}

const appearance = computed<WebChatPreviewAppearance>(() => ({
    title: form.title,
    subtitle: form.subtitle || null,
    welcome_text: form.welcome_text || null,
    offline_text: form.offline_text || null,
    accent_color: HEX_COLOR.test(form.accent_color)
        ? form.accent_color
        : props.widget.accent_color,
    position: form.position,
    launcher_enabled: form.launcher_enabled,
    launcher_label: form.launcher_label || null,
    avatar: avatarPreview.value,
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
        validation.load(values);
    },
);

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

const nativeSelectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50 dark:bg-input/30';

const fieldError = (path: string): string | undefined =>
    validation.error(path) ?? form.errors[path as keyof WidgetForm];
</script>

<template>
    <SurfaceCard
        title="Widget de chat web"
        description="Dónde se puede insertar el chat, cómo se ve y cómo se identifican los visitantes."
        body-class="grid gap-6 p-5"
    >
        <div class="grid gap-6" @keydown.enter="onEnter">
            <section class="grid gap-3" aria-labelledby="widget-origins-title">
                <div>
                    <h3 id="widget-origins-title" class="text-sm font-medium">
                        Dominios autorizados
                    </h3>
                    <p class="text-xs text-muted-foreground">
                        Solo estos sitios pueden mostrar el chat. Escribe el
                        origen exacto (https://tienda.example.com); los
                        subdominios no se incluyen solos. Usa http únicamente
                        con localhost o 127.0.0.1 para desarrollo.
                    </p>
                </div>
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

            <section
                class="grid gap-4 border-t pt-5 sm:grid-cols-2"
                aria-labelledby="widget-appearance-title"
            >
                <h3
                    id="widget-appearance-title"
                    class="text-sm font-medium sm:col-span-2"
                >
                    Apariencia
                </h3>
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
                    class="sm:col-span-2"
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
                        <input
                            type="color"
                            :value="
                                HEX_COLOR.test(form.accent_color)
                                    ? form.accent_color
                                    : widget.accent_color
                            "
                            class="h-9 w-12 shrink-0 cursor-pointer rounded-md border border-input bg-transparent p-1 disabled:cursor-not-allowed disabled:opacity-50"
                            aria-label="Elegir el color de acento"
                            :disabled="!canUpdate"
                            @input="
                                form.accent_color = (
                                    $event.target as HTMLInputElement
                                ).value
                            "
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
                    id="widget-position"
                    label="Posición"
                    :error="fieldError('position')"
                >
                    <select
                        id="widget-position"
                        v-model="form.position"
                        :class="nativeSelectClass"
                        :disabled="!canUpdate"
                    >
                        <option
                            v-for="option in options.positions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                </FormField>
                <div
                    class="flex items-center justify-between gap-3 rounded-lg border px-3 py-2"
                >
                    <label for="widget-launcher" class="text-sm"
                        >Mostrar botón flotante</label
                    >
                    <Switch
                        id="widget-launcher"
                        v-model="form.launcher_enabled"
                        :disabled="!canUpdate"
                    />
                </div>
                <FormField
                    id="widget-launcher-label"
                    label="Texto del botón (opcional)"
                    :error="fieldError('launcher_label')"
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
                        placeholder="¿Necesitas ayuda?"
                        :disabled="!form.launcher_enabled"
                    />
                </FormField>
                <FormField
                    id="widget-avatar"
                    label="Imagen (opcional)"
                    :error="avatarError ?? form.errors.avatar"
                    :hint="`PNG, JPG o WebP de hasta ${options.limits.avatar_kb} KB.`"
                    class="sm:col-span-2"
                >
                    <div class="flex flex-wrap items-center gap-3">
                        <img
                            v-if="avatarPreview"
                            :src="avatarPreview"
                            alt="Imagen actual del chat"
                            class="size-10 rounded-full border object-cover"
                        />
                        <input
                            id="widget-avatar"
                            type="file"
                            accept="image/png,image/jpeg,image/webp"
                            class="max-w-full text-sm file:mr-3 file:rounded-md file:border file:border-input file:bg-transparent file:px-3 file:py-1.5 file:text-sm"
                            :disabled="!canUpdate"
                            @change="onAvatarChange"
                        />
                        <Button
                            v-if="avatarPreview"
                            type="button"
                            variant="ghost"
                            size="sm"
                            :disabled="!canUpdate"
                            @click="removeAvatar"
                        >
                            Quitar imagen
                        </Button>
                    </div>
                </FormField>
            </section>

            <section
                class="grid gap-4 border-t pt-5"
                aria-labelledby="widget-identity-title"
            >
                <div>
                    <h3 id="widget-identity-title" class="text-sm font-medium">
                        Identificación de visitantes
                    </h3>
                    <p class="text-xs text-muted-foreground">
                        Si tu sitio tiene usuarios con sesión, puede
                        identificarlos con una firma para que sus conversaciones
                        los sigan entre dispositivos. Un visitante identificado
                        nunca se mezcla con contactos de WhatsApp.
                    </p>
                </div>
                <FormField
                    id="widget-identity-mode"
                    label="Modo"
                    :error="fieldError('identity_mode')"
                >
                    <select
                        id="widget-identity-mode"
                        v-model="form.identity_mode"
                        :class="nativeSelectClass"
                        :disabled="!canUpdate"
                    >
                        <option
                            v-for="option in options.identityModes"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
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
                        <p>
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
                        class="max-h-72 overflow-auto rounded-md border bg-muted/50 p-3 text-xs leading-relaxed"
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

            <section
                class="grid gap-4 border-t pt-5"
                aria-labelledby="widget-prechat-title"
            >
                <div>
                    <h3 id="widget-prechat-title" class="text-sm font-medium">
                        Formulario previo
                    </h3>
                    <p class="text-xs text-muted-foreground">
                        Datos que se piden al visitante antes de su primer
                        mensaje. Sin campos, puede escribir directamente.
                    </p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <label
                        v-for="field in [
                            { value: 'name', label: 'Nombre' },
                            { value: 'email', label: 'Correo electrónico' },
                        ] as const"
                        :key="field.value"
                        class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm"
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
                </div>
                <div
                    v-if="form.prechat.fields.length > 0"
                    class="flex items-center justify-between gap-3 rounded-lg border px-3 py-2"
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

            <section
                class="grid gap-4 border-t pt-5 sm:grid-cols-2"
                aria-labelledby="widget-availability-title"
            >
                <h3
                    id="widget-availability-title"
                    class="text-sm font-medium sm:col-span-2"
                >
                    Disponibilidad
                </h3>
                <FormField
                    id="widget-availability"
                    label="Cuándo se muestra en línea"
                    :error="fieldError('availability_mode')"
                    hint="Según el horario usa el horario de atención del departamento predeterminado."
                >
                    <select
                        id="widget-availability"
                        v-model="form.availability_mode"
                        :class="nativeSelectClass"
                        :disabled="!canUpdate"
                    >
                        <option
                            v-for="option in options.availabilityModes"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
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
                <FormField
                    id="widget-offline"
                    label="Mensaje fuera de línea (opcional)"
                    :error="fieldError('offline_text')"
                    class="sm:col-span-2"
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

            <section
                class="grid gap-3 border-t pt-5"
                aria-labelledby="widget-key-title"
            >
                <div>
                    <h3 id="widget-key-title" class="text-sm font-medium">
                        Clave pública
                    </h3>
                    <p class="text-xs text-muted-foreground">
                        Identifica al widget en el fragmento de código; no es un
                        secreto. Rótala si se insertó donde no debía: el código
                        anterior dejará de funcionar y se cerrarán las sesiones
                        abiertas de los visitantes.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <code
                        class="min-w-0 flex-1 truncate rounded-md border bg-muted/50 px-2 py-1.5 font-mono text-xs"
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

            <div
                v-if="canUpdate"
                class="flex flex-wrap items-center justify-end gap-3 border-t pt-4"
            >
                <span
                    class="text-sm text-muted-foreground max-sm:sr-only"
                    aria-live="polite"
                    >{{
                        hasChanges
                            ? 'Cambios del widget sin guardar'
                            : 'Sin cambios en el widget'
                    }}</span
                >
                <Button
                    type="button"
                    variant="outline"
                    :disabled="!hasChanges || form.processing"
                    @click="discard"
                >
                    Descartar
                </Button>
                <Button
                    type="button"
                    :disabled="!hasChanges || form.processing"
                    @click="save"
                >
                    <Spinner v-if="form.processing" />
                    Guardar widget
                </Button>
            </div>
        </div>

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
    </SurfaceCard>
</template>
