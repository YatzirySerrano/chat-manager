<script setup lang="ts">
defineProps<{
    active: boolean;
    activeLabel?: string;
    inactiveLabel?: string;
}>();
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap"
        :class="
            active
                ? 'bg-success-soft text-success-soft-foreground'
                : 'bg-muted text-muted-foreground'
        "
    >
        <span
            class="size-1.5 shrink-0 rounded-full"
            :class="active ? 'bg-success' : 'bg-muted-foreground/50'"
            aria-hidden="true"
        />
        {{ active ? (activeLabel ?? 'Activo') : (inactiveLabel ?? 'Inactivo') }}
    </span>
</template>
