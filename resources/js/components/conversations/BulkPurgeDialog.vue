<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { TriangleAlert } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import {
    fallbackMessage,
    historyRequest,
    HistoryRequestError,
} from '@/components/history-import/api';
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
 * Confirmation of «Vaciar conversaciones definitivamente» (Superadministrador):
 * a bulk, irreversible erase of a whole scope — every closed conversation, the
 * whole imported Whaticket history, or a single imported lote. The erase runs a
 * chunk at a time straight from here (POST /conversations/purge), so it never
 * depends on a queue worker and the progress bar shows real progress until the
 * scope is empty.
 */
const props = withDefaults(
    defineProps<{
        title: string;
        /** One sentence naming what is about to be erased. */
        description: string;
        /** What goes away for good. */
        erased: string[];
        /** What stays (shown so nobody fears losing more than this). */
        kept?: string[];
        /** Scope sent to the purge endpoint. */
        scope: 'closed' | 'whaticket';
        /** A single imported lote (whaticket), or null for the whole scope. */
        batchId?: number | null;
        confirmLabel?: string;
        /** Word to type; must match the server (ELIMINAR). */
        word?: string;
    }>(),
    {
        kept: () => [],
        batchId: null,
        confirmLabel: 'Eliminar definitivamente',
        word: 'ELIMINAR',
    },
);

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    purged: [];
}>();

const typed = ref('');
const purging = ref(false);
const error = ref<string | null>(null);
const total = ref<number | null>(null);
const deleted = ref(0);

const matches = computed(
    () => typed.value.trim().toUpperCase() === props.word.toUpperCase(),
);

const percent = computed(() => {
    if (total.value === null || total.value === 0) {
        return 0;
    }

    return Math.min(100, Math.round((deleted.value / total.value) * 100));
});

watch(open, (isOpen) => {
    if (isOpen) {
        typed.value = '';
        error.value = null;
        total.value = null;
        deleted.value = 0;
    }
});

async function confirm(): Promise<void> {
    if (!matches.value || purging.value) {
        return;
    }

    purging.value = true;
    error.value = null;
    deleted.value = 0;
    total.value = null;

    try {
        // Erase a chunk at a time until the scope is empty, updating progress.
        for (;;) {
            const step = await historyRequest<{
                deleted: number;
                remaining: number;
                done: boolean;
            }>('POST', '/conversations/purge', {
                scope: props.scope,
                batch_id: props.batchId ?? null,
                confirmation: typed.value,
            });

            if (total.value === null) {
                total.value = step.deleted + step.remaining;
            }

            deleted.value += step.deleted;

            if (step.done) {
                break;
            }

            // Safety net: a step that erased nothing while work remains would
            // loop forever. Stop and let the user retry.
            if (step.deleted === 0) {
                throw new HistoryRequestError(
                    0,
                    'No se pudo continuar el borrado. Vuelve a intentarlo.',
                );
            }
        }

        const removed = deleted.value;
        open.value = false;
        emit('purged');
        toast.success(
            removed === 0
                ? 'No había conversaciones que borrar.'
                : `Se borraron ${removed} conversaciones definitivamente.`,
        );
        router.reload();
    } catch (requestError) {
        error.value =
            requestError instanceof HistoryRequestError
                ? requestError.message
                : fallbackMessage(0);
    } finally {
        purging.value = false;
    }
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
                    {{ title }}
                </DialogTitle>
                <DialogDescription>{{ description }}</DialogDescription>
            </DialogHeader>

            <!-- While erasing: a real progress bar driven by the chunked requests. -->
            <div v-if="purging" class="space-y-3 py-2 text-sm">
                <p class="font-medium">Vaciando conversaciones…</p>
                <div
                    class="h-2.5 w-full overflow-hidden rounded-full bg-muted"
                    role="progressbar"
                    :aria-valuenow="percent"
                    aria-valuemin="0"
                    aria-valuemax="100"
                >
                    <div
                        class="h-full rounded-full bg-destructive transition-[width] duration-300"
                        :style="{ width: `${total === null ? 8 : percent}%` }"
                    />
                </div>
                <p class="text-muted-foreground">
                    <template v-if="total !== null">
                        {{ deleted }} de {{ total }} borradas ({{ percent }}%).
                        No cierres esta ventana.
                    </template>
                    <template v-else> Preparando el borrado… </template>
                </p>
            </div>

            <div v-else class="space-y-4 text-sm">
                <div
                    class="rounded-lg border border-destructive/25 bg-destructive-soft px-3.5 py-3 text-destructive-soft-foreground"
                >
                    <p class="font-semibold">
                        Se borra para siempre y no se puede deshacer
                    </p>
                    <ul class="mt-1.5 list-disc space-y-0.5 pl-5">
                        <li v-for="item in erased" :key="item">{{ item }}</li>
                    </ul>
                </div>

                <div v-if="kept.length > 0" class="text-muted-foreground">
                    <p class="font-medium text-foreground">Se conserva</p>
                    <ul class="mt-1 list-disc space-y-0.5 pl-5">
                        <li v-for="item in kept" :key="item">{{ item }}</li>
                    </ul>
                </div>

                <form class="space-y-1.5" @submit.prevent="confirm">
                    <Label for="bulk-purge-word">
                        Escribe {{ word }} para confirmar
                    </Label>
                    <Input
                        id="bulk-purge-word"
                        v-model="typed"
                        autocomplete="off"
                        autocapitalize="characters"
                        spellcheck="false"
                        :placeholder="word"
                        :aria-invalid="error ? true : undefined"
                        :aria-describedby="error ? 'bulk-purge-error' : undefined"
                        data-bulk-purge="word"
                    />
                    <p
                        v-if="error"
                        id="bulk-purge-error"
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
                    :disabled="purging"
                    @click="open = false"
                >
                    Cancelar
                </Button>
                <Button
                    variant="destructive"
                    :loading="purging"
                    :disabled="!matches || purging"
                    data-bulk-purge="confirm"
                    @click="confirm"
                >
                    {{ purging ? 'Vaciando…' : confirmLabel }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
