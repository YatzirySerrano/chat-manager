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
import type { FormValidation } from '@/composables/useFormValidation';

/**
 * Centered dialog for administrative forms. It keeps the form open on
 * validation errors (the parent only closes it on success), blocks closing
 * while a request is in flight and asks before discarding unsaved changes.
 *
 * With `validation` (useFormValidation) it also refuses to submit invalid
 * data (showing every error and focusing the first invalid field), disables
 * the submit button of edit forms while nothing effectively changed, and
 * uses the normalized change detection for the discard confirmation.
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
        validation?: FormValidation;
    }>(),
    {
        description: undefined,
        size: 'md',
        processing: false,
        dirty: false,
        submitLabel: 'Guardar',
        submitDisabled: false,
        validation: undefined,
    },
);

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    submit: [];
}>();

const confirmDiscard = ref(false);

const hasUnsavedChanges = computed(() =>
    props.validation ? props.validation.hasChanges : props.dirty,
);

/** Edit forms without effective changes have nothing to save. */
const unchanged = computed(
    () =>
        props.validation !== undefined &&
        props.validation.requiresChanges &&
        !props.validation.hasChanges,
);

function submit(): void {
    if (props.processing || props.submitDisabled || unchanged.value) {
        return;
    }

    if (props.validation && !props.validation.validate()) {
        return;
    }

    emit('submit');
}

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

    if (hasUnsavedChanges.value) {
        confirmDiscard.value = true;

        return;
    }

    open.value = false;
}

const formElement = ref<HTMLFormElement | null>(null);

/**
 * The dialog starts on the first field to fill in, not on the first focusable
 * element (often a help icon, which would open its explanation on its own).
 */
function focusFirstField(event: Event): void {
    const field = formElement.value?.querySelector<HTMLElement>(
        'input:not([type="hidden"]):not([type="color"]):not([disabled]):not(.sr-only), textarea:not([disabled]), select:not([disabled])',
    );

    if (field) {
        event.preventDefault();
        field.focus();
    }
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
            @open-auto-focus="focusFirstField"
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
                ref="formElement"
                class="flex min-h-0 flex-1 flex-col"
                novalidate
                @submit.prevent="submit"
            >
                <div class="min-h-0 flex-1 overflow-y-auto px-6 py-5">
                    <slot />
                </div>

                <div
                    class="flex flex-col-reverse gap-2 border-t bg-muted/40 px-6 py-3.5 sm:flex-row sm:items-center sm:justify-end"
                >
                    <slot name="footer-start" />
                    <p
                        v-if="unchanged"
                        class="text-sm text-muted-foreground sm:mr-auto"
                        aria-live="polite"
                    >
                        Sin cambios por guardar
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        class="pointer-coarse:h-10"
                        :disabled="processing"
                        @click="requestOpenChange(false)"
                    >
                        Cancelar
                    </Button>
                    <Button
                        type="submit"
                        class="pointer-coarse:h-10"
                        :disabled="processing || submitDisabled || unchanged"
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
