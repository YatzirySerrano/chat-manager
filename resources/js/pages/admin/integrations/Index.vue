<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Ban, Check, Copy, KeyRound, Plus, TriangleAlert } from '@lucide/vue';
import { useClipboard } from '@vueuse/core';
import { computed, onUnmounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import DataPagination from '@/components/DataPagination.vue';
import EmptyState from '@/components/EmptyState.vue';
import FormDialog from '@/components/FormDialog.vue';
import FormField from '@/components/FormField.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatDate, formatDateTime, formatRelative } from '@/lib/format';
import {
    destroy as destroyToken,
    index as tokensIndex,
    store as storeToken,
} from '@/routes/integrations/tokens';
import type { Paginated } from '@/types';
import type {
    ApiAbilityOption,
    ApiAbilityValue,
    ApiTokenConnectionOption,
    ApiTokenExpiry,
    ApiTokenStatus,
    ApiTokenSummary,
    IssuedApiToken,
} from '@/types/integrations';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Integraciones', href: tokensIndex() }],
    },
});

const props = defineProps<{
    tokens: Paginated<ApiTokenSummary>;
    abilities: ApiAbilityOption[];
    connections: ApiTokenConnectionOption[];
    departments: { id: number; name: string }[];
    expiry: ApiTokenExpiry;
    can: { manage: boolean };
}>();

const abilityLabels = computed<Record<string, string>>(() =>
    Object.fromEntries(props.abilities.map((a) => [a.value, a.label])),
);

const statusPresentation: Record<
    ApiTokenStatus,
    { label: string; class: string }
> = {
    active: { label: 'Activo', class: 'bg-success/10 text-success' },
    expired: { label: 'Vencido', class: 'bg-muted text-muted-foreground' },
    revoked: {
        label: 'Revocado',
        class: 'bg-destructive/10 text-destructive',
    },
};

/* Issuing ---------------------------------------------------------------- */

const formOpen = ref(false);

const form = useForm<{
    name: string;
    abilities: ApiAbilityValue[];
    connection_ids: number[];
    department_ids: number[];
    allowed_ips: string;
    expires_at: string;
}>({
    name: '',
    abilities: [],
    connection_ids: [],
    department_ids: [],
    allowed_ips: '',
    expires_at: props.expiry.default,
});

function openCreate(): void {
    form.reset();
    form.clearErrors();
    form.expires_at = props.expiry.default;
    formOpen.value = true;
}

function toggle<T>(list: T[], value: T, checked: boolean): T[] {
    const without = list.filter((item) => item !== value);

    return checked ? [...without, value] : without;
}

function firstError(prefix: string): string | undefined {
    const errors = form.errors as Record<string, string | undefined>;

    return (
        errors[prefix] ??
        Object.entries(errors).find(([key]) =>
            key.startsWith(`${prefix}.`),
        )?.[1]
    );
}

function submit(): void {
    form.transform((data) => ({
        ...data,
        department_ids:
            data.department_ids.length > 0 ? data.department_ids : null,
        allowed_ips: data.allowed_ips.trim() === '' ? null : data.allowed_ips,
    })).submit(storeToken(), {
        preserveScroll: true,
        onSuccess: () => {
            formOpen.value = false;
        },
    });
}

/* The secret, shown once ------------------------------------------------- */

const page = usePage();
const issued = ref<IssuedApiToken | null>(null);
const { copy, copied, isSupported } = useClipboard({ legacy: true });

function captureIssued(flash: Record<string, unknown> | undefined): void {
    const token = flash?.issued_token as IssuedApiToken | undefined;

    if (token && typeof token.token === 'string') {
        issued.value = token;
    }
}

captureIssued(page.flash as Record<string, unknown> | undefined);

const stopFlashListener = router.on('flash', (event) => {
    captureIssued(event.detail.flash as Record<string, unknown> | undefined);
});

onUnmounted(stopFlashListener);

const secretOpen = computed({
    get: () => issued.value !== null,
    set: (value: boolean) => {
        if (!value) {
            issued.value = null;
        }
    },
});

async function copySecret(): Promise<void> {
    if (!issued.value) {
        return;
    }

    await copy(issued.value.token);
    toast.success('Token copiado al portapapeles.');
}

/* Revoking --------------------------------------------------------------- */

const revoking = ref<ApiTokenSummary | null>(null);
const revokeReason = ref('');
const revokeProcessing = ref(false);
const revokeError = ref<string | undefined>(undefined);

const revokeOpen = computed({
    get: () => revoking.value !== null,
    set: (value: boolean) => {
        if (!value && !revokeProcessing.value) {
            revoking.value = null;
        }
    },
});

function openRevoke(token: ApiTokenSummary): void {
    revoking.value = token;
    revokeReason.value = '';
    revokeError.value = undefined;
}

function confirmRevoke(): void {
    const token = revoking.value;

    if (!token) {
        return;
    }

    router.delete(destroyToken.url(token.id), {
        data: { reason: revokeReason.value.trim() || null },
        preserveScroll: true,
        onStart: () => (revokeProcessing.value = true),
        onFinish: () => (revokeProcessing.value = false),
        onError: (errors) => {
            revokeError.value = errors.reason ?? Object.values(errors)[0];
        },
        onSuccess: () => {
            revoking.value = null;
        },
    });
}

function departmentsLabel(token: ApiTokenSummary): string {
    return token.departments === null
        ? 'Todos los departamentos'
        : token.departments.map((d) => d.name).join(', ');
}
</script>

<template>
    <Head title="Integraciones" />

    <div
        class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8"
    >
        <PageHeader
            help="integraciones"
            title="Integraciones"
            description="Tokens de la API de integración (/api/v1) para que otros sistemas consulten conversaciones y envíen mensajes. Cada token tiene capacidades, conexiones permitidas y una fecha de vencimiento."
        >
            <template v-if="can.manage" #actions>
                <Button @click="openCreate">
                    <Plus />
                    Nuevo token
                </Button>
            </template>
        </PageHeader>

        <SurfaceCard>
            <ul v-if="tokens.data.length > 0" class="divide-y">
                <li
                    v-for="token in tokens.data"
                    :key="token.id"
                    class="grid gap-3 p-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-start"
                    :class="
                        token.status !== 'active' && 'text-muted-foreground'
                    "
                >
                    <div class="grid min-w-0 gap-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="font-medium wrap-anywhere">
                                {{ token.name }}
                            </h2>
                            <span
                                class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="statusPresentation[token.status].class"
                            >
                                {{ statusPresentation[token.status].label }}
                            </span>
                        </div>
                        <code
                            class="w-fit max-w-full rounded bg-muted px-1.5 py-0.5 font-mono text-xs wrap-anywhere"
                            >{{ token.prefix }}_…</code
                        >
                        <div class="flex flex-wrap gap-1.5">
                            <span
                                v-for="ability in token.abilities"
                                :key="ability"
                                class="rounded-full border px-2 py-0.5 text-xs"
                            >
                                {{ abilityLabels[ability] ?? ability }}
                            </span>
                        </div>
                        <dl
                            class="grid gap-x-6 gap-y-1 text-xs text-muted-foreground sm:grid-cols-2"
                        >
                            <div class="min-w-0">
                                <dt class="inline font-medium">Conexiones:</dt>
                                {{ ' ' }}
                                <dd class="inline wrap-anywhere">
                                    {{
                                        token.connections
                                            .map((c) => c.name)
                                            .join(', ')
                                    }}
                                </dd>
                            </div>
                            <div class="min-w-0">
                                <dt class="inline font-medium">
                                    Departamentos:
                                </dt>
                                {{ ' ' }}
                                <dd class="inline wrap-anywhere">
                                    {{ departmentsLabel(token) }}
                                </dd>
                            </div>
                            <div class="min-w-0">
                                <dt class="inline font-medium">IP:</dt>
                                {{ ' ' }}
                                <dd class="inline wrap-anywhere">
                                    {{
                                        token.allowed_ips?.join(', ') ||
                                        'Cualquiera'
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="inline font-medium">Vence:</dt>
                                {{ ' ' }}
                                <dd class="inline">
                                    {{ formatDate(token.expires_at) }}
                                </dd>
                            </div>
                            <div>
                                <dt class="inline font-medium">Último uso:</dt>
                                {{ ' ' }}
                                <dd
                                    class="inline"
                                    :title="
                                        token.last_used_at
                                            ? formatDateTime(token.last_used_at)
                                            : undefined
                                    "
                                >
                                    {{
                                        token.last_used_at
                                            ? formatRelative(token.last_used_at)
                                            : 'Nunca'
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="inline font-medium">Emitido:</dt>
                                {{ ' ' }}
                                <dd class="inline">
                                    {{ formatDate(token.created_at) }}
                                    <template v-if="token.created_by">
                                        por {{ token.created_by }}</template
                                    >
                                </dd>
                            </div>
                            <div
                                v-if="token.status === 'revoked'"
                                class="min-w-0 sm:col-span-2"
                            >
                                <dt class="inline font-medium">Revocado:</dt>
                                {{ ' ' }}
                                <dd class="inline wrap-anywhere">
                                    {{ formatDateTime(token.revoked_at) }}
                                    <template v-if="token.revoked_by">
                                        por {{ token.revoked_by }}</template
                                    ><template v-if="token.revoke_reason">
                                        — {{ token.revoke_reason }}</template
                                    >
                                </dd>
                            </div>
                        </dl>
                    </div>
                    <Button
                        v-if="can.manage && token.status === 'active'"
                        variant="outline"
                        class="w-full text-destructive sm:w-auto"
                        @click="openRevoke(token)"
                    >
                        <Ban />
                        Revocar
                    </Button>
                </li>
            </ul>

            <EmptyState
                v-else
                class="m-4 border-none"
                :icon="KeyRound"
                title="Aún no hay tokens de integración"
                :description="
                    can.manage
                        ? 'Emite un token para que otro sistema use la API de Chat Manager.'
                        : 'Cuando se emitan tokens aparecerán aquí.'
                "
            >
                <Button v-if="can.manage" @click="openCreate">
                    <Plus />
                    Nuevo token
                </Button>
            </EmptyState>

            <div
                v-if="tokens.meta.last_page > 1 || tokens.data.length > 0"
                class="border-t px-4 py-3"
            >
                <DataPagination :meta="tokens.meta" noun="tokens" />
            </div>
        </SurfaceCard>

        <FormDialog
            v-if="can.manage"
            v-model:open="formOpen"
            title="Nuevo token de integración"
            description="El token se muestra una sola vez al emitirlo. Guárdalo en el gestor de secretos del sistema que lo usará."
            size="lg"
            :processing="form.processing"
            :dirty="form.isDirty"
            submit-label="Emitir token"
            @submit="submit"
        >
            <div class="grid gap-5">
                <FormField
                    id="token-name"
                    label="Nombre"
                    :error="form.errors.name"
                    hint="Para reconocer qué sistema lo usa."
                >
                    <Input
                        id="token-name"
                        v-model="form.name"
                        maxlength="100"
                        placeholder="CRM de ventas"
                        :aria-invalid="form.errors.name ? true : undefined"
                    />
                </FormField>

                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">
                        Capacidades
                    </legend>
                    <label
                        v-for="ability in abilities"
                        :key="ability.value"
                        class="flex min-h-11 cursor-pointer items-center gap-3 rounded-lg border px-3 py-2 text-sm has-[[data-state=checked]]:border-primary/40"
                    >
                        <Checkbox
                            :model-value="
                                form.abilities.includes(ability.value)
                            "
                            @update:model-value="
                                (checked) =>
                                    (form.abilities = toggle(
                                        form.abilities,
                                        ability.value,
                                        checked === true,
                                    ))
                            "
                        />
                        <span class="min-w-0">{{ ability.label }}</span>
                        <code
                            class="ml-auto font-mono text-xs text-muted-foreground"
                            >{{ ability.value }}</code
                        >
                    </label>
                    <InputError :message="firstError('abilities')" />
                </fieldset>

                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">Conexiones</legend>
                    <p
                        v-if="connections.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        No hay conexiones disponibles.
                    </p>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <label
                            v-for="connection in connections"
                            :key="connection.id"
                            class="flex min-h-11 cursor-pointer items-center gap-3 rounded-lg border px-3 py-2 text-sm has-[[data-state=checked]]:border-primary/40"
                        >
                            <Checkbox
                                :model-value="
                                    form.connection_ids.includes(connection.id)
                                "
                                @update:model-value="
                                    (checked) =>
                                        (form.connection_ids = toggle(
                                            form.connection_ids,
                                            connection.id,
                                            checked === true,
                                        ))
                                "
                            />
                            <span class="min-w-0 truncate">{{
                                connection.name
                            }}</span>
                            <span
                                class="ml-auto shrink-0 text-xs text-muted-foreground"
                                >{{ connection.channel_label }}</span
                            >
                        </label>
                    </div>
                    <InputError :message="firstError('connection_ids')" />
                </fieldset>

                <fieldset v-if="departments.length > 0" class="grid gap-2">
                    <legend class="mb-1 text-sm font-medium">
                        Departamentos (opcional)
                    </legend>
                    <p class="mb-1 text-xs text-muted-foreground">
                        Sin elegir ninguno, el token alcanza todos los
                        departamentos de sus conexiones.
                    </p>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <label
                            v-for="department in departments"
                            :key="department.id"
                            class="flex min-h-11 cursor-pointer items-center gap-3 rounded-lg border px-3 py-2 text-sm has-[[data-state=checked]]:border-primary/40"
                        >
                            <Checkbox
                                :model-value="
                                    form.department_ids.includes(department.id)
                                "
                                @update:model-value="
                                    (checked) =>
                                        (form.department_ids = toggle(
                                            form.department_ids,
                                            department.id,
                                            checked === true,
                                        ))
                                "
                            />
                            <span class="min-w-0 truncate">{{
                                department.name
                            }}</span>
                        </label>
                    </div>
                    <InputError :message="firstError('department_ids')" />
                </fieldset>

                <FormField
                    id="token-ips"
                    label="IP permitidas (opcional)"
                    :error="firstError('allowed_ips')"
                    hint="Una IP o rango CIDR por línea (IPv4 o IPv6). Vacío: cualquier IP."
                >
                    <Textarea
                        id="token-ips"
                        v-model="form.allowed_ips"
                        rows="2"
                        placeholder="203.0.113.10&#10;198.51.100.0/24"
                        class="font-mono text-sm"
                    />
                </FormField>

                <FormField
                    id="token-expires"
                    label="Vence el"
                    :error="form.errors.expires_at"
                    :hint="`Como máximo ${expiry.max_days} días a partir de hoy.`"
                >
                    <Input
                        id="token-expires"
                        v-model="form.expires_at"
                        type="date"
                        :min="expiry.min"
                        :max="expiry.max"
                        class="sm:w-48"
                    />
                </FormField>
            </div>
        </FormDialog>

        <Dialog v-model:open="secretOpen">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Token emitido</DialogTitle>
                    <DialogDescription>
                        Copia el token de «{{ issued?.name }}» y guárdalo en un
                        lugar seguro.
                    </DialogDescription>
                </DialogHeader>
                <div
                    class="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm"
                    role="alert"
                >
                    <TriangleAlert
                        class="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    <p>
                        No se volverá a mostrar. Si lo pierdes, revócalo y emite
                        uno nuevo.
                    </p>
                </div>
                <div class="grid gap-2">
                    <Label for="issued-token">Token</Label>
                    <code
                        id="issued-token"
                        class="block rounded-lg border bg-muted p-3 font-mono text-xs break-all select-all"
                        >{{ issued?.token }}</code
                    >
                    <p class="text-xs text-muted-foreground">
                        Envíalo en el encabezado
                        <code class="font-mono">Authorization: Bearer …</code>
                    </p>
                </div>
                <DialogFooter class="gap-2">
                    <Button
                        v-if="isSupported"
                        variant="outline"
                        @click="copySecret"
                    >
                        <Check v-if="copied" />
                        <Copy v-else />
                        {{ copied ? 'Copiado' : 'Copiar token' }}
                    </Button>
                    <Button @click="secretOpen = false">Listo</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <ConfirmDialog
            v-model:open="revokeOpen"
            :title="`¿Revocar el token ${revoking?.name ?? ''}?`"
            description="Las llamadas que lo usen se rechazarán de inmediato. No se puede deshacer: para volver a conectar el sistema tendrás que emitir otro token."
            confirm-label="Revocar token"
            destructive
            :processing="revokeProcessing"
            @confirm="confirmRevoke"
        >
            <FormField
                id="revoke-reason"
                label="Motivo (opcional)"
                :error="revokeError"
            >
                <Textarea
                    id="revoke-reason"
                    v-model="revokeReason"
                    rows="2"
                    maxlength="255"
                    placeholder="Ej. El proveedor dejó de usar la integración"
                />
            </FormField>
        </ConfirmDialog>
    </div>
</template>
