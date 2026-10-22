<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import FormDialog from '@/components/FormDialog.vue';
import FormField from '@/components/FormField.vue';
import InputError from '@/components/InputError.vue';
import PeoplePicker from '@/components/team-chat/PeoplePicker.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useFormValidation } from '@/composables/useFormValidation';
import { maxLength, minLength, normalizers, required } from '@/lib/validation';
import { store as storeGroup } from '@/routes/team-chat/groups';

const props = defineProps<{
    maxParticipants: number;
}>();

const open = defineModel<boolean>('open', { required: true });

const form = useForm<{
    name: string;
    description: string;
    participant_ids: number[];
}>({
    name: '',
    description: '',
    participant_ids: [],
});

const validation = useFormValidation(form, {
    rules: {
        name: [
            required('Escribe el nombre del grupo.'),
            minLength(2),
            maxLength(80),
        ],
        description: [maxLength(500)],
        participant_ids: [
            (value) =>
                Array.isArray(value) && value.length > 0
                    ? null
                    : 'Elige al menos a otra persona para el grupo.',
            (value) =>
                Array.isArray(value) && value.length > props.maxParticipants - 1
                    ? `Un grupo puede tener como máximo ${props.maxParticipants} participantes.`
                    : null,
        ],
    },
    normalize: { name: normalizers.squish },
});

watch(open, (isOpen) => {
    if (isOpen) {
        form.clearErrors();
        validation.load({ name: '', description: '', participant_ids: [] });
    }
});

const selectedCount = computed(() => form.participant_ids.length);

function submit(): void {
    form.submit(storeGroup(), {
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <FormDialog
        v-model:open="open"
        title="Nuevo grupo"
        description="Los grupos son privados: solo sus participantes ven los mensajes. Serás su administrador."
        size="lg"
        :processing="form.processing"
        :validation="validation"
        submit-label="Crear grupo"
        @submit="submit"
    >
        <div class="grid gap-5">
            <FormField
                id="team-group-name"
                label="Nombre"
                :error="validation.error('name')"
            >
                <Input
                    id="team-group-name"
                    v-model="form.name"
                    maxlength="80"
                    placeholder="Ej. Turno matutino de soporte"
                    v-bind="validation.field('name', 'team-group-name')"
                />
            </FormField>

            <FormField
                id="team-group-description"
                label="Descripción (opcional)"
                :error="validation.error('description')"
                hint="Para qué sirve el grupo; la verán sus participantes."
            >
                <Textarea
                    id="team-group-description"
                    v-model="form.description"
                    rows="2"
                    maxlength="500"
                    v-bind="
                        validation.field(
                            'description',
                            'team-group-description',
                        )
                    "
                />
            </FormField>

            <div class="grid gap-2">
                <div class="flex items-center justify-between gap-2">
                    <Label>Participantes</Label>
                    <span class="text-xs text-muted-foreground">
                        {{
                            selectedCount === 1
                                ? '1 persona elegida'
                                : `${selectedCount} personas elegidas`
                        }}
                    </span>
                </div>
                <PeoplePicker
                    v-if="open"
                    v-model:selected="form.participant_ids"
                    multiple
                    @update:selected="validation.touch('participant_ids')"
                />
                <InputError :message="validation.error('participant_ids')" />
            </div>
        </div>
    </FormDialog>
</template>
