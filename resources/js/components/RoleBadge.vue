<script setup lang="ts">
import { ShieldCheck } from '@lucide/vue';
import { computed } from 'vue';
import type { RoleSummary } from '@/types';

const props = defineProps<{
    role: RoleSummary | null;
}>();

const classes = computed(() => {
    if (!props.role) {
        return 'border-destructive/30 bg-destructive-soft text-destructive-soft-foreground';
    }

    if (props.role.is_active === false) {
        return 'border-dashed text-muted-foreground line-through decoration-muted-foreground/50';
    }

    return props.role.is_root
        ? 'border-primary/25 bg-primary-soft text-primary-soft-foreground'
        : 'bg-secondary text-secondary-foreground';
});
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded-md border border-transparent px-2 py-0.5 text-xs font-medium whitespace-nowrap"
        :class="classes"
        :title="
            role?.is_active === false
                ? 'Rol eliminado: no otorga permisos'
                : undefined
        "
    >
        <ShieldCheck v-if="role?.is_root" class="size-3" aria-hidden="true" />
        {{ role?.label ?? 'Sin rol' }}
    </span>
</template>
