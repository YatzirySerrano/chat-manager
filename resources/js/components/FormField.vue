<script setup lang="ts">
import FieldHelp from '@/components/FieldHelp.vue';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
import type { FieldHelpContent } from '@/types';

defineProps<{
    id?: string;
    label: string;
    error?: string;
    hint?: string;
    help?: FieldHelpContent;
}>();
</script>

<template>
    <div class="grid gap-2">
        <div v-if="help" class="flex items-center gap-1.5">
            <Label :for="id">{{ label }}</Label>
            <FieldHelp :title="label" :help="help" />
        </div>
        <Label v-else :for="id">{{ label }}</Label>
        <slot />
        <p v-if="hint && !error" class="text-xs text-muted-foreground">
            {{ hint }}
        </p>
        <InputError :id="id ? `${id}-error` : undefined" :message="error" />
    </div>
</template>
