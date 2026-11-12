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
 * Confirmation of an irreversible deletion (group «Eliminación definitiva»
 * of Roles y permisos). It says what is erased and what is kept, and only
 * enables the destructive button once the person typed the confirmation
 * word; the server checks the same word again.
 *
 * The request is a DELETE to `url` with `{ confirmation }`. The server
 * answers with a redirect and a toast, like every other operation.
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
        url: string;
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
    deleted: [];
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

    router.delete(props.url, {
        data: { confirmation: typed.value },
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            emit('deleted');
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
                    <Label for="permanent-delete-word">
                        Escribe {{ word }} para confirmar
                    </Label>
                    <Input
                        id="permanent-delete-word"
                        v-model="typed"
                        autocomplete="off"
                        autocapitalize="characters"
                        spellcheck="false"
                        :placeholder="word"
                        :aria-invalid="error ? true : undefined"
                        :aria-describedby="
                            error ? 'permanent-delete-error' : undefined
                        "
                        data-permanent-delete="word"
                    />
                    <p
                        v-if="error"
                        id="permanent-delete-error"
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
                    data-permanent-delete="confirm"
                    @click="confirm"
                >
                    {{ confirmLabel }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
