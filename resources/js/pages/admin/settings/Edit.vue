<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { CircleDashed, Lock, PlugZap } from '@lucide/vue';
import { computed } from 'vue';
import PlatformHolidaysCard from '@/components/automation/PlatformHolidaysCard.vue';
import FieldHelp from '@/components/FieldHelp.vue';
import FormField from '@/components/FormField.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { useFormValidation } from '@/composables/useFormValidation';
import { integerBetween, maxLength, required } from '@/lib/validation';
import type { FieldRules } from '@/lib/validation';
import { edit, update } from '@/routes/admin/settings';
import type { PlatformSettings, Weekday } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Configuración', href: edit() }],
    },
});

const props = defineProps<{
    settings: PlatformSettings;
    timezones: string[];
    weekdays: Weekday[];
    departments: { id: number; name: string }[];
    integration: {
        whatsapp_accounts: number;
        active_whatsapp_accounts: number;
    };
    can: { update: boolean };
}>();

const weekdayLabels: Record<Weekday, string> = {
    monday: 'Lunes',
    tuesday: 'Martes',
    wednesday: 'Miércoles',
    thursday: 'Jueves',
    friday: 'Viernes',
    saturday: 'Sábado',
    sunday: 'Domingo',
};

/**
 * Props are reactive proxies, which structuredClone cannot copy (it threw a
 * DataCloneError and left this page blank). A JSON copy is enough for this
 * plain settings object and keeps the form independent from the props.
 */
const form = useForm<PlatformSettings>(
    JSON.parse(JSON.stringify(props.settings)) as PlatformSettings,
);

const validation = useFormValidation(form, {
    rules: () => {
        const rules: FieldRules = {
            'general.platform_name': [
                required('Escribe el nombre de la plataforma.'),
                maxLength(60),
            ],
            'general.timezone': [required('Elige la zona horaria.')],
            'operations.max_open_conversations_per_agent': [
                integerBetween(1, 200),
            ],
            'operations.auto_close_after_hours': [integerBetween(1, 720)],
        };

        for (const day of props.weekdays) {
            rules[`business_hours.days.${day}.end`] = [
                required('Indica la hora de cierre.'),
                (end) => {
                    const hours = form.business_hours.days[day];

                    return hours.open && String(end) <= hours.start
                        ? 'La hora de cierre debe ser posterior a la hora de apertura.'
                        : null;
                },
            ];
            rules[`business_hours.days.${day}.start`] = [
                required('Indica la hora de apertura.'),
            ];
        }

        return rules;
    },
    requireChanges: true,
});

function hoursError(day: Weekday): string | undefined {
    return (
        validation.error(`business_hours.days.${day}.end`) ??
        validation.error(`business_hours.days.${day}.start`)
    );
}

const nativeSelectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

function toNullableNumber(value: string | number): number | null {
    if (value === '' || value === null) {
        return null;
    }

    const parsed = Number(value);

    return Number.isFinite(parsed) ? parsed : null;
}

function submit(): void {
    if (!validation.validate()) {
        return;
    }

    form.submit(update(), {
        preserveScroll: true,
        onSuccess: () => {
            validation.load(
                JSON.parse(JSON.stringify(props.settings)) as PlatformSettings,
            );
        },
    });
}

const integrationConnected = computed(
    () => props.integration.active_whatsapp_accounts > 0,
);
</script>

<template>
    <Head title="Configuración" />

    <form class="flex flex-1 flex-col" novalidate @submit.prevent="submit">
        <fieldset
            :disabled="!can.update"
            class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-8 p-4 md:p-6 lg:p-8"
        >
            <PageHeader
                title="Configuración"
                description="Parámetros generales de la plataforma. Los cambios quedan registrados en la auditoría."
            />

            <p
                v-if="!can.update"
                class="flex items-center gap-2 rounded-xl border bg-card px-4 py-3 text-sm text-muted-foreground shadow-card"
            >
                <Lock class="size-4" />
                Puedes consultar la configuración, pero no modificarla.
            </p>

            <section
                class="grid gap-6 lg:grid-cols-[16rem_1fr]"
                aria-labelledby="general-heading"
            >
                <div>
                    <h2 id="general-heading" class="font-semibold">General</h2>
                    <p class="text-sm text-muted-foreground">
                        Nombre visible y zona horaria usada para mostrar fechas
                        y calcular indicadores diarios.
                    </p>
                </div>
                <div
                    class="grid gap-5 rounded-xl border bg-card p-5 shadow-card sm:grid-cols-2"
                >
                    <FormField
                        id="platform-name"
                        label="Nombre de la plataforma"
                        :error="validation.error('general.platform_name')"
                    >
                        <Input
                            id="platform-name"
                            v-model="form.general.platform_name"
                            v-bind="
                                validation.field(
                                    'general.platform_name',
                                    'platform-name',
                                )
                            "
                            required
                            maxlength="60"
                        />
                    </FormField>
                    <FormField
                        id="platform-timezone"
                        label="Zona horaria"
                        :error="validation.error('general.timezone')"
                    >
                        <select
                            id="platform-timezone"
                            v-bind="
                                validation.field(
                                    'general.timezone',
                                    'platform-timezone',
                                )
                            "
                            v-model="form.general.timezone"
                            :class="nativeSelectClass"
                        >
                            <option
                                v-for="zone in timezones"
                                :key="zone"
                                :value="zone"
                            >
                                {{ zone.replaceAll('_', ' ') }}
                            </option>
                        </select>
                    </FormField>
                </div>
            </section>

            <section
                class="grid gap-6 lg:grid-cols-[16rem_1fr]"
                aria-labelledby="hours-heading"
            >
                <div>
                    <h2 id="hours-heading" class="font-medium">
                        Horario de atención
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        Define cuándo se envía la bienvenida o el mensaje fuera
                        de horario. Cada departamento puede tener uno propio.
                    </p>
                </div>
                <div
                    class="overflow-hidden rounded-xl border bg-card shadow-card"
                >
                    <label
                        class="flex items-center justify-between gap-4 border-b p-5"
                    >
                        <span>
                            <span class="block text-sm font-medium"
                                >Aplicar horario de atención</span
                            >
                            <span class="block text-sm text-muted-foreground">
                                Si está desactivado, se considera atención
                                continua.
                            </span>
                        </span>
                        <Switch v-model="form.business_hours.enabled" />
                    </label>
                    <ul
                        class="divide-y"
                        :class="!form.business_hours.enabled && 'opacity-60'"
                    >
                        <li
                            v-for="day in weekdays"
                            :key="day"
                            class="grid grid-cols-[1fr_auto] items-center gap-3 px-5 py-3 sm:grid-cols-[8rem_auto_1fr]"
                        >
                            <span class="text-sm font-medium">{{
                                weekdayLabels[day]
                            }}</span>
                            <Switch
                                v-model="form.business_hours.days[day].open"
                                :disabled="!form.business_hours.enabled"
                                :aria-label="`Abierto el ${weekdayLabels[day].toLowerCase()}`"
                            />
                            <div
                                class="col-span-2 flex flex-wrap items-center gap-2 sm:col-span-1 sm:justify-end"
                            >
                                <template
                                    v-if="form.business_hours.days[day].open"
                                >
                                    <Input
                                        v-model="
                                            form.business_hours.days[day].start
                                        "
                                        type="time"
                                        class="w-36"
                                        :disabled="!form.business_hours.enabled"
                                        :aria-label="`Apertura ${weekdayLabels[day]}`"
                                    />
                                    <span class="text-sm text-muted-foreground"
                                        >a</span
                                    >
                                    <Input
                                        v-model="
                                            form.business_hours.days[day].end
                                        "
                                        type="time"
                                        class="w-36"
                                        :disabled="!form.business_hours.enabled"
                                        :aria-label="`Cierre ${weekdayLabels[day]}`"
                                        v-bind="
                                            validation.field(
                                                `business_hours.days.${day}.end`,
                                                `hours-${day}-end`,
                                            )
                                        "
                                        :id="`hours-${day}-end`"
                                    />
                                </template>
                                <span
                                    v-else
                                    class="text-sm text-muted-foreground"
                                    >Cerrado</span
                                >
                                <InputError
                                    :id="`hours-${day}-end-error`"
                                    class="w-full sm:text-right"
                                    :message="hoursError(day)"
                                />
                            </div>
                        </li>
                    </ul>
                </div>
            </section>

            <section
                class="grid gap-6 lg:grid-cols-[16rem_1fr]"
                aria-labelledby="holidays-heading"
            >
                <div>
                    <h2 id="holidays-heading" class="font-medium">
                        Días no laborables
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        Fechas en las que no se atiende. Se guardan aparte del
                        resto de la configuración.
                    </p>
                </div>
                <PlatformHolidaysCard
                    :holidays="settings.business_hours.holidays ?? []"
                    :can-update="can.update"
                />
            </section>

            <section
                class="grid gap-6 lg:grid-cols-[16rem_1fr]"
                aria-labelledby="operations-heading"
            >
                <div>
                    <h2 id="operations-heading" class="font-medium">
                        Operación
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        Cómo llegan las conversaciones a los agentes. Cada
                        departamento elige además su forma de distribución en su
                        propia ficha.
                    </p>
                </div>
                <div
                    class="grid gap-5 rounded-xl border bg-card p-5 shadow-card sm:grid-cols-2"
                >
                    <FormField
                        id="default-department"
                        label="Departamento predeterminado"
                        hint="Recibe las conversaciones nuevas sin departamento."
                        :help="{
                            description:
                                'Cola a la que entra una conversación nueva cuando su conexión no tiene un departamento predeterminado propio.',
                            howTo: 'Elige un departamento activo autorizado para las conexiones. Si no eliges ninguno, las conversaciones quedan «Sin departamento» y solo las ven quienes tienen alcance sobre toda la organización.',
                            warning:
                                'Nunca se adivina el departamento a partir del contenido de los mensajes.',
                        }"
                        :error="
                            validation.error('operations.default_department_id')
                        "
                    >
                        <select
                            id="default-department"
                            v-model="form.operations.default_department_id"
                            :class="nativeSelectClass"
                        >
                            <option :value="null">Sin asignar</option>
                            <option
                                v-for="department in departments"
                                :key="department.id"
                                :value="department.id"
                            >
                                {{ department.name }}
                            </option>
                        </select>
                    </FormField>
                    <FormField
                        id="max-open"
                        label="Máximo de conversaciones por agente"
                        hint="Déjalo vacío para no limitar."
                        :help="{
                            description:
                                'Cuántas conversaciones en atención o en espera puede tener un agente antes de dejar de aceptar pendientes y de recibir asignaciones automáticas.',
                            example: '8',
                            warning:
                                'Quien tiene permiso de asignar puede entregar conversaciones por encima de este límite, porque es una decisión de supervisión.',
                        }"
                        :error="
                            validation.error(
                                'operations.max_open_conversations_per_agent',
                            )
                        "
                    >
                        <Input
                            id="max-open"
                            v-bind="
                                validation.field(
                                    'operations.max_open_conversations_per_agent',
                                    'max-open',
                                )
                            "
                            :model-value="
                                form.operations
                                    .max_open_conversations_per_agent ?? ''
                            "
                            @update:model-value="
                                (value) =>
                                    (form.operations.max_open_conversations_per_agent =
                                        toNullableNumber(value))
                            "
                            type="number"
                            min="1"
                            max="200"
                            inputmode="numeric"
                        />
                    </FormField>
                    <FormField
                        id="auto-close"
                        label="Cierre por inactividad (horas)"
                        hint="Déjalo vacío para no cerrar automáticamente."
                        :help="{
                            description:
                                'Cierra las conversaciones sin mensajes nuevos durante estas horas. Se revisa cada hora y queda registrado en su historial.',
                            example: '48',
                            warning:
                                'Cerrar no envía nada al cliente. Si el cliente vuelve a escribir, se aplica la ventana de reapertura de la conexión.',
                        }"
                        :error="
                            validation.error(
                                'operations.auto_close_after_hours',
                            )
                        "
                    >
                        <Input
                            id="auto-close"
                            v-bind="
                                validation.field(
                                    'operations.auto_close_after_hours',
                                    'auto-close',
                                )
                            "
                            :model-value="
                                form.operations.auto_close_after_hours ?? ''
                            "
                            @update:model-value="
                                (value) =>
                                    (form.operations.auto_close_after_hours =
                                        toNullableNumber(value))
                            "
                            type="number"
                            min="1"
                            max="720"
                            inputmode="numeric"
                        />
                    </FormField>
                    <div class="grid gap-2 sm:col-span-2">
                        <div class="flex items-center gap-1.5">
                            <span
                                id="transfer-reason-label"
                                class="text-sm font-medium"
                                >Motivo obligatorio al transferir</span
                            >
                            <FieldHelp
                                title="Motivo obligatorio al transferir"
                                :help="{
                                    description:
                                        'Exige escribir por qué se transfiere una conversación a otro agente o departamento.',
                                    warning:
                                        'El motivo queda en el historial de la conversación; nunca se envía al cliente.',
                                }"
                            />
                        </div>
                        <label
                            class="flex items-center justify-between gap-3 rounded-lg border px-3 py-2 text-sm"
                        >
                            <span class="text-muted-foreground">
                                {{
                                    form.operations.transfer_reason_required
                                        ? 'Se pide el motivo en cada transferencia'
                                        : 'El motivo es opcional'
                                }}
                            </span>
                            <Switch
                                v-model="
                                    form.operations.transfer_reason_required
                                "
                                aria-labelledby="transfer-reason-label"
                            />
                        </label>
                        <InputError
                            :message="
                                validation.error(
                                    'operations.transfer_reason_required',
                                )
                            "
                        />
                    </div>
                </div>
            </section>

            <section
                class="grid gap-6 lg:grid-cols-[16rem_1fr]"
                aria-labelledby="meta-heading"
            >
                <div>
                    <h2 id="meta-heading" class="font-medium">
                        Integración con Meta
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        WhatsApp Cloud API oficial.
                    </p>
                </div>
                <div
                    class="space-y-4 rounded-xl border bg-card p-5 shadow-card"
                >
                    <div class="flex items-start gap-3">
                        <span
                            class="flex size-9 shrink-0 items-center justify-center rounded-md"
                            :class="
                                integrationConnected
                                    ? 'bg-success/10 text-success'
                                    : 'bg-muted text-muted-foreground'
                            "
                        >
                            <PlugZap
                                v-if="integrationConnected"
                                class="size-4"
                                aria-hidden="true"
                            />
                            <CircleDashed
                                v-else
                                class="size-4"
                                aria-hidden="true"
                            />
                        </span>
                        <div>
                            <p class="text-sm font-medium">
                                {{
                                    integrationConnected
                                        ? 'Con números activos'
                                        : 'Sin números activos'
                                }}
                            </p>
                            <p class="text-sm text-muted-foreground">
                                {{ integration.active_whatsapp_accounts }} de
                                {{ integration.whatsapp_accounts }} números de
                                WhatsApp activos.
                            </p>
                        </div>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        Los números, sus credenciales (guardadas cifradas) y su
                        estado real se administran en Administración ›
                        Conexiones. Cada número recibe y envía mensajes solo
                        mientras está activo y su integración con Meta está
                        verificada.
                    </p>
                </div>
            </section>
        </fieldset>

        <div
            v-if="can.update"
            class="sticky bottom-0 flex items-center justify-end gap-3 border-t bg-background/95 px-4 py-3 backdrop-blur md:px-6"
        >
            <span
                class="text-sm text-muted-foreground max-sm:sr-only"
                aria-live="polite"
                >{{
                    validation.hasChanges
                        ? 'Cambios sin guardar'
                        : 'Sin cambios por guardar'
                }}</span
            >
            <Button
                type="button"
                variant="outline"
                :disabled="!validation.hasChanges || form.processing"
                @click="form.reset()"
            >
                Descartar
            </Button>
            <Button
                type="submit"
                :disabled="form.processing || !validation.hasChanges"
            >
                <Spinner v-if="form.processing" />
                Guardar configuración
            </Button>
        </div>
    </form>
</template>
