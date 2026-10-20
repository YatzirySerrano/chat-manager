<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import FormField from '@/components/FormField.vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
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

/**
 * Keep the form in sync when fresh props arrive (e.g. after saving or when
 * another administrator changed the message) unless there are local edits.
 */
watch(
    () => props.department.welcome_message,
    (message) => {
        if (!form.isDirty) {
            form.defaults({ welcome_message: message ?? '' });
            form.reset();
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
    form.submit(update(props.department.id), {
        preserveScroll: true,
        onSuccess: () => {
            form.defaults({
                welcome_message: props.department.welcome_message ?? '',
            });
            form.reset();
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
        <form v-if="canUpdate" class="space-y-4" @submit.prevent="submit">
            <FormField
                id="department-welcome-message"
                label="Mensaje"
                :error="form.errors.welcome_message"
                :help="help"
            >
                <Textarea
                    id="department-welcome-message"
                    v-model="form.welcome_message"
                    :maxlength="MAX_LENGTH"
                    rows="4"
                    placeholder="Sin mensaje de bienvenida"
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
