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

const form = useForm({
    welcome_message: props.department.welcome_message ?? '',
});

const validation = useFormValidation(form, {
    rules: { welcome_message: [maxLength(MAX_LENGTH)] },
    requireChanges: true,
});

/**
 * Keep the form in sync when fresh props arrive (e.g. after saving or when
 * another administrator changed the message) unless there are local edits.
 */
watch(
    () => props.department.welcome_message,
    (message) => {
        if (!validation.hasChanges) {
            validation.load({ welcome_message: message ?? '' });
        }
    },
);

const length = computed(() => form.welcome_message.length);

const help: FieldHelpContent = {
    description:
        'Es la configuración del saludo que se enviará cuando una conversación sea dirigida a este departamento.',
    howTo: 'Escribe un texto breve que confirme al cliente con qué área está hablando. Déjalo vacío para no enviar saludo del departamento.',
    example:
        'Hola, te atiende el equipo de Soporte. En un momento revisamos tu caso.',
    warning:
        'Por ahora solo se guarda: el envío se habilitará con la integración de mensajería de Meta y únicamente dentro de la ventana de 24 horas de atención al cliente.',
};

function submit(): void {
    if (!validation.validate()) {
        return;
    }

    form.submit(update(props.department.id), {
        preserveScroll: true,
        onSuccess: () => {
            validation.load({
                welcome_message: props.department.welcome_message ?? '',
            });
        },
    });
}
</script>

<template>
    <SurfaceCard
        title="Mensaje de bienvenida del departamento"
        description="Saludo para las conversaciones que llegan a este departamento."
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
                label="Mensaje"
                :error="validation.error('welcome_message')"
                :help="help"
            >
                <Textarea
                    id="department-welcome-message"
                    v-model="form.welcome_message"
                    :maxlength="MAX_LENGTH"
                    rows="4"
                    placeholder="Sin mensaje de bienvenida"
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
                    {{ length }} / {{ MAX_LENGTH }}
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
                    Guardar mensaje
                </Button>
            </div>
        </form>
        <template v-else>
            <p
                v-if="department.welcome_message"
                class="text-sm whitespace-pre-line"
            >
                {{ department.welcome_message }}
            </p>
            <p v-else class="text-sm text-muted-foreground">
                Sin mensaje de bienvenida configurado.
            </p>
        </template>
    </SurfaceCard>
</template>
