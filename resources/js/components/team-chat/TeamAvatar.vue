<script setup lang="ts">
import { UsersRound } from '@lucide/vue';
import { computed } from 'vue';
import { getInitials } from '@/composables/useInitials';
import { cn } from '@/lib/utils';

/**
 * Initials of a person (or a group icon). The ring and the dot mean
 * "En línea": the person has the application open now (presence channel).
 * There is no declared availability any more.
 */
const props = withDefaults(
    defineProps<{
        name: string;
        group?: boolean;
        deleted?: boolean;
        online?: boolean;
        size?: 'sm' | 'md';
    }>(),
    {
        group: false,
        deleted: false,
        online: false,
        size: 'md',
    },
);

const isOnline = computed(() => props.online && !props.group && !props.deleted);
</script>

<template>
    <span
        :class="
            cn(
                'relative inline-flex shrink-0 items-center justify-center rounded-full font-semibold select-none',
                size === 'sm' ? 'size-8 text-xs' : 'size-10 text-sm',
                deleted
                    ? 'bg-muted text-muted-foreground'
                    : 'bg-primary-soft text-primary-soft-foreground',
                isOnline &&
                    'ring-2 ring-primary ring-offset-2 ring-offset-background',
            )
        "
        :title="isOnline ? 'En línea' : undefined"
        aria-hidden="true"
    >
        <UsersRound v-if="group" class="size-4" />
        <template v-else>{{ deleted ? '?' : getInitials(name) }}</template>
        <span
            v-if="isOnline"
            class="absolute -right-0.5 -bottom-0.5 size-3 rounded-full border-2 border-background bg-success"
            title="En línea"
        />
    </span>
</template>
