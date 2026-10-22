<script setup lang="ts">
import { ShieldCheck } from '@lucide/vue';

/**
 * Marks an audit entry about the root authority: only superadministrators
 * can read it (the server never sends it to anyone else).
 */
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded-full border border-primary/30 px-1.5 py-0.5 text-[10px] font-medium text-primary"
        title="Solo visible para superadministradores"
    >
        <ShieldCheck class="size-3" aria-hidden="true" />
        Reservado
    </span>
</template>
