<script setup lang="ts">
import { UserRoundSearch } from '@lucide/vue';

/**
 * Marks a provisional contact: someone who wrote in and was registered
 * automatically, but nobody saved into the shared directory yet.
 */
withDefaults(defineProps<{ label?: string }>(), { label: 'No guardado' });
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded-full border border-warning/40 bg-warning/10 px-2 py-0.5 text-xs font-medium text-foreground"
        title="Registrado automáticamente al escribir; todavía no está guardado en el directorio de Contactos."
    >
        <UserRoundSearch class="size-3" aria-hidden="true" />
        {{ label }}
    </span>
</template>
