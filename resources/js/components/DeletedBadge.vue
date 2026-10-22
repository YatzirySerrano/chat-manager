<script setup lang="ts">
import { Trash2 } from '@lucide/vue';

/**
 * Marks a logically deleted record (it keeps its history and can be
 * restored).
 */
defineProps<{
    /** Use the feminine form ("Eliminada") for etiqueta, conexión, etc. */
    feminine?: boolean;
}>();
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded-full bg-destructive/10 px-2 py-0.5 text-xs font-medium text-destructive dark:bg-destructive/20"
    >
        <Trash2 class="size-3" aria-hidden="true" />
        {{ feminine ? 'Eliminada' : 'Eliminado' }}
    </span>
</template>
