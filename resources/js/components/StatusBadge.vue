<script setup lang="ts">
defineProps<{
    active: boolean;
    activeLabel?: string;
    inactiveLabel?: string;
}>();
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium"
        :class="
            active
                ? 'bg-success/10 text-success'
                : 'bg-muted text-muted-foreground'
        "
    >
        <span
            class="size-1.5 rounded-full"
            :class="active ? 'bg-success' : 'bg-muted-foreground/50'"
            aria-hidden="true"
        />
        {{ active ? (activeLabel ?? 'Activo') : (inactiveLabel ?? 'Inactivo') }}
    </span>
</template>
