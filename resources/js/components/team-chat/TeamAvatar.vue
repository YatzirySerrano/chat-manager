<script setup lang="ts">
import { UsersRound } from '@lucide/vue';
import { computed } from 'vue';
import { getInitials } from '@/composables/useInitials';
import { cn } from '@/lib/utils';
import type { TeamAvailability } from '@/types/team-chat';

/**
 * Initials of a person (or a group icon) with two separate indicators:
 * - the dot is the availability declared by the person (Disponible / No
 *   disponible), an operational state;
 * - the ring means "En línea": the person has the application open now
 *   (presence channel), whatever their declared availability.
 */
const props = withDefaults(
    defineProps<{
        name: string;
        group?: boolean;
        deleted?: boolean;
        availability?: TeamAvailability | null;
        online?: boolean;
        size?: 'sm' | 'md';
    }>(),
    {
        group: false,
        deleted: false,
        availability: null,
        online: false,
        size: 'md',
    },
);

const availabilityLabel = computed(() =>
    props.availability === 'available' ? 'Disponible' : 'No disponible',
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
            v-if="!group && !deleted && availability"
            :class="
                cn(
                    'absolute -right-0.5 -bottom-0.5 size-3 rounded-full border-2 border-background',
                    availability === 'available'
                        ? 'bg-success'
                        : 'bg-muted-foreground/50',
                )
            "
            :title="availabilityLabel"
        />
    </span>
</template>
