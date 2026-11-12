<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { KeyRound, ShieldCheck } from '@lucide/vue';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { register } from '@/routes/connections';

/**
 * Registers the connection's WhatsApp number with the Cloud API
 * (POST /{phone-number-id}/register). The six-digit PIN is the number's
 * two-step verification PIN: Meta sets it with this call when the number
 * has none and checks it when it already has one. It is sent once and never
 * stored by the platform; the field is cleared whenever the dialog closes
 * or the request ends.
 */
const props = defineProps<{
    connectionId: number;
    /** Number shown to confirm which line is being registered. */
    displayNumber?: string | null;
    /** Status Meta last reported for the number (e.g. CONNECTED, PENDING). */
    phoneStatus?: string | null;
}>();

const open = defineModel<boolean>('open', { required: true });

const form = useForm({ pin: '' });

const complete = computed(() => /^\d{6}$/.test(form.pin));
const alreadyConnected = computed(() => props.phoneStatus === 'CONNECTED');

watch(open, (isOpen) => {
    if (!isOpen) {
        form.reset();
        form.clearErrors();
    }
});

function submit(): void {
    if (!complete.value || form.processing) {
        return;
    }

    form.submit(register(props.connectionId), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
        // A wrong PIN is typed again from scratch; it is never kept.
        onError: () => form.reset('pin'),
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <form class="grid gap-5" novalidate @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>Registrar número en WhatsApp</DialogTitle>
                    <DialogDescription>
                        Es el paso que deja el número listo para enviar y
                        recibir por la API de Meta.
                        <template v-if="displayNumber">
                            Se registrará
                            <strong class="font-medium text-foreground">{{
                                displayNumber
                            }}</strong
                            >.
                        </template>
                    </DialogDescription>
                </DialogHeader>

                <p
                    v-if="alreadyConnected"
                    class="flex gap-2 rounded-lg bg-info-soft px-3 py-2 text-xs text-info-soft-foreground"
                    role="note"
                >
                    <ShieldCheck
                        class="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    Meta ya reporta este número como conectado. Regístralo de
                    nuevo solo si Meta te lo pide.
                </p>

                <div class="grid gap-2">
                    <label for="register-pin" class="text-sm font-medium"
                        >PIN de seis dígitos</label
                    >
                    <InputOTP
                        id="register-pin"
                        v-model="form.pin"
                        :maxlength="6"
                        :disabled="form.processing"
                        inputmode="numeric"
                        autocomplete="off"
                        autofocus
                        :aria-invalid="form.errors.pin ? true : undefined"
                        :aria-describedby="
                            form.errors.pin
                                ? 'register-pin-error'
                                : 'register-pin-hint'
                        "
                    >
                        <InputOTPGroup>
                            <InputOTPSlot
                                v-for="index in 6"
                                :key="index"
                                :index="index - 1"
                            />
                        </InputOTPGroup>
                    </InputOTP>
                    <InputError
                        id="register-pin-error"
                        :message="form.errors.pin"
                    />
                    <p
                        id="register-pin-hint"
                        class="max-w-prose text-xs text-pretty text-muted-foreground"
                    >
                        Si el número ya tiene verificación en dos pasos, escribe
                        ese PIN. Si no la tiene, el PIN que escribas quedará
                        como su PIN: guárdalo en un lugar seguro, Meta lo pedirá
                        para volver a registrarlo.
                    </p>
                </div>

                <p
                    class="flex gap-2 rounded-lg bg-surface-sunken px-3 py-2 text-xs text-muted-foreground"
                >
                    <KeyRound
                        class="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    El PIN se envía a Meta una sola vez y la plataforma no lo
                    guarda.
                </p>

                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="form.processing"
                        @click="open = false"
                    >
                        Cancelar
                    </Button>
                    <Button
                        type="submit"
                        :disabled="!complete"
                        :loading="form.processing"
                    >
                        Registrar número
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
