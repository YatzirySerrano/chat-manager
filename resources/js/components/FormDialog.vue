<script setup lang="ts">
import { computed, ref } from 'vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';

/**
 * Centered dialog for administrative forms. It keeps the form open on
 * validation errors (the parent only closes it on success), blocks closing
 * while a request is in flight and asks before discarding unsaved changes.
 */
const props = withDefaults(
    defineProps<{
        title: string;
        description?: string;
        size?: 'md' | 'lg' | 'xl';
        processing?: boolean;
        dirty?: boolean;
        submitLabel?: string;
        submitDisabled?: boolean;
    }>(),
    {
        description: undefined,
        size: 'md',
        processing: false,
        dirty: false,
        submitLabel: 'Guardar',
        submitDisabled: false,
    },
);

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    submit: [];
}>();

const confirmDiscard = ref(false);

const widthClass = computed(
    () =>
        ({
            md: 'sm:max-w-lg',
            lg: 'sm:max-w-2xl',
            xl: 'sm:max-w-4xl',
        })[props.size],
);

/**
 * Every way of closing (Escape, outside click, close button, Cancel) goes
 * through here so unsaved changes are never lost by accident.
 */
function requestOpenChange(value: boolean): void {
    if (value) {
        open.value = true;

        return;
    }

    if (props.processing) {
        return;
    }

    if (props.dirty) {
        confirmDiscard.value = true;

        return;
    }

    open.value = false;
}

function discard(): void {
    confirmDiscard.value = false;
    open.value = false;
}
</script>

<template>
    <Dialog :open="open" @update:open="requestOpenChange">
        <DialogContent
            :class="[
                'flex max-h-[min(90dvh,860px)] flex-col gap-0 overflow-hidden p-0',
                widthClass,
            ]"
        >
            <DialogHeader class="border-b px-6 pt-5 pb-4 text-left">
                <DialogTitle class="text-base font-semibold">
                    {{ title }}
                </DialogTitle>
                <DialogDescription v-if="description">
                    {{ description }}
                </DialogDescription>
            </DialogHeader>

            <form
                class="flex min-h-0 flex-1 flex-col"
                novalidate
                @submit.prevent="emit('submit')"
            >
                <div class="min-h-0 flex-1 overflow-y-auto px-6 py-5">
                    <slot />
                </div>

                <div
                    class="flex flex-col-reverse gap-2 border-t bg-muted/40 px-6 py-3.5 sm:flex-row sm:items-center sm:justify-end"
                >
                    <slot name="footer-start" />
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="processing"
                        @click="requestOpenChange(false)"
                    >
                        Cancelar
                    </Button>
                    <Button
                        type="submit"
                        :disabled="processing || submitDisabled"
                    >
                        <Spinner v-if="processing" />
                        {{ submitLabel }}
                    </Button>
                </div>
            </form>
        </DialogContent>
    </Dialog>

    <ConfirmDialog
        v-model:open="confirmDiscard"
        title="¿Descartar los cambios?"
        description="Los datos que capturaste en este formulario se perderán."
        confirm-label="Descartar"
        destructive
        @confirm="discard"
    />
</template>
