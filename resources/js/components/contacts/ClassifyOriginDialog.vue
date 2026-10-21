<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import FormDialog from '@/components/FormDialog.vue';
import FormField from '@/components/FormField.vue';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { store } from '@/routes/contacts/attributions';
import type { ContactEnumOption, ContactOriginTypeValue } from '@/types';

const props = defineProps<{
    contactId: number;
    originTypes: ContactEnumOption<ContactOriginTypeValue>[];
    hasConfirmedOrigin: boolean;
}>();

const open = defineModel<boolean>('open', { required: true });

const AD_TYPES: ContactOriginTypeValue[] = [
    'facebook_ad',
    'instagram_ad',
    'ctwa_button',
    'physical_ad',
];

const form = useForm<{
    origin_type: ContactOriginTypeValue | '';
    ad_platform: string;
    campaign_name: string;
    page_name: string;
    source_url: string;
    notes: string;
}>({
    origin_type: '',
    ad_platform: '',
    campaign_name: '',
    page_name: '',
    source_url: '',
    notes: '',
});

const showsAdDetails = computed(
    () =>
        form.origin_type !== '' &&
        AD_TYPES.includes(form.origin_type as ContactOriginTypeValue),
);

watch(open, (isOpen) => {
    if (isOpen) {
        form.clearErrors();
        form.reset();
    }
});

function submit(): void {
    form.submit(store(props.contactId), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <FormDialog
        v-model:open="open"
        title="Registrar procedencia declarada"
        description="Acción administrativa excepcional: registra cómo dice la persona que nos conoció. Quedará como dato declarado por ti y nunca como «Confirmado por Meta»."
        :processing="form.processing"
        :dirty="form.isDirty"
        :submit-disabled="form.origin_type === ''"
        submit-label="Registrar procedencia"
        @submit="submit"
    >
        <div class="grid gap-5">
            <p
                v-if="hasConfirmedOrigin"
                class="rounded-lg border bg-muted/50 p-3 text-sm text-muted-foreground"
            >
                Este contacto ya tiene una procedencia confirmada por el
                proveedor. Tu declaración se guardará en el historial, pero la
                procedencia confirmada seguirá mostrándose como principal.
            </p>

            <FormField
                id="origin-type"
                label="Procedencia declarada"
                :error="form.errors.origin_type"
                :help="{
                    description:
                        'Cómo dice la persona que nos conoció. Es un dato declarado: se muestra como «Declarado por» con tu nombre y la fecha.',
                    howTo: 'Elige la opción más cercana solo si la persona lo indicó. Si no lo sabes, no registres nada: el contacto seguirá como «Origen no identificado».',
                    warning:
                        'Un dato declarado nunca se convierte en «Confirmado por Meta» ni reemplaza una procedencia confirmada por el proveedor.',
                }"
            >
                <Select v-model="form.origin_type">
                    <SelectTrigger id="origin-type" class="w-full">
                        <SelectValue placeholder="Selecciona una procedencia" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="option in originTypes"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </FormField>

            <div v-if="showsAdDetails" class="grid gap-5 sm:grid-cols-2">
                <FormField
                    id="origin-platform"
                    label="Plataforma (opcional)"
                    :error="form.errors.ad_platform"
                >
                    <Input
                        id="origin-platform"
                        v-model="form.ad_platform"
                        maxlength="40"
                        placeholder="Facebook, Instagram, espectacular…"
                    />
                </FormField>
                <FormField
                    id="origin-campaign"
                    label="Campaña (opcional)"
                    :error="form.errors.campaign_name"
                >
                    <Input
                        id="origin-campaign"
                        v-model="form.campaign_name"
                        maxlength="255"
                    />
                </FormField>
            </div>

            <FormField
                id="origin-page"
                label="Página (opcional)"
                :error="form.errors.page_name"
                hint="Página de Facebook, sitio o lugar donde vio el anuncio."
            >
                <Input
                    id="origin-page"
                    v-model="form.page_name"
                    maxlength="255"
                />
            </FormField>

            <FormField
                id="origin-url"
                label="URL de referencia (opcional)"
                :error="form.errors.source_url"
                :help="{
                    description:
                        'Enlace a la publicación, anuncio o página relacionada con el origen.',
                    howTo: 'Pega la dirección completa; debe comenzar con http:// o https://.',
                    example: 'https://www.facebook.com/mi-negocio/posts/123',
                }"
            >
                <Input
                    id="origin-url"
                    v-model="form.source_url"
                    type="url"
                    inputmode="url"
                    maxlength="2048"
                    placeholder="https://"
                />
            </FormField>

            <FormField
                id="origin-notes"
                label="Notas (opcional)"
                :error="form.errors.notes"
            >
                <Textarea
                    id="origin-notes"
                    v-model="form.notes"
                    rows="3"
                    maxlength="1000"
                    placeholder="Por ejemplo: dijo que lo recomendó su hermana."
                />
            </FormField>
        </div>
    </FormDialog>
</template>
