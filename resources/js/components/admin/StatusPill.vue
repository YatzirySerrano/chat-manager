<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';
import './admin.css';

/**
 * A short state of a record (Activo, Eliminado, Conectada, Con errores…) as a
 * tinted pill with a dot. `live` makes the dot breathe (live-dot): use it
 * only for a state that is happening right now (a connection that is
 * receiving, a session online), never for every row of a list.
 */
const props = withDefaults(
    defineProps<{
        label: string;
        tone?: 'success' | 'warning' | 'danger' | 'info' | 'primary' | 'muted';
        live?: boolean;
        title?: string;
        class?: HTMLAttributes['class'];
    }>(),
    { tone: 'muted', live: false, title: undefined, class: undefined },
);
</script>

<template>
    <span
        :class="cn('admin-pill', props.class)"
        :data-tone="tone"
        :title="title"
        data-status-pill
    >
        <span
            class="admin-pill-dot"
            :class="live && 'live-dot'"
            aria-hidden="true"
        />
        <span class="truncate">{{ label }}</span>
        <slot />
    </span>
</template>
