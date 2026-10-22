<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import FormField from '@/components/FormField.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useFormValidation } from '@/composables/useFormValidation';
import { maxLength } from '@/lib/validation';
import { update } from '@/routes/departments/operations';
import type { DepartmentSummary, FieldHelpContent } from '@/types';

const MAX_LENGTH = 1024;

const props = defineProps<{
    department: DepartmentSummary;
    canUpdate: boolean;
}>();

function stored(): { welcome_message: string; away_message: string } {
    return {
        welcome_message: props.department.welcome_message ?? '',
        away_message: props.department.away_message ?? '',
    };
}

const form = useForm(stored());

const validation = useFormValidation(form, {
    rules: {
        welcome_message: [maxLength(MAX_LENGTH)],
        away_message: [maxLength(MAX_LENGTH)],
    },
    requireChanges: true,
});

/**
 * Keep the form in sync when fresh props arrive (e.g. after saving or when
 * another administrator changed the messages) unless there are local edits.
 */
watch(
    () => [props.department.welcome_message, props.department.away_message],
    () => {
        if (!validation.hasChanges) {
            validation.load(stored());
        }
    },
);

const welcomeLength = computed(() => form.welcome_message.length);
const awayLength = computed(() => form.away_message.length);

const rules =
    'Solo se envía como texto dentro de la ventana de 24 horas de atención al cliente de WhatsApp (nunca como plantilla), por una conexión activa, y como máximo una vez por ciclo de la conversación aunque Meta repita la notificación del mensaje.';

const welcomeHelp: FieldHelpContent = {
    description:
        'Se envía automáticamente cuando un cliente inicia una conversación (o la reabre escribiendo) dentro del horario de atención y la conversación queda en este departamento.',
    howTo: 'Escribe un texto breve que confirme con qué área habla el cliente. Si lo dejas vacío se usa el mensaje de bienvenida de la conexión, si está activado.',
    example:
        'Hola, te atiende el equipo de Soporte. En un momento revisamos tu caso.',
    warning: rules,
};

const awayHelp: FieldHelpContent = {
    description:
        'Se envía en lugar de la bienvenida cuando el cliente escribe fuera del horario de atención del departamento o en un día no laborable.',
    howTo: 'Indica cuándo se le responderá. Si lo dejas vacío se usa el mensaje fuera de horario de la conexión, si está activado; si ninguno existe no se envía nada.',
    example:
        'Gracias por escribirnos. Nuestro horario es de lunes a viernes de 9:00 a 18:00; te responderemos en cuanto abramos.',
    warning: rules,
};

function submit(): void {
    if (!validation.validate()) {
        return;
    }

    form.submit(update(props.department.id), {
        preserveScroll: true,
        onSuccess: () => {
            validation.load(stored());
        },
    });
}
</script>

<template>
    <SurfaceCard
        title="Mensajes automáticos"
        description="Bienvenida y respuesta fuera de horario para las conversaciones de este departamento. Tienen prioridad sobre los de la conexión."
        body-class="p-5"
    >
        <form
            v-if="canUpdate"
            class="space-y-4"
            novalidate
            @submit.prevent="submit"
        >
            <FormField
                id="department-welcome-message"
                label="Bienvenida (dentro del horario)"
                :error="validation.error('welcome_message')"
                :help="welcomeHelp"
            >
                <Textarea
                    id="department-welcome-message"
                    v-model="form.welcome_message"
                    :maxlength="MAX_LENGTH"
                    rows="3"
                    placeholder="Sin bienvenida propia: se usa la de la conexión"
                    v-bind="
                        validation.field(
                            'welcome_message',
                            'department-welcome-message',
                        )
                    "
                />
                <p
                    class="text-right text-xs text-muted-foreground tabular-nums"
                    aria-live="polite"
                >
                    {{ welcomeLength }} / {{ MAX_LENGTH }}
                </p>
            </FormField>
            <FormField
                id="department-away-message"
                label="Fuera de horario"
                :error="validation.error('away_message')"
                :help="awayHelp"
            >
                <Textarea
                    id="department-away-message"
                    v-model="form.away_message"
                    :maxlength="MAX_LENGTH"
                    rows="3"
                    placeholder="Sin mensaje propio: se usa el de la conexión"
                    v-bind="
                        validation.field(
                            'away_message',
                            'department-away-message',
                        )
                    "
                />
                <p
                    class="text-right text-xs text-muted-foreground tabular-nums"
                    aria-live="polite"
                >
                    {{ awayLength }} / {{ MAX_LENGTH }}
                </p>
            </FormField>
            <div class="flex justify-end gap-2">
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
                    :disabled="!validation.hasChanges || form.processing"
                >
                    <Spinner v-if="form.processing" />
                    Guardar mensajes
                </Button>
            </div>
        </form>
        <dl v-else class="space-y-4 text-sm">
            <div>
                <dt class="font-medium">Bienvenida</dt>
                <dd
                    v-if="department.welcome_message"
                    class="whitespace-pre-line"
                >
                    {{ department.welcome_message }}
                </dd>
                <dd v-else class="text-muted-foreground">
                    Sin bienvenida propia; se usa la de la conexión.
                </dd>
            </div>
            <div>
                <dt class="font-medium">Fuera de horario</dt>
                <dd v-if="department.away_message" class="whitespace-pre-line">
                    {{ department.away_message }}
                </dd>
                <dd v-else class="text-muted-foreground">
                    Sin mensaje propio; se usa el de la conexión.
                </dd>
            </div>
        </dl>
    </SurfaceCard>
</template>
