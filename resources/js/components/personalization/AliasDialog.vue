<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import FormField from '@/components/FormField.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import {
    destroy as destroyAlias,
    update as updateAlias,
} from '@/routes/conversations/alias';
import type { ContactAliasResponse } from '@/types';

/**
 * Edits the viewer's private alias of the contact of a conversation. It
 * only changes how the contact is shown to the viewer: the official name,
 * phone and recipient stay the same and no colleague ever sees it.
 * Standalone, so the thread header, the details panel and the inbox row
 * menu can all mount it: pass the conversation and listen to `saved`.
 */
const props = defineProps<{
    conversationId: number;
    /** Official name of the contact (shown for reference). */
    contactName: string;
    alias: string | null;
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{ saved: [response: ContactAliasResponse] }>();

const MAX_LENGTH = 80;

const http = useHttp<{ alias: string | null }, ContactAliasResponse>({
    alias: null,
});
const removeHttp = useHttp<Record<string, never>, ContactAliasResponse>({});
const draft = ref('');
const error = ref<string | null>(null);

const processing = computed(() => http.processing || removeHttp.processing);
const trimmed = computed(() => draft.value.trim());
const unchanged = computed(() => trimmed.value === (props.alias ?? ''));

watch(
    open,
    (isOpen) => {
        if (isOpen) {
            draft.value = props.alias ?? '';
            error.value = null;
        }
    },
    { immediate: true },
);

async function save(): Promise<void> {
    if (processing.value) {
        return;
    }

    if (trimmed.value.length > MAX_LENGTH) {
        error.value = `El alias puede tener hasta ${MAX_LENGTH} caracteres.`;

        return;
    }

    error.value = null;
    http.alias = trimmed.value === '' ? null : trimmed.value;

    try {
        const response = await http.put(updateAlias.url(props.conversationId), {
            onHttpException: () => false,
        });

        // A validation refusal resolves without a response.
        if (!response) {
            error.value = http.errors.alias ?? 'No se pudo guardar el alias.';

            return;
        }

        toast.success(
            response.alias === null ? 'Alias quitado.' : 'Alias guardado.',
        );
        emit('saved', response);
        open.value = false;
    } catch {
        error.value = http.errors.alias ?? 'No se pudo guardar el alias.';
    }
}

async function remove(): Promise<void> {
    if (processing.value) {
        return;
    }

    try {
        const response = await removeHttp.delete(
            destroyAlias.url(props.conversationId),
            { onHttpException: () => false },
        );

        if (!response) {
            error.value = 'No se pudo quitar el alias.';

            return;
        }

        toast.success('Alias quitado.');
        emit('saved', response);
        open.value = false;
    } catch {
        error.value = 'No se pudo quitar el alias.';
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-md">
            <form class="grid gap-4" novalidate @submit.prevent="save">
                <DialogHeader>
                    <DialogTitle>Alias personal</DialogTitle>
                    <DialogDescription>
                        Solo tú verás este nombre. No cambia el nombre oficial
                        del contacto, su teléfono ni a quién se envían los
                        mensajes.
                    </DialogDescription>
                </DialogHeader>
                <p class="text-sm">
                    <span class="text-muted-foreground">Nombre oficial:</span>
                    {{ contactName }}
                </p>
                <FormField
                    id="contact-alias"
                    label="Alias"
                    :error="error ?? undefined"
                    :hint="`Hasta ${MAX_LENGTH} caracteres. Déjalo vacío para quitarlo.`"
                >
                    <Input
                        id="contact-alias"
                        v-model="draft"
                        :maxlength="MAX_LENGTH"
                        autocomplete="off"
                        placeholder="Por ejemplo: Proveedor de cajas"
                        :aria-invalid="error ? true : undefined"
                        :aria-describedby="
                            error ? 'contact-alias-error' : undefined
                        "
                    />
                </FormField>
                <DialogFooter class="gap-2 pointer-coarse:*:h-10">
                    <Button
                        v-if="alias"
                        type="button"
                        variant="ghost"
                        class="sm:mr-auto"
                        :disabled="processing"
                        @click="remove"
                    >
                        Quitar alias
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="processing"
                        @click="open = false"
                    >
                        Cancelar
                    </Button>
                    <Button type="submit" :disabled="processing || unchanged">
                        <Spinner v-if="processing" />
                        Guardar alias
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
