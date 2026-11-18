<script setup lang="ts">
import { Star } from '@lucide/vue';
import { computed } from 'vue';
import { colorPresentations } from '@/lib/colors';
import type { ConnectionDepartment } from '@/types';

/**
 * Departments authorized on a connection as color chips; the default one
 * goes first and carries a star. The rest beyond `limit` collapse into
 * «+N», whose tooltip names them.
 */
const props = withDefaults(
    defineProps<{
        departments: ConnectionDepartment[];
        limit?: number;
    }>(),
    { limit: 3 },
);

const ordered = computed(() =>
    [...props.departments].sort(
        (a, b) => Number(b.is_default) - Number(a.is_default),
    ),
);

const visible = computed(() => ordered.value.slice(0, props.limit));
const hidden = computed(() => ordered.value.slice(props.limit));
</script>

<template>
    <ul
        v-if="departments.length > 0"
        class="flex min-w-0 flex-wrap items-center gap-1"
        aria-label="Departamentos autorizados"
    >
        <li
            v-for="department in visible"
            :key="department.id"
            class="inline-flex max-w-44 min-w-0 items-center gap-1.5 rounded-sm px-1.5 py-0.5 text-xs font-medium"
            :class="!department.is_active && 'line-through opacity-60'"
            v-bind="colorPresentations(department.color).soft"
            :title="
                department.is_default
                    ? `${department.name} · predeterminado`
                    : department.name
            "
        >
            <Star
                v-if="department.is_default"
                class="size-3 shrink-0 fill-current"
                aria-hidden="true"
            />
            <span
                v-else
                class="size-1.5 shrink-0 rounded-full"
                v-bind="colorPresentations(department.color).dot"
                aria-hidden="true"
            />
            <span class="truncate">{{ department.name }}</span>
            <span v-if="department.is_default" class="sr-only">
                (predeterminado)</span
            >
            <span v-if="!department.is_active" class="sr-only">
                (eliminado)</span
            >
        </li>
        <li
            v-if="hidden.length > 0"
            class="rounded-sm bg-muted px-1.5 py-0.5 text-xs font-medium text-muted-foreground tabular-nums"
            :title="hidden.map((department) => department.name).join(', ')"
        >
            +{{ hidden.length }}
            <span class="sr-only"
                >:
                {{
                    hidden.map((department) => department.name).join(', ')
                }}</span
            >
        </li>
    </ul>
    <span v-else class="text-xs text-warning-soft-foreground"
        >Sin departamento</span
    >
</template>
