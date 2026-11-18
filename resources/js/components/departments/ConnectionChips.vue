<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Star } from '@lucide/vue';
import { computed } from 'vue';
import ChannelIcon from '@/components/connections/ChannelIcon.vue';
import type { ConnectionChannel } from '@/types';
import '@/components/admin/admin.css';

type ConnectionChip = {
    id: number;
    name: string;
    channel: ConnectionChannel;
    is_active: boolean;
    is_deleted?: boolean;
    is_default?: boolean;
    /** Link to the connection, when the viewer can open it. */
    url?: string | null;
};

/**
 * Connections of a department as small chips with their channel icon. An
 * inactive connection is dimmed; the department's default connection is
 * starred. Beyond `limit` they collapse into «+N».
 */
const props = withDefaults(
    defineProps<{
        connections: ConnectionChip[];
        limit?: number;
    }>(),
    { limit: 3 },
);

const shown = computed(() => props.connections.slice(0, props.limit));
const hidden = computed(() => props.connections.slice(props.limit));

function describe(connection: ConnectionChip): string {
    return [
        connection.name,
        connection.is_default ? 'predeterminado' : null,
        connection.is_deleted
            ? 'eliminada'
            : connection.is_active
              ? null
              : 'inactiva',
    ]
        .filter(Boolean)
        .join(' · ');
}
</script>

<template>
    <ul
        v-if="connections.length > 0"
        class="flex min-w-0 flex-wrap items-center gap-1"
        aria-label="Conexiones"
    >
        <li v-for="connection in shown" :key="connection.id" class="min-w-0">
            <component
                :is="connection.url ? Link : 'span'"
                v-bind="connection.url ? { href: connection.url } : {}"
                :data-channel-theme="connection.channel"
                class="inline-flex max-w-40 min-w-0 items-center gap-1.5 rounded-sm border bg-card py-0.5 pr-2 pl-1 text-xs font-medium text-foreground"
                :class="[
                    !connection.is_active && 'opacity-60',
                    connection.is_deleted && 'line-through',
                    connection.url &&
                        'relative z-10 transition-colors outline-none hover:border-border-strong focus-visible:ring-2 focus-visible:ring-ring',
                ]"
                :title="describe(connection)"
            >
                <span
                    class="channel-tile size-5 shrink-0 rounded-[3px]"
                    aria-hidden="true"
                >
                    <ChannelIcon :channel="connection.channel" class="size-3" />
                </span>
                <span class="truncate">{{ connection.name }}</span>
                <Star
                    v-if="connection.is_default"
                    class="size-3 shrink-0 fill-current text-warning"
                    aria-hidden="true"
                />
                <span class="sr-only">{{
                    describe(connection) === connection.name
                        ? ''
                        : `(${describe(connection).split(' · ').slice(1).join(', ')})`
                }}</span>
            </component>
        </li>
        <li
            v-if="hidden.length > 0"
            class="rounded-sm bg-muted px-1.5 py-0.5 text-xs font-medium text-muted-foreground tabular-nums"
            :title="hidden.map((connection) => connection.name).join(', ')"
        >
            +{{ hidden.length }}
            <span class="sr-only"
                >:
                {{
                    hidden.map((connection) => connection.name).join(', ')
                }}</span
            >
        </li>
    </ul>
    <span v-else class="text-xs text-warning-soft-foreground"
        >Sin conexiones</span
    >
</template>
