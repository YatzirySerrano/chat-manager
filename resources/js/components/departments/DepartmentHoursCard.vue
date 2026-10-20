<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import FieldHelp from '@/components/FieldHelp.vue';
import InputError from '@/components/InputError.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { update } from '@/routes/departments/hours';
import type {
    BusinessDay,
    DepartmentSummary,
    PlatformSettings,
    Weekday,
} from '@/types';

const props = defineProps<{
    department: DepartmentSummary;
    platformHours: PlatformSettings['business_hours'];
    weekdays: Weekday[];
    canUpdate: boolean;
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

type HoursForm = {
    uses_custom_hours: boolean;
    business_hours: { days: Record<Weekday, BusinessDay> };
};

/**
 * The custom editor starts from the stored custom schedule or, the first
 * time, from a copy of the platform hours. JSON copy because props are
 * reactive proxies that structuredClone cannot copy.
 */
function initialState(): HoursForm {
    const days =
        props.department.business_hours?.days ?? props.platformHours.days;

    return {
        uses_custom_hours: props.department.uses_custom_hours,
        business_hours: { days: JSON.parse(JSON.stringify(days)) },
    };
}

const form = useForm<HoursForm>(initialState());

watch(
    () => [props.department.uses_custom_hours, props.department.business_hours],
    () => {
        if (!form.isDirty) {
            form.defaults(initialState());
            form.reset();
        }
    },
    { deep: true },
);

const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

function dayError(day: Weekday): string | undefined {
    return (
        errors.value[`business_hours.days.${day}.end`] ??
        errors.value[`business_hours.days.${day}.start`] ??
        errors.value[`business_hours.days.${day}.open`]
    );
}

function summary(day: BusinessDay): string {
    return day.open ? `${day.start} a ${day.end}` : 'Cerrado';
}

const helpContent = {
    description:
        'Define en qué horario atiende este departamento. Se usará para identificar mensajes recibidos fuera de horario cuando se habilite la mensajería.',
    howTo: 'Elige "Horario de la plataforma" para seguir el horario general, o "Horario propio" para definir apertura y cierre por día.',
    warning:
        'Volver al horario de la plataforma no borra el horario propio guardado; solo deja de aplicarse.',
};

function submit(): void {
    form.submit(update(props.department.id), {
        preserveScroll: true,
        onSuccess: () => {
            form.defaults(initialState());
            form.reset();
        },
    });
}
</script>

<template>
    <SurfaceCard body-class="divide-y">
        <div class="flex items-center gap-1.5 px-5 py-4">
            <h2 class="text-sm font-semibold">Horario de operación</h2>
            <FieldHelp title="Horario de operación" :help="helpContent" />
        </div>

        <form v-if="canUpdate" class="flex flex-col" @submit.prevent="submit">
            <fieldset class="grid gap-2 p-5 sm:grid-cols-2">
                <legend class="sr-only">Tipo de horario</legend>
                <label
                    class="flex cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm has-[:checked]:border-primary has-[:checked]:bg-primary-soft/40"
                >
                    <input
                        v-model="form.uses_custom_hours"
                        type="radio"
                        name="hours-mode"
                        :value="false"
                        class="mt-0.5 accent-primary"
                    />
                    <span>
                        <span class="block font-medium"
                            >Horario de la plataforma</span
                        >
                        <span class="block text-muted-foreground"
                            >Sigue el horario general de la configuración.</span
                        >
                    </span>
                </label>
                <label
                    class="flex cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm has-[:checked]:border-primary has-[:checked]:bg-primary-soft/40"
                >
                    <input
                        v-model="form.uses_custom_hours"
                        type="radio"
                        name="hours-mode"
                        :value="true"
                        class="mt-0.5 accent-primary"
                    />
                    <span>
                        <span class="block font-medium">Horario propio</span>
                        <span class="block text-muted-foreground"
                            >Apertura y cierre definidos por día.</span
                        >
                    </span>
                </label>
                <InputError
                    class="sm:col-span-2"
                    :message="errors.uses_custom_hours"
                />
            </fieldset>

            <ul v-if="form.uses_custom_hours" class="divide-y border-t">
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
                        :aria-label="`Abierto el ${weekdayLabels[day].toLowerCase()}`"
                    />
                    <div
                        class="col-span-2 flex flex-wrap items-center gap-2 sm:col-span-1 sm:justify-end"
                    >
                        <template v-if="form.business_hours.days[day].open">
                            <Input
                                v-model="form.business_hours.days[day].start"
                                type="time"
                                class="w-32"
                                :aria-label="`Apertura ${weekdayLabels[day]}`"
                            />
                            <span class="text-sm text-muted-foreground">a</span>
                            <Input
                                v-model="form.business_hours.days[day].end"
                                type="time"
                                class="w-32"
                                :aria-label="`Cierre ${weekdayLabels[day]}`"
                            />
                        </template>
                        <span v-else class="text-sm text-muted-foreground"
                            >Cerrado</span
                        >
                        <InputError
                            class="w-full sm:text-right"
                            :message="dayError(day)"
                        />
                    </div>
                </li>
            </ul>

            <div v-else class="border-t px-5 py-4">
                <p
                    v-if="!platformHours.enabled"
                    class="text-sm text-muted-foreground"
                >
                    La plataforma no aplica horario de atención: se considera
                    atención continua.
                </p>
                <dl v-else class="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                    <div
                        v-for="day in weekdays"
                        :key="day"
                        class="flex justify-between gap-3"
                    >
                        <dt class="text-muted-foreground">
                            {{ weekdayLabels[day] }}
                        </dt>
                        <dd class="tabular-nums">
                            {{ summary(platformHours.days[day]) }}
                        </dd>
                    </div>
                </dl>
            </div>

            <div class="flex justify-end gap-2 border-t px-5 py-3">
                <Button
                    type="button"
                    variant="outline"
                    :disabled="!form.isDirty || form.processing"
                    @click="form.reset()"
                >
                    Descartar
                </Button>
                <Button
                    type="submit"
                    :disabled="!form.isDirty || form.processing"
                >
                    <Spinner v-if="form.processing" />
                    Guardar horario
                </Button>
            </div>
        </form>

        <div v-else class="space-y-3 px-5 py-4">
            <p class="text-sm font-medium">
                {{
                    department.uses_custom_hours
                        ? 'Horario propio'
                        : 'Horario de la plataforma'
                }}
            </p>
            <p
                v-if="!department.uses_custom_hours && !platformHours.enabled"
                class="text-sm text-muted-foreground"
            >
                La plataforma no aplica horario de atención: se considera
                atención continua.
            </p>
            <dl v-else class="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                <div
                    v-for="day in weekdays"
                    :key="day"
                    class="flex justify-between gap-3"
                >
                    <dt class="text-muted-foreground">
                        {{ weekdayLabels[day] }}
                    </dt>
                    <dd class="tabular-nums">
                        {{
                            summary(
                                department.uses_custom_hours &&
                                    department.business_hours
                                    ? department.business_hours.days[day]
                                    : platformHours.days[day],
                            )
                        }}
                    </dd>
                </div>
            </dl>
        </div>
    </SurfaceCard>
</template>
