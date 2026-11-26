<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { TriangleAlert } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
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
import { Label } from '@/components/ui/label';

/**
 * Confirmation of «Eliminar registros de auditoría» (Superadministrador):
 * an irreversible bulk erase of the audit log, all of it or everything
 * before an optional date. Same look and typed-word UX as BulkPurgeDialog,
 * plus the optional date field this operation needs.
 *
 * The request is a DELETE to `/admin/audit` with `{ before, confirmation }`.
 */
const WORD = 'ELIMINAR';

const open = defineModel<boolean>('open', { required: true });

const before = ref('');
const typed = ref('');
const processing = ref(false);
const error = ref<string | null>(null);

const matches = computed(
    () => typed.value.trim().toUpperCase() === WORD.toUpperCase(),
);

watch(open, (isOpen) => {
    if (isOpen) {
        before.value = '';
        typed.value = '';
        error.value = null;
    }
});

function confirm(): void {
    if (!matches.value || processing.value) {
        return;
    }

    processing.value = true;
    error.value = null;

    router.delete('/admin/audit', {
        data: {
            before: before.value !== '' ? before.value : null,
            confirmation: typed.value,
        },
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
        onError: (errors) => {
            error.value =
                Object.values(errors)[0] ??
                'No se pudo eliminar. Inténtalo de nuevo.';
            toast.error(error.value);
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <span
                        class="grid size-8 shrink-0 place-items-center rounded-lg bg-destructive-soft text-destructive-soft-foreground"
                    >
                        <TriangleAlert class="size-4" aria-hidden="true" />
                    </span>
                    Eliminar registros de auditoría
                </DialogTitle>
                <DialogDescription>
                    Esto borra registros del historial de auditoría de forma
                    permanente. No se puede deshacer.
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-4 text-sm">
                <div
                    class="rounded-lg border border-destructive/25 bg-destructive-soft px-3.5 py-3 text-destructive-soft-foreground"
                >
                    <p class="font-semibold">
                        Se borra para siempre y no se puede deshacer
                    </p>
                    <p class="mt-1">
                        El sistema también borra solo los registros con más
                        de 180 días.
                    </p>
                </div>

                <div class="space-y-1.5">
                    <Label for="audit-purge-before">
                        Borrar registros anteriores a (opcional)
                    </Label>
                    <Input
                        id="audit-purge-before"
                        v-model="before"
                        type="date"
                        class="w-full"
                    />
                    <p class="text-xs text-muted-foreground">
                        Déjalo vacío para borrar TODOS los registros de
                        auditoría.
                    </p>
                </div>

                <form class="space-y-1.5" @submit.prevent="confirm">
                    <Label for="audit-purge-word">
                        Escribe {{ WORD }} para confirmar
                    </Label>
                    <Input
                        id="audit-purge-word"
                        v-model="typed"
                        autocomplete="off"
                        autocapitalize="characters"
                        spellcheck="false"
                        :placeholder="WORD"
                        :aria-invalid="error ? true : undefined"
                        :aria-describedby="
                            error ? 'audit-purge-error' : undefined
                        "
                        data-audit-purge="word"
                    />
                    <p
                        v-if="error"
                        id="audit-purge-error"
                        class="text-xs text-destructive"
                        role="alert"
                    >
                        {{ error }}
                    </p>
                </form>
            </div>

            <DialogFooter class="gap-2">
                <Button
                    variant="outline"
                    :disabled="processing"
                    @click="open = false"
                >
                    Cancelar
                </Button>
                <Button
                    variant="destructive"
                    :loading="processing"
                    :disabled="!matches"
                    data-audit-purge="confirm"
                    @click="confirm"
                >
                    {{ processing ? 'Eliminando…' : 'Eliminar registros' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
