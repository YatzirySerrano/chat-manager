<script setup lang="ts">
import { Bot, CircleCheck, QrCode, ShieldCheck } from '@lucide/vue';
import { nextTick, ref } from 'vue';
import ChannelIcon from '@/components/connections/ChannelIcon.vue';
import ConnectionHealthBadge from '@/components/connections/ConnectionHealthBadge.vue';
import type { ChatbotConnection, ConnectionChannel } from '@/types';

/**
 * «¿Qué número vas a configurar?»: the connections as cards (name, phone,
 * how it is linked, its real state and whether its bot is on). The chatbot
 * is configured per connection, so this is the first thing on the page.
 * It is a radio group: arrow keys move the choice.
 */
const props = defineProps<{
    connections: ChatbotConnection[];
    /** Keyword rules that apply to each connection, by id. */
    ruleCounts: Map<number, number>;
}>();

const selected = defineModel<number | null>({ required: true });

const list = ref<HTMLElement | null>(null);

function modality(connection: ChatbotConnection): {
    label: string;
    icon: typeof QrCode | null;
} {
    if (connection.is_whatsapp_web) {
        return { label: 'WhatsApp por QR', icon: QrCode };
    }

    if (connection.channel.value === 'whatsapp') {
        return { label: 'API oficial de Meta', icon: ShieldCheck };
    }

    return { label: connection.channel.label, icon: null };
}

async function step(index: number, offset: -1 | 1): Promise<void> {
    const total = props.connections.length;
    const target = (index + offset + total) % total;

    selected.value = props.connections[target].id;
    await nextTick();
    list.value
        ?.querySelectorAll<HTMLButtonElement>('[role=radio]')
        [target]?.focus();
}
</script>

<template>
    <div
        ref="list"
        class="auto-grid [--col-min:16rem]"
        role="radiogroup"
        aria-label="Número de WhatsApp que vas a configurar"
        data-test="chatbot-connection-picker"
    >
        <button
            v-for="(connection, index) in connections"
            :key="connection.id"
            type="button"
            role="radio"
            :aria-checked="connection.id === selected"
            :tabindex="connection.id === selected ? 0 : -1"
            class="group relative grid content-start gap-3 rounded-xl border bg-card p-4 text-left transition-[border-color,background-color,box-shadow] duration-(--duration-fast) hover:border-border-strong focus-visible:ring-3 focus-visible:ring-primary/30 focus-visible:outline-none"
            :class="
                connection.id === selected &&
                'border-primary bg-primary-soft/40 shadow-(--shadow-control) hover:border-primary'
            "
            :data-connection="connection.id"
            @click="selected = connection.id"
            @keydown.right.prevent="step(index, 1)"
            @keydown.down.prevent="step(index, 1)"
            @keydown.left.prevent="step(index, -1)"
            @keydown.up.prevent="step(index, -1)"
        >
            <CircleCheck
                v-if="connection.id === selected"
                class="absolute top-3 right-3 size-5 text-primary"
                aria-hidden="true"
            />

            <span class="flex min-w-0 items-center gap-3 pr-6">
                <span
                    class="grid size-10 shrink-0 place-items-center rounded-full [&_svg]:size-5"
                    :class="
                        connection.channel.value === 'whatsapp'
                            ? 'bg-success-soft text-success-soft-foreground'
                            : 'bg-primary-soft text-primary-soft-foreground'
                    "
                    aria-hidden="true"
                >
                    <ChannelIcon
                        :channel="connection.channel.value as ConnectionChannel"
                    />
                </span>
                <span class="grid min-w-0">
                    <span class="truncate font-display text-base font-semibold">
                        {{ connection.name }}
                    </span>
                    <span
                        class="truncate text-sm tabular-nums"
                        :class="
                            connection.phone
                                ? 'text-foreground/80'
                                : 'text-muted-foreground italic'
                        "
                    >
                        {{ connection.phone ?? 'Sin número vinculado' }}
                    </span>
                </span>
            </span>

            <span class="flex flex-wrap items-center gap-1.5 text-xs">
                <span
                    class="inline-flex items-center gap-1 rounded-full border bg-background px-2 py-0.5 font-medium text-muted-foreground"
                >
                    <component
                        :is="modality(connection).icon"
                        v-if="modality(connection).icon"
                        class="size-3.5"
                        aria-hidden="true"
                    />
                    {{ modality(connection).label }}
                </span>
                <ConnectionHealthBadge :health="connection.status" />
            </span>

            <span
                class="flex items-center justify-between gap-2 border-t pt-3 text-sm"
            >
                <span
                    class="inline-flex items-center gap-1.5 font-medium"
                    :class="
                        connection.menu.is_enabled
                            ? 'text-success-soft-foreground'
                            : 'text-muted-foreground'
                    "
                >
                    <Bot class="size-4" aria-hidden="true" />
                    {{
                        connection.menu.is_enabled
                            ? 'Menú encendido'
                            : 'Menú apagado'
                    }}
                </span>
                <span class="text-xs text-muted-foreground tabular-nums">
                    {{
                        (ruleCounts.get(connection.id) ?? 0) === 1
                            ? '1 palabra clave'
                            : `${ruleCounts.get(connection.id) ?? 0} palabras clave`
                    }}
                </span>
            </span>
        </button>
    </div>
</template>
