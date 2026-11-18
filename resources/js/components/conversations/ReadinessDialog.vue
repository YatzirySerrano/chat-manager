<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { newClientReference } from '@/components/conversations/messageFormat';
import FormField from '@/components/FormField.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { usePermissions } from '@/composables/usePermissions';
import {
    conversationConflictFromErrors,
    withoutConflictCode,
} from '@/lib/conversations/actionErrors';
import { edit as missingInfoEdit } from '@/routes/admin/missing-info';
import { update as updateReadiness } from '@/routes/conversations/readiness';
import type {
    ConversationReadinessValue,
    ConversationSummary,
    CustomerWindow,
    MissingInfoCatalog,
} from '@/types';

/**
 * «Confirmar solicitud completa» is applied at once, with no dialog or
 * comment. «Marcar que necesita información» asks what is missing: items of
 * the catalog (Configuración › Información faltante) and an optional «Otro».
 * The list is recorded as the reason and, by default, sent to the customer
 * as a reply of the agent. The server authorizes again under lock; when the
 * message cannot be sent the request is still marked and the toast says why.
 */
const props = defineProps<{
    target: {
        conversation: ConversationSummary;
        readiness: ConversationReadinessValue;
    } | null;
    /** The catalog; null while phase two is off. */
    catalog: MissingInfoCatalog | null;
    /** Customer service window of the open conversation, when it is the target. */
    window: CustomerWindow | null;
}>();

const emit = defineEmits<{ close: [] }>();

/** Shortcut to the catalog (Configuración › Información faltante). */
const { can: holdsPermission } = usePermissions();
const catalogUrl = missingInfoEdit.url();

const OTHER_MIN = 5;
const OTHER_MAX = 200;

const processing = ref(false);
const selected = ref<string[]>([]);
const other = ref('');
const send = ref<boolean | 'indeterminate'>(true);
const error = ref<string | undefined>(undefined);
let clientReference = newClientReference();

const open = computed({
    get: () => props.target !== null && props.target.readiness !== 'ready',
    set: (value: boolean) => {
        if (!value && !processing.value) {
            emit('close');
        }
    },
});

const items = computed(() => props.catalog?.items ?? []);

/** Why the message cannot be sent now, as far as the page knows. */
const sendBlock = computed<string | null>(() => {
    const conversation = props.target?.conversation;

    if (!conversation) {
        return null;
    }

    // Whoever marks the request may send the list, also from the queue.
    if (!conversation.can.confirm_ready) {
        return 'No puedes escribirle al cliente en esta conversación.';
    }

    if (!conversation.connection.can_send) {
        return (
            conversation.connection.send_block_reason ??
            'La conexión no puede enviar mensajes ahora.'
        );
    }

    if (
        props.window &&
        props.window.status !== 'not_applicable' &&
        !props.window.allows_free_form
    ) {
        return (
            props.window.block_reason ??
            'La ventana de atención no permite enviar mensajes.'
        );
    }

    return null;
});

const sending = computed(() => send.value === true && sendBlock.value === null);

const missing = computed(() => {
    const picked = items.value.filter((item) => selected.value.includes(item));
    const text = other.value.trim().replace(/\s+/g, ' ');

    return text === '' ? picked : [...picked, text];
});

const preview = computed(() =>
    [
        props.catalog?.intro ?? '',
        ...missing.value.map((item) => `• ${item}`),
    ].join('\n'),
);

watch(open, (isOpen) => {
    if (isOpen) {
        selected.value = [];
        other.value = '';
        send.value = true;
        error.value = undefined;
        clientReference = newClientReference();
    }
});

function toggle(item: string, checked: boolean | 'indeterminate'): void {
    selected.value =
        checked === true
            ? [...selected.value, item]
            : selected.value.filter((value) => value !== item);
    error.value = undefined;
}

watch(
    () => props.target,
    (target) => {
        if (target?.readiness === 'ready') {
            post(target, {});
        }
    },
);

function submit(): void {
    const target = props.target;

    if (target === null || processing.value) {
        return;
    }

    const text = other.value.trim();

    if (selected.value.length === 0 && text.length < OTHER_MIN) {
        error.value =
            text === ''
                ? 'Selecciona la información que falta o descríbela en «Otro».'
                : `Describe la otra información con al menos ${OTHER_MIN} caracteres.`;

        return;
    }

    if (text !== '' && text.length < OTHER_MIN) {
        error.value = `Describe la otra información con al menos ${OTHER_MIN} caracteres.`;

        return;
    }

    post(target, {
        missing_items: items.value.filter((item) =>
            selected.value.includes(item),
        ),
        missing_other: text === '' ? null : text,
        send_message: sending.value,
        client_reference: sending.value ? clientReference : null,
    });
}

function post(
    target: NonNullable<typeof props.target>,
    extra: Record<string, unknown>,
): void {
    if (processing.value) {
        return;
    }

    router.post(
        updateReadiness.url(target.conversation.id),
        {
            readiness: target.readiness,
            version: target.conversation.version,
            ...extra,
        },
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => {
                processing.value = true;
                error.value = undefined;
            },
            onSuccess: () => emit('close'),
            onError: (errors) => {
                const field =
                    errors.reason ??
                    errors.missing_other ??
                    errors.missing_items ??
                    Object.entries(errors).find(([key]) =>
                        key.startsWith('missing_items.'),
                    )?.[1];

                if (field && target.readiness !== 'ready') {
                    error.value = field;

                    return;
                }

                toast.error(
                    conversationConflictFromErrors(errors)?.message ??
                        errors.conversation ??
                        Object.values(withoutConflictCode(errors))[0] ??
                        'No se pudo cambiar el estado de la solicitud. Inténtalo de nuevo.',
                );
                emit('close');
            },
            onFinish: () => (processing.value = false),
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90dvh] overflow-y-auto sm:max-w-md">
            <form
                class="grid gap-4"
                novalidate
                data-readiness-dialog
                @submit.prevent="submit"
            >
                <DialogHeader>
                    <DialogTitle>Marcar que necesita información</DialogTitle>
                    <DialogDescription>
                        La solicitud quedará como «Necesita información». Donde
                        la confirmación es obligatoria no podrá tomarse de la
                        cola hasta que se confirme como completa.
                    </DialogDescription>
                </DialogHeader>

                <fieldset class="grid gap-2">
                    <legend
                        class="mb-2 flex w-full items-center justify-between gap-2 text-sm font-medium"
                    >
                        Qué información falta
                        <Link
                            v-if="holdsPermission('settings.update')"
                            :href="catalogUrl"
                            class="text-xs font-normal text-primary underline-offset-4 hover:underline"
                            data-missing-catalog-link
                        >
                            Editar opciones
                        </Link>
                    </legend>
                    <p
                        v-if="items.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        No hay opciones configuradas. Descríbela en «Otro» o
                        agrégalas en Administración › Información faltante.
                    </p>
                    <label
                        v-for="(item, index) in items"
                        :key="item"
                        :for="`missing-item-${index}`"
                        class="flex cursor-pointer items-center gap-3 rounded-lg border px-3 py-2 text-sm transition-colors hover:bg-muted/50 has-[[data-state=checked]]:border-primary/40 has-[[data-state=checked]]:bg-primary/5"
                    >
                        <Checkbox
                            :id="`missing-item-${index}`"
                            :model-value="selected.includes(item)"
                            :data-missing-item="item"
                            @update:model-value="(value) => toggle(item, value)"
                        />
                        {{ item }}
                    </label>
                </fieldset>

                <FormField
                    id="missing-other"
                    label="Otro (opcional)"
                    hint="Lo que falte y no esté en la lista."
                >
                    <Textarea
                        id="missing-other"
                        v-model="other"
                        rows="2"
                        :maxlength="OTHER_MAX"
                        @input="error = undefined"
                    />
                </FormField>

                <InputError :message="error" />

                <div class="grid gap-2 rounded-xl border p-3">
                    <label
                        for="missing-send"
                        class="flex items-start gap-3 text-sm"
                        :class="
                            sendBlock === null
                                ? 'cursor-pointer'
                                : 'cursor-not-allowed opacity-70'
                        "
                    >
                        <Checkbox
                            id="missing-send"
                            v-model="send"
                            class="mt-0.5"
                            :disabled="sendBlock !== null"
                            data-missing-send
                        />
                        <span class="grid gap-1">
                            <span class="font-medium"
                                >Enviar mensaje al cliente</span
                            >
                            <span class="text-xs text-muted-foreground">
                                {{
                                    sendBlock ??
                                    'Se envía en el chat a tu nombre, como una respuesta normal.'
                                }}
                            </span>
                        </span>
                    </label>
                    <p
                        v-if="sending && missing.length > 0"
                        class="rounded-lg bg-muted/60 px-3 py-2 text-sm whitespace-pre-line"
                        aria-label="Vista previa del mensaje"
                        data-missing-preview
                    >
                        {{ preview }}
                    </p>
                </div>

                <DialogFooter class="gap-2 pointer-coarse:*:h-10">
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="processing"
                        @click="open = false"
                    >
                        Cancelar
                    </Button>
                    <Button
                        type="submit"
                        :disabled="processing"
                        :loading="processing"
                    >
                        {{
                            sending
                                ? 'Marcar y enviar al cliente'
                                : 'Marcar que necesita información'
                        }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
