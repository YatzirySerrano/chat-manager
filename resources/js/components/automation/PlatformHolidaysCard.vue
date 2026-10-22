<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { CalendarPlus, Trash2 } from '@lucide/vue';
import { computed, watch } from 'vue';
import FieldHelp from '@/components/FieldHelp.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { formatHoliday } from '@/lib/holidays';
import { update } from '@/routes/admin/settings/holidays';
import type { FieldHelpContent, Holiday } from '@/types';

/**
 * Editor of the platform's non-working days. It lives inside the settings
 * page form, so it does not use a <form> element of its own: it saves with
 * its own button and request (Enter inside it saves this list only).
 */
const props = defineProps<{
    holidays: Holiday[];
    canUpdate: boolean;
}>();

const MAX = 100;

function copy(): { holidays: Holiday[] } {
    return { holidays: props.holidays.map((holiday) => ({ ...holiday })) };
}

const form = useForm<{ holidays: Holiday[] }>(copy());

watch(
    () => props.holidays,
    () => {
        if (!form.isDirty) {
            form.defaults(copy());
            form.reset();
        }
    },
    { deep: true },
);

const errors = computed(
    () => form.errors as Record<string, string | undefined>,
);

const duplicates = computed(() => {
    const seen = new Map<string, number>();
    const repeated = new Set<number>();

    form.holidays.forEach((holiday, index) => {
        if (holiday.date === '') {
            return;
        }

        if (seen.has(holiday.date)) {
            repeated.add(index);
        } else {
            seen.set(holiday.date, index);
        }
    });

    return repeated;
});

function rowError(index: number): string | undefined {
    if (duplicates.value.has(index)) {
        return 'Esta fecha ya está en la lista.';
    }

    return (
        errors.value[`holidays.${index}.date`] ??
        errors.value[`holidays.${index}.label`] ??
        errors.value[`holidays.${index}.recurring`]
    );
}

const invalid = computed(
    () =>
        duplicates.value.size > 0 ||
        form.holidays.some(
            (holiday) => holiday.date === '' || holiday.label.trim() === '',
        ),
);

function add(): void {
    form.holidays.push({ date: '', label: '', recurring: false });
}

function remove(index: number): void {
    form.holidays.splice(index, 1);
}

function submit(): void {
    if (invalid.value || !form.isDirty) {
        return;
    }

    form.put(update.url(), {
        preserveScroll: true,
        onSuccess: () => {
            form.defaults(copy());
            form.reset();
        },
    });
}

const help: FieldHelpContent = {
    description:
        'En estos días todos los horarios (de la plataforma y propios de cada departamento) se consideran cerrados todo el día.',
    howTo: 'Agrega la fecha y un nombre. Marca "Cada año" para fechas fijas como el 25 de diciembre.',
    warning:
        'Si la plataforma está en atención continua (sin horario aplicado), los días no laborables no se usan.',
};
</script>

<template>
    <div
        class="overflow-hidden rounded-xl border bg-card shadow-card"
        role="group"
        aria-labelledby="holidays-title"
        @keydown.enter.prevent="submit"
    >
        <div class="flex items-center justify-between gap-3 border-b p-5">
            <div class="flex items-center gap-1.5">
                <span id="holidays-title" class="text-sm font-medium"
                    >Días no laborables</span
                >
                <FieldHelp title="Días no laborables" :help="help" />
            </div>
            <Button
                v-if="canUpdate"
                type="button"
                variant="outline"
                size="sm"
                :disabled="form.holidays.length >= MAX"
                @click="add"
            >
                <CalendarPlus />
                Agregar día
            </Button>
        </div>

        <p
            v-if="form.holidays.length === 0"
            class="p-5 text-sm text-muted-foreground"
        >
            No hay días no laborables configurados.
        </p>

        <ul v-else class="divide-y">
            <li
                v-for="(holiday, index) in form.holidays"
                :key="index"
                class="grid gap-2 px-5 py-3 sm:grid-cols-[10rem_1fr_auto_auto] sm:items-center"
            >
                <template v-if="canUpdate">
                    <Input
                        v-model="holiday.date"
                        type="date"
                        :aria-label="`Fecha del día no laborable ${index + 1}`"
                        :aria-invalid="rowError(index) ? true : undefined"
                    />
                    <Input
                        v-model="holiday.label"
                        maxlength="80"
                        placeholder="Nombre, p. ej. Día de la Independencia"
                        :aria-label="`Nombre del día no laborable ${index + 1}`"
                    />
                    <label class="flex items-center gap-2 text-sm">
                        <Switch v-model="holiday.recurring" />
                        Cada año
                    </label>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        :aria-label="`Quitar ${holiday.label || 'día'}`"
                        @click="remove(index)"
                    >
                        <Trash2 />
                    </Button>
                </template>
                <template v-else>
                    <span class="text-sm font-medium">{{
                        formatHoliday(holiday)
                    }}</span>
                    <span class="text-sm text-muted-foreground sm:col-span-3">{{
                        holiday.label
                    }}</span>
                </template>
                <InputError class="sm:col-span-4" :message="rowError(index)" />
            </li>
        </ul>

        <div
            v-if="canUpdate"
            class="flex items-center justify-end gap-2 border-t px-5 py-3"
        >
            <InputError :message="errors.holidays" class="mr-auto" />
            <Button
                type="button"
                variant="outline"
                :disabled="!form.isDirty || form.processing"
                @click="form.reset()"
            >
                Descartar
            </Button>
            <Button
                type="button"
                :disabled="!form.isDirty || invalid || form.processing"
                @click="submit"
            >
                <Spinner v-if="form.processing" />
                Guardar días
            </Button>
        </div>
    </div>
</template>
