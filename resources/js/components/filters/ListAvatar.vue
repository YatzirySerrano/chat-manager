<script setup lang="ts">
import { UsersRound } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ChannelIcon from '@/components/connections/ChannelIcon.vue';
import { getInitials } from '@/composables/useInitials';
import { cn } from '@/lib/utils';
import type { ConnectionChannel } from '@/types';
import './filters.css';

/**
 * The avatar of a list row (inbox and internal chat): the initials on a
 * flat tint whose hue comes from the name, so each person always keeps the
 * same color. Its corner says where the row comes from (the channel badge:
 * WhatsApp, Messenger, Instagram, web chat) or, for teammates, that they
 * are online. A request waiting to be taken gets a dashed warning ring; a
 * closed one fades a little. Decorative: the row says all of it in words.
 *
 * `src`: the contact's WhatsApp profile picture (a private copy served by
 * the platform). It covers the initials once loaded and falls back to
 * them when it is missing or fails to load.
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
        /** Profile picture; the initials are shown without it. */
        src?: string | null;
    }>(),
    {
        channel: null,
        channelTitle: undefined,
        online: false,
        group: false,
        deleted: false,
        pending: false,
        dimmed: false,
        src: null,
    },
);

const failed = ref(false);

watch(
    () => props.src,
    () => {
        failed.value = false;
    },
);

const picture = computed(() =>
    props.src && !failed.value && !props.group && !props.deleted
        ? props.src
        : null,
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
                'relative inline-flex size-9 shrink-0 items-center justify-center rounded-full text-[0.8125rem] font-semibold select-none',
                deleted
                    ? 'bg-muted text-muted-foreground'
                    : group
                      ? 'bg-muted text-muted-foreground'
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
        <img
            v-if="picture"
            :src="picture"
            alt=""
            loading="lazy"
            decoding="async"
            referrerpolicy="no-referrer"
            class="absolute inset-0 size-full rounded-full object-cover"
            :class="dimmed && 'opacity-60'"
            data-list-avatar-photo
            @error="failed = true"
        />
        <span
            v-if="channel"
            :class="
                cn(
                    'absolute -right-0.5 -bottom-0.5 flex size-4 items-center justify-center rounded-full text-white ring-2 ring-background',
                    `list-channel-${channel}`,
                )
            "
            :title="channelTitle"
            data-list-channel
        >
            <ChannelIcon :channel="channel" class="size-[0.5625rem]" />
        </span>
        <span
            v-else-if="online && !group && !deleted"
            class="absolute right-0 bottom-0 size-2.5 rounded-full bg-success ring-2 ring-background"
            title="En línea"
        />
    </span>
</template>
