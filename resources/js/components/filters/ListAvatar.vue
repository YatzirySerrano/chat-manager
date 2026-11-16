<script setup lang="ts">
import { UsersRound } from '@lucide/vue';
import { computed } from 'vue';
import ChannelIcon from '@/components/connections/ChannelIcon.vue';
import { getInitials } from '@/composables/useInitials';
import { cn } from '@/lib/utils';
import type { ConnectionChannel } from '@/types';
import './filters.css';

/**
 * The avatar of a list row (inbox and internal chat): the initials on a
 * gradient whose hue comes from the name, so each person always keeps the
 * same colors. Its corner says where the row comes from (the channel badge:
 * WhatsApp, Messenger, Instagram, web chat) or, for teammates, that they
 * are online. A request waiting to be taken gets a dashed warning ring; a
 * closed one fades a little. Decorative: the row says all of it in words.
 */
const props = withDefaults(
    defineProps<{
        name: string;
        channel?: ConnectionChannel | null;
        /** Tooltip of the channel badge (connection and phone). */
        channelTitle?: string;
        online?: boolean;
        group?: boolean;
        deleted?: boolean;
        pending?: boolean;
        dimmed?: boolean;
    }>(),
    {
        channel: null,
        channelTitle: undefined,
        online: false,
        group: false,
        deleted: false,
        pending: false,
        dimmed: false,
    },
);

const hue = computed(() => {
    let hash = 0;

    for (let index = 0; index < props.name.length; index += 1) {
        hash = (hash * 31 + props.name.charCodeAt(index)) >>> 0;
    }

    return hash % 360;
});
</script>

<template>
    <span
        :class="
            cn(
                'relative inline-flex size-10 shrink-0 items-center justify-center rounded-full text-sm font-semibold tracking-tight transition-transform duration-(--duration-base) ease-(--ease-spring) select-none group-hover/row:scale-[1.04]',
                deleted
                    ? 'bg-muted text-muted-foreground'
                    : group
                      ? 'bg-primary-soft text-primary-soft-foreground ring-1 ring-primary/20'
                      : 'list-avatar',
                pending && 'list-avatar-pending',
            )
        "
        :style="{ '--h': hue }"
        aria-hidden="true"
    >
        <span
            class="inline-flex items-center justify-center"
            :class="dimmed && 'opacity-60'"
        >
            <UsersRound v-if="group" class="size-4" />
            <template v-else>{{ deleted ? '?' : getInitials(name) }}</template>
        </span>
        <span
            v-if="channel"
            :class="
                cn(
                    'absolute -right-1 -bottom-1 flex size-[1.125rem] items-center justify-center rounded-full text-white ring-2 ring-background',
                    `list-channel-${channel}`,
                )
            "
            :title="channelTitle"
            data-list-channel
        >
            <ChannelIcon :channel="channel" class="size-2.5" />
        </span>
        <span
            v-else-if="online && !group && !deleted"
            class="absolute right-0 bottom-0 size-3 rounded-full bg-success ring-2 ring-background"
            title="En línea"
        />
    </span>
</template>
