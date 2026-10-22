<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Check, Circle, ShieldCheck } from '@lucide/vue';
import { computed } from 'vue';
import { passwordRequirements } from '@/lib/validation';

/**
 * Live checklist of the effective password policy shared by the server
 * (Password::defaults()). It only informs: the server validates again,
 * including the check against known leaks.
 */
const props = defineProps<{
    password: string;
    id?: string;
}>();

const page = usePage();
const policy = computed(() => page.props.passwordPolicy);

const requirements = computed(() =>
    passwordRequirements(policy.value, props.password),
);
</script>

<template>
    <div :id="id" class="grid gap-1.5 text-xs">
        <p class="font-medium text-muted-foreground">
            La contraseña debe tener:
        </p>
        <ul class="grid gap-1 sm:grid-cols-2" aria-live="polite">
            <li
                v-for="requirement in requirements"
                :key="requirement.key"
                class="flex items-center gap-1.5"
                :class="
                    requirement.met ? 'text-success' : 'text-muted-foreground'
                "
            >
                <Check
                    v-if="requirement.met"
                    class="size-3.5 shrink-0"
                    aria-hidden="true"
                />
                <Circle v-else class="size-3 shrink-0" aria-hidden="true" />
                <span>{{ requirement.label }}</span>
                <span class="sr-only">{{
                    requirement.met ? '(cumplido)' : '(pendiente)'
                }}</span>
            </li>
        </ul>
        <p
            v-if="policy.uncompromised"
            class="flex items-center gap-1.5 text-muted-foreground"
        >
            <ShieldCheck class="size-3.5 shrink-0" aria-hidden="true" />
            Al guardar se verifica que no aparezca en filtraciones conocidas.
        </p>
    </div>
</template>
