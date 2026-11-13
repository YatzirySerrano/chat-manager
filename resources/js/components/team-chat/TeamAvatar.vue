<script setup lang="ts">
import { UsersRound } from '@lucide/vue';
import { computed } from 'vue';
import { personTint } from '@/components/team-chat/teamChatFormat';
import { getInitials } from '@/composables/useInitials';
import { cn } from '@/lib/utils';

/**
 * Initials of a person (each one always with the same tint) or the icon of
 * a group. The green dot means «En línea»: the person has the application
 * open now (presence channel). There is no declared availability any more.
 */
const props = withDefaults(
    defineProps<{
        name: string;
        group?: boolean;
        deleted?: boolean;
        online?: boolean;
        size?: 'xs' | 'sm' | 'md' | 'lg';
    }>(),
    {
        group: false,
        deleted: false,
        online: false,
        size: 'md',
    },
);

const isOnline = computed(() => props.online && !props.group && !props.deleted);
const tint = computed(() =>
    props.deleted
        ? 'bg-muted text-muted-foreground'
        : props.group
          ? 'bg-primary-soft text-primary-soft-foreground'
          : personTint(props.name).avatar,
);
</script>

<template>
    <span
        :class="
            cn(
                'relative inline-flex shrink-0 items-center justify-center rounded-full font-semibold tracking-tight select-none',
                {
                    xs: 'size-7 text-[0.65rem]',
                    sm: 'size-8 text-xs',
                    md: 'size-10 text-sm',
                    lg: 'size-14 text-lg',
                }[size],
                tint,
            )
        "
        :title="isOnline ? 'En línea' : undefined"
        aria-hidden="true"
    >
        <UsersRound
            v-if="group"
            :class="
                size === 'lg' ? 'size-6' : size === 'xs' ? 'size-3.5' : 'size-4'
            "
        />
        <template v-else>{{ deleted ? '?' : getInitials(name) }}</template>
        <span
            v-if="isOnline"
            :class="
                cn(
                    'absolute right-0 bottom-0 rounded-full bg-success ring-2 ring-background',
                    size === 'lg' ? 'size-3.5' : 'size-2.5',
                )
            "
            title="En línea"
        />
    </span>
</template>
