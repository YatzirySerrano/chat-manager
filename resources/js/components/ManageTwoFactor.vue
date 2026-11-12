<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ShieldCheck } from '@lucide/vue';
import { onUnmounted, ref } from 'vue';
import FormSection from '@/components/FormSection.vue';
import TwoFactorRecoveryCodes from '@/components/TwoFactorRecoveryCodes.vue';
import TwoFactorSetupModal from '@/components/TwoFactorSetupModal.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useTwoFactorAuth } from '@/composables/useTwoFactorAuth';
import { disable, enable } from '@/routes/two-factor';

export type Props = {
    canManageTwoFactor?: boolean;
    requiresConfirmation?: boolean;
    twoFactorEnabled?: boolean;
};

withDefaults(defineProps<Props>(), {
    canManageTwoFactor: false,
    requiresConfirmation: false,
    twoFactorEnabled: false,
});

const { hasSetupData, clearTwoFactorAuthData } = useTwoFactorAuth();
const showSetupModal = ref<boolean>(false);

onUnmounted(() => clearTwoFactorAuthData());
</script>

<template>
    <FormSection
        v-if="canManageTwoFactor"
        title="Autenticación en dos pasos"
        description="Una capa extra de seguridad: además de tu contraseña se pide un código temporal de tu teléfono."
    >
        <template #aside>
            <Badge :variant="twoFactorEnabled ? 'success' : 'muted'">
                {{ twoFactorEnabled ? 'Activada' : 'Desactivada' }}
            </Badge>
        </template>

        <div v-if="!twoFactorEnabled" class="flex flex-col items-start gap-4">
            <p class="max-w-prose text-sm text-pretty text-muted-foreground">
                Al activarla, se te pedirá un código temporal al iniciar sesión.
                Lo obtendrás de una aplicación de autenticación (TOTP) en tu
                teléfono.
            </p>

            <Button v-if="hasSetupData" @click="showSetupModal = true">
                <ShieldCheck />Continuar configuración
            </Button>
            <Form
                v-else
                v-bind="enable.form()"
                @success="showSetupModal = true"
                #default="{ processing }"
            >
                <Button type="submit" :loading="processing">
                    <ShieldCheck />
                    Activar
                </Button>
            </Form>
        </div>

        <div v-else class="flex flex-col items-start gap-4">
            <p class="max-w-prose text-sm text-pretty text-muted-foreground">
                Al iniciar sesión se te pedirá un código temporal de tu
                aplicación de autenticación (TOTP).
            </p>

            <Form v-bind="disable.form()" #default="{ processing }">
                <Button
                    variant="destructive"
                    type="submit"
                    :loading="processing"
                >
                    Desactivar
                </Button>
            </Form>

            <TwoFactorRecoveryCodes />
        </div>

        <TwoFactorSetupModal
            v-model:isOpen="showSetupModal"
            :requiresConfirmation="requiresConfirmation"
            :twoFactorEnabled="twoFactorEnabled"
        />
    </FormSection>
</template>
