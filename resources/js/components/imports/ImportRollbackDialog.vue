<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, RotateCcw } from '@lucide/vue';
import { ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { show as contactShow } from '@/routes/contacts';
import { rollback as rollbackImport } from '@/routes/contacts/import';
import type { ImportRollbackPreview } from '@/types';

/**
 * Logical rollback of an import batch: loads the preview of its effects
 * (nothing is written), then asks for an explicit confirmation.
 */
const props = defineProps<{
    transferId: number;
    preview: ImportRollbackPreview | null | undefined;
}>();

const open = defineModel<boolean>('open', { required: true });

const loading = ref(false);
// "rollback" only carries the server error when the batch can no longer be reverted.
const form = useForm<{ confirmation: boolean; rollback: null }>({
    confirmation: false,
    rollback: null,
});

watch(open, (isOpen) => {
    if (!isOpen) {
        form.reset();
        form.clearErrors();

        return;
    }

    loadPreview();
});

function loadPreview(): void {
    loading.value = true;
    router.reload({
        only: ['rollbackPreview'],
        onFinish: () => (loading.value = false),
    });
}

function submit(): void {
    form.transform((data) => ({ confirmation: data.confirmation })).post(
        rollbackImport.url(props.transferId),
        {
            preserveScroll: true,
            onSuccess: () => (open.value = false),
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>Revertir esta importación</DialogTitle>
                <DialogDescription>
                    Se eliminan lógicamente los contactos que esta importación
                    creó y no se han usado, y se vacían los campos que completó
                    si nadie los cambió después. Nada se borra físicamente.
                </DialogDescription>
            </DialogHeader>

            <div v-if="loading" class="grid gap-2">
                <Skeleton class="h-16 w-full" />
                <Skeleton class="h-24 w-full" />
            </div>

            <Alert v-else-if="preview === undefined" variant="destructive">
                <AlertTriangle />
                <AlertTitle>No se pudo cargar la vista previa</AlertTitle>
                <AlertDescription class="flex flex-col items-start gap-2">
                    Revisa tu conexión e inténtalo de nuevo. No se revirtió
                    nada.
                    <Button variant="outline" size="sm" @click="loadPreview">
                        Reintentar
                    </Button>
                </AlertDescription>
            </Alert>

            <Alert v-else-if="preview === null" variant="destructive">
                <AlertTriangle />
                <AlertTitle>No se puede revertir</AlertTitle>
                <AlertDescription>
                    Esta importación ya no puede revertirse. Recarga la página
                    para ver el motivo.
                </AlertDescription>
            </Alert>

            <div v-else class="flex flex-col gap-4 text-sm">
                <dl class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    <div class="rounded-lg border p-3">
                        <dt class="text-xs text-muted-foreground">
                            Contactos a eliminar
                        </dt>
                        <dd class="text-lg font-semibold tabular-nums">
                            {{ preview.summary.contacts_archived }}
                        </dd>
                    </div>
                    <div class="rounded-lg border p-3">
                        <dt class="text-xs text-muted-foreground">
                            Contactos que se conservan
                        </dt>
                        <dd class="text-lg font-semibold tabular-nums">
                            {{ preview.summary.contacts_kept }}
                        </dd>
                    </div>
                    <div class="rounded-lg border p-3">
                        <dt class="text-xs text-muted-foreground">
                            Campos a vaciar
                        </dt>
                        <dd class="text-lg font-semibold tabular-nums">
                            {{ preview.summary.fields_restored }}
                        </dd>
                    </div>
                    <div class="rounded-lg border p-3">
                        <dt class="text-xs text-muted-foreground">
                            Campos que se conservan
                        </dt>
                        <dd class="text-lg font-semibold tabular-nums">
                            {{ preview.summary.fields_kept }}
                        </dd>
                    </div>
                    <div class="rounded-lg border p-3">
                        <dt class="text-xs text-muted-foreground">
                            Etiquetas a quitar
                        </dt>
                        <dd class="text-lg font-semibold tabular-nums">
                            {{ preview.summary.tags_removed }}
                        </dd>
                    </div>
                    <div class="rounded-lg border p-3">
                        <dt class="text-xs text-muted-foreground">
                            Notas que se conservan
                        </dt>
                        <dd class="text-lg font-semibold tabular-nums">
                            {{ preview.summary.notes_kept }}
                        </dd>
                    </div>
                </dl>

                <div v-if="preview.kept.length" class="flex flex-col gap-2">
                    <p class="font-medium">
                        Se conservan por tener actividad posterior
                    </p>
                    <ul
                        class="flex max-h-56 flex-col divide-y overflow-y-auto rounded-lg border"
                    >
                        <li
                            v-for="record in preview.kept"
                            :key="record.contact_id"
                            class="flex flex-col gap-0.5 px-3 py-2"
                        >
                            <span>
                                <Link
                                    :href="contactShow(record.contact_id)"
                                    class="font-medium hover:underline"
                                    >{{ record.name }}</Link
                                >
                                <span class="text-xs text-muted-foreground">
                                    · fila {{ record.line ?? '—' }} ·
                                    {{
                                        record.action === 'created'
                                            ? 'creado'
                                            : 'completado'
                                    }}{{
                                        record.outcome === 'partial'
                                            ? ' · revertido en parte'
                                            : ''
                                    }}
                                </span>
                            </span>
                            <span class="text-xs text-muted-foreground">
                                {{ record.reason }}
                            </span>
                        </li>
                    </ul>
                    <p class="text-xs text-muted-foreground">
                        Se muestran hasta {{ preview.limit }} registros.
                    </p>
                </div>

                <p
                    v-if="preview.summary.records === 0"
                    class="text-muted-foreground"
                >
                    No hay registros pendientes de revertir.
                </p>

                <label class="flex items-start gap-2">
                    <input
                        v-model="form.confirmation"
                        type="checkbox"
                        class="mt-0.5 accent-primary"
                    />
                    <span>
                        Revisé la vista previa. Entiendo que los contactos
                        eliminados podrán restaurarse desde Contactos y que lo
                        conservado no se modificará.
                    </span>
                </label>
                <InputError :message="form.errors.confirmation" />
                <InputError :message="form.errors.rollback" />
            </div>

            <DialogFooter class="gap-2">
                <Button
                    variant="outline"
                    :disabled="form.processing"
                    @click="open = false"
                >
                    Cancelar
                </Button>
                <Button
                    variant="destructive"
                    :disabled="
                        !preview ||
                        loading ||
                        !form.confirmation ||
                        form.processing ||
                        preview.summary.records === 0
                    "
                    @click="submit"
                >
                    <Spinner v-if="form.processing" />
                    <RotateCcw v-else />
                    Revertir importación
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
