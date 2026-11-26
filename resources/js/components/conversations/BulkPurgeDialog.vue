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
 * Confirmation of «Vaciar conversaciones definitivamente» (Superadministrador):
 * a bulk, irreversible erase of a whole scope (every closed conversation, or
 * the whole imported Whaticket history). Same look and typed-word UX as
 * PermanentDeleteDialog, but the request is a POST to the single purge
 * endpoint with `{ scope, confirmation }` instead of a DELETE to a per-row
 * url, so it is its own small component rather than a prop variant of that
 * one.
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
        confirmLabel?: string;
        /** Word to type; must match the server (ELIMINAR). */
        word?: string;
    }>(),
    {
        kept: () => [],
        confirmLabel: 'Eliminar definitivamente',
        word: 'ELIMINAR',
    },
);

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    purged: [];
}>();

const typed = ref('');
const processing = ref(false);
const error = ref<string | null>(null);

const matches = computed(
    () => typed.value.trim().toUpperCase() === props.word.toUpperCase(),
);

watch(open, (isOpen) => {
    if (isOpen) {
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

    router.post(
        '/conversations/purge',
        { scope: props.scope, confirmation: typed.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                open.value = false;
                emit('purged');
                // The erase runs in the background a slice at a time: refresh
                // now and shortly after so the conversations visibly start
                // disappearing from the list without a manual reload.
                router.reload();
                window.setTimeout(() => router.reload(), 3500);
            },
            onError: (errors) => {
                error.value =
                    Object.values(errors)[0] ??
                    'No se pudo iniciar el borrado. Inténtalo de nuevo.';
                toast.error(error.value);
            },
            onFinish: () => {
                processing.value = false;
            },
        },
    );
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

            <div class="space-y-4 text-sm">
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
                    :disabled="processing"
                    @click="open = false"
                >
                    Cancelar
                </Button>
                <Button
                    variant="destructive"
                    :loading="processing"
                    :disabled="!matches"
                    data-bulk-purge="confirm"
                    @click="confirm"
                >
                    {{ processing ? 'Vaciando…' : confirmLabel }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
