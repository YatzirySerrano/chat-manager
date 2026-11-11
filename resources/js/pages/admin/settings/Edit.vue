<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { CircleDashed, Lock, PlugZap } from '@lucide/vue';
import { computed, ref } from 'vue';
import PlatformHolidaysCard from '@/components/automation/PlatformHolidaysCard.vue';
import FieldHelp from '@/components/FieldHelp.vue';
import FormField from '@/components/FormField.vue';
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import ServiceRulesCard from '@/components/service-rules/ServiceRulesCard.vue';
import StatusNote from '@/components/StatusNote.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { useFormValidation } from '@/composables/useFormValidation';
import { formatTimeZone } from '@/lib/format';
import { integerBetween, maxLength, required } from '@/lib/validation';
import type { FieldRules } from '@/lib/validation';
import { edit, update } from '@/routes/admin/settings';
import { flattenServiceRules, writeServiceRule } from '@/lib/serviceRules';
import type {
    PlatformSettings,
    ServiceRuleKey,
    ServiceRuleSet,
    ServiceRuleValue,
    Weekday,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Configuración', href: edit() }],
    },
});

const props = defineProps<{
    settings: PlatformSettings;
    /** Platform level of the service rules (names, units and bounds). */
    serviceRules: ServiceRuleSet;
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
            'operations.pending_block_after_minutes': [integerBetween(0, 1440)],
            'sla_alerts.reminder_minutes': [integerBetween(5, 1440)],
            'sla_alerts.reminder_limit': [
                required('Escribe el máximo de recordatorios.'),
                integerBetween(1, 10),
            ],
        };

        // Service rules: bounds come from the server definition.
        for (const key of Object.keys(props.serviceRules) as ServiceRuleKey[]) {
            const rule = props.serviceRules[key];

            if (rule.type === 'integer') {
                rules[key] = [
                    ...(rule.nullable ? [] : [required('Escribe un valor.')]),
                    integerBetween(rule.min ?? 0, rule.max ?? 0),
                ];
            }
        }

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

/** The service rules of the form as the flat map the card edits. */
const ruleValues = computed(() =>
    flattenServiceRules(props.serviceRules, form),
);

const ruleErrors = computed(() => {
    const errors: Partial<Record<ServiceRuleKey, string>> = {};

    for (const key of Object.keys(props.serviceRules) as ServiceRuleKey[]) {
        errors[key] = validation.error(key);
    }

    return errors;
});

/** Bumped when the form is discarded, to remount the rules card. */
const rulesRevision = ref(0);

function setRule(key: ServiceRuleKey, value: ServiceRuleValue): void {
    writeServiceRule(form, key, value);
}

function discard(): void {
    form.reset();
    rulesRevision.value++;
}

const slaAlertSwitches: {
    key:
        | 'agent_warning'
        | 'agent_breach'
        | 'supervisor_warning'
        | 'supervisor_breach';
    label: string;
    on: string;
}[] = [
    {
        key: 'agent_warning',
        label: 'Al agente: solicitud próxima a vencer',
        on: 'Se avisa al agente responsable antes de que venza',
    },
    {
        key: 'agent_breach',
        label: 'Al agente: solicitud vencida',
        on: 'Se avisa al agente responsable cuando vence',
    },
    {
        key: 'supervisor_warning',
        label: 'A supervisión: solicitud próxima a vencer',
        on: 'Se avisa a quienes supervisan el departamento antes de que venza',
    },
    {
        key: 'supervisor_breach',
        label: 'A supervisión: solicitud vencida',
        on: 'Se avisa a quienes supervisan el departamento cuando vence',
    },
];

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
            class="page-shell [--page-max:72rem] [--section-gap:2rem]"
        >
            <PageHeader
                help="configuracion"
                title="Configuración"
                description="Parámetros generales de la plataforma. Los cambios quedan registrados en la auditoría."
            />

            <StatusNote v-if="!can.update" tone="info" :icon="Lock">
                Puedes consultar la configuración, pero no modificarla.
            </StatusNote>

            <section
                class="grid gap-6 lg:grid-cols-[16rem_1fr]"
                aria-labelledby="general-heading"
            >
                <div data-tour="settings.general">
                    <h2 id="general-heading" class="text-base font-semibold">
                        General
                    </h2>
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
                                {{ formatTimeZone(zone) }}
                            </option>
                        </select>
                    </FormField>
                </div>
            </section>

            <section
                class="grid gap-6 lg:grid-cols-[16rem_1fr]"
                aria-labelledby="hours-heading"
            >
                <div data-tour="settings.hours">
                    <h2 id="hours-heading" class="text-base font-semibold">
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
                    <h2 id="holidays-heading" class="text-base font-semibold">
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
                <div data-tour="settings.operations">
                    <h2 id="operations-heading" class="text-base font-semibold">
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
                                'Departamento que recibe las conversaciones nuevas cuando su conexión no tiene uno propio.',
                            howTo: 'Elige un departamento activo autorizado para las conexiones. Si no eliges ninguno, las conversaciones quedan «Sin departamento» y solo las verán quienes ven toda la organización.',
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
                    <div
                        class="grid gap-2 sm:col-span-2"
                        data-settings-pending-block
                    >
                        <div class="flex items-center gap-1.5">
                            <span
                                id="pending-block-label"
                                class="text-sm font-medium"
                                >Control de conversaciones pendientes</span
                            >
                            <FieldHelp
                                title="Control de conversaciones pendientes"
                                :help="{
                                    description:
                                        'Un agente con clientes esperando su respuesta más tiempo que la tolerancia no puede aceptar conversaciones nuevas ni recibirlas automáticamente hasta responderles.',
                                    example:
                                        'Con 10 minutos: si un cliente escribió hace 12 minutos y el agente no le ha respondido, «Aceptar» se desactiva para ese agente.',
                                    warning:
                                        'Solo cuentan los clientes que escribieron después de la última respuesta del agente; si el agente ya respondió y espera al cliente, no bloquea. Nunca limita el chat interno ni las asignaciones que hace un administrador. Los roles con el permiso «Aceptar conversaciones nuevas aunque tenga pendientes» quedan fuera del control.',
                                }"
                            />
                        </div>
                        <label
                            class="flex items-center justify-between gap-3 rounded-lg border px-3 py-2 text-sm"
                        >
                            <span class="text-muted-foreground">
                                {{
                                    form.operations.pending_block_enabled
                                        ? 'Activado: no se aceptan nuevas con clientes esperando'
                                        : 'Desactivado: se pueden aceptar nuevas siempre'
                                }}
                            </span>
                            <Switch
                                v-model="form.operations.pending_block_enabled"
                                aria-labelledby="pending-block-label"
                            />
                        </label>
                        <FormField
                            v-if="form.operations.pending_block_enabled"
                            id="pending-block-minutes"
                            label="Tolerancia antes de bloquear (minutos)"
                            hint="Tiempo que un cliente puede esperar la respuesta antes de que el agente deje de poder aceptar nuevas. 0 bloquea en cuanto el cliente escribe."
                            :error="
                                validation.error(
                                    'operations.pending_block_after_minutes',
                                )
                            "
                        >
                            <Input
                                id="pending-block-minutes"
                                v-model.number="
                                    form.operations.pending_block_after_minutes
                                "
                                type="number"
                                min="0"
                                max="1440"
                                inputmode="numeric"
                                class="sm:max-w-40"
                            />
                        </FormField>
                    </div>
                </div>
            </section>

            <section
                class="grid gap-6 lg:grid-cols-[16rem_1fr]"
                aria-labelledby="sla-heading"
            >
                <div data-tour="settings.sla">
                    <h2 id="sla-heading" class="text-base font-semibold">
                        Objetivos de servicio
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        Reglas generales de atención, en tres grupos que no
                        deben confundirse: el tiempo máximo de respuesta, la
                        confirmación de solicitud completa y la espera mínima
                        con el orden de atención.
                    </p>
                    <p class="mt-2 text-sm text-muted-foreground">
                        Son los valores generales: cada departamento y cada
                        categoría de solicitud puede tener valores propios, que
                        tienen prioridad (categoría, después departamento,
                        después estos). Con un objetivo, Reportes muestra además
                        el porcentaje de cumplimiento.
                    </p>
                </div>
                <div
                    class="rounded-xl border bg-card p-4 shadow-card sm:p-5"
                    data-settings-service-rules
                >
                    <ServiceRulesCard
                        :key="rulesRevision"
                        level="platform"
                        :rules="serviceRules"
                        :values="ruleValues"
                        :errors="ruleErrors"
                        :readonly="false"
                        id-prefix="platform-rule"
                        @update="setRule"
                    />
                </div>
            </section>

            <section
                class="grid gap-6 lg:grid-cols-[16rem_1fr]"
                aria-labelledby="sla-alerts-heading"
            >
                <div data-tour="settings.sla-alerts">
                    <h2 id="sla-alerts-heading" class="text-base font-semibold">
                        Alertas automáticas de SLA
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        Avisos que la plataforma envía sola cuando una solicitud
                        se acerca a su tiempo máximo de respuesta o lo supera.
                    </p>
                    <p class="mt-2 text-sm text-muted-foreground">
                        No son las alertas que un administrador envía a mano
                        desde «Seguimiento de atención»: aquellas las escribe
                        una persona; estas dependen solo de los plazos.
                    </p>
                </div>
                <div
                    class="grid gap-5 rounded-xl border bg-card p-5 shadow-card"
                    data-settings-sla-alerts
                >
                    <fieldset class="grid gap-2">
                        <legend
                            class="mb-2 flex items-center gap-1.5 text-sm font-medium"
                        >
                            A quién se avisa y cuándo
                            <FieldHelp
                                title="A quién se avisa y cuándo"
                                :help="{
                                    description:
                                        '«Próxima a vencer» se avisa al consumirse el porcentaje del plazo definido arriba; «vencida», al superarse el plazo. El agente es quien tiene asignada la solicitud; supervisión son las personas que supervisan su departamento.',
                                    example:
                                        'Con plazo de 15 minutos y aviso al 80 %: aviso de próxima a vencer a los 12 minutos y de vencida a los 15.',
                                    warning:
                                        'Sin un tiempo máximo configurado no hay plazo y, por lo tanto, no se envía ninguna alerta. Cada persona puede silenciar sus notificaciones desde sus preferencias.',
                                }"
                            />
                        </legend>
                        <label
                            v-for="alert in slaAlertSwitches"
                            :key="alert.key"
                            class="flex items-center justify-between gap-3 rounded-lg border px-3 py-2 text-sm"
                        >
                            <span>
                                <span class="block font-medium">{{
                                    alert.label
                                }}</span>
                                <span class="block text-muted-foreground">{{
                                    form.sla_alerts[alert.key]
                                        ? alert.on
                                        : 'No se avisa'
                                }}</span>
                            </span>
                            <Switch
                                v-model="form.sla_alerts[alert.key]"
                                :aria-label="alert.label"
                            />
                        </label>
                    </fieldset>
                    <div class="grid gap-5 sm:grid-cols-2">
                        <FormField
                            id="sla-reminder-minutes"
                            label="Recordar las solicitudes vencidas cada (minutos)"
                            hint="De 5 a 1440. Déjalo vacío para no enviar recordatorios."
                            :help="{
                                description:
                                    'Mientras una solicitud vencida siga sin respuesta, se vuelve a avisar a las mismas personas con esta frecuencia.',
                                example:
                                    '30: una solicitud que venció a las 10:00 se recuerda a las 10:30, 11:00…',
                                warning:
                                    'Los recordatorios se detienen cuando el equipo responde, cuando la solicitud se cierra o al llegar al máximo de recordatorios.',
                            }"
                            :error="
                                validation.error('sla_alerts.reminder_minutes')
                            "
                        >
                            <Input
                                id="sla-reminder-minutes"
                                v-bind="
                                    validation.field(
                                        'sla_alerts.reminder_minutes',
                                        'sla-reminder-minutes',
                                    )
                                "
                                :model-value="
                                    form.sla_alerts.reminder_minutes ?? ''
                                "
                                @update:model-value="
                                    (value) =>
                                        (form.sla_alerts.reminder_minutes =
                                            toNullableNumber(value))
                                "
                                type="number"
                                min="5"
                                max="1440"
                                inputmode="numeric"
                            />
                        </FormField>
                        <FormField
                            v-if="form.sla_alerts.reminder_minutes !== null"
                            id="sla-reminder-limit"
                            label="Máximo de recordatorios por solicitud"
                            hint="De 1 a 10."
                            :help="{
                                description:
                                    'Cuántas veces, como máximo, se recuerda una misma solicitud vencida. Evita avisos sin fin cuando nadie puede atenderla.',
                                example: '3',
                            }"
                            :error="
                                validation.error('sla_alerts.reminder_limit')
                            "
                        >
                            <Input
                                id="sla-reminder-limit"
                                v-bind="
                                    validation.field(
                                        'sla_alerts.reminder_limit',
                                        'sla-reminder-limit',
                                    )
                                "
                                v-model.number="form.sla_alerts.reminder_limit"
                                type="number"
                                min="1"
                                max="10"
                                inputmode="numeric"
                            />
                        </FormField>
                    </div>
                </div>
            </section>

            <section
                class="grid gap-6 lg:grid-cols-[16rem_1fr]"
                aria-labelledby="meta-heading"
            >
                <div>
                    <h2 id="meta-heading" class="text-base font-semibold">
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
                                    ? 'bg-success-soft text-success-soft-foreground'
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
            data-dock-bottom
            data-tour="settings.save"
            class="sticky bottom-0 flex items-center justify-end gap-3 border-t bg-background/95 px-4 py-3 backdrop-blur md:px-6"
        >
            <span
                class="text-sm text-muted-foreground max-sm:sr-only"
                :class="validation.hasChanges && 'font-medium text-warning'"
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
                @click="discard()"
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
