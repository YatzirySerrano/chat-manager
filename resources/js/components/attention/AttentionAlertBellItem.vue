<script setup lang="ts">
import { ListChecks } from '@lucide/vue';
import { computed, ref } from 'vue';
import { ALERT_SURFACE, PRIORITY_LOOK } from '@/components/attention/priority';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime, formatRelative } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { MyAttentionAlert } from '@/types';

/**
 * A pending attention alert inside the notification bell: the same alert
 * as the floating card, in the bell's compact layout. Reading it (opening
 * the bell, expanding the message) changes nothing; only «Entendido»
 * acknowledges it. Its background is the alert's color surface, whose
 * text keeps a high contrast in light and dark mode.
 */
const props = defineProps<{
    alert: MyAttentionAlert;
    acknowledging: boolean;
    /** Another alert is being acknowledged: one at a time. */
    busy: boolean;
}>();

const emit = defineEmits<{ acknowledge: []; viewPending: [] }>();

const look = computed(() => PRIORITY_LOOK[props.alert.priority]);
const surface = computed(
    () => ALERT_SURFACE[props.alert.color] ?? ALERT_SURFACE.blue,
);

/** Long messages start folded so one alert never fills the menu. */
const LONG_BODY = 160;
const long = computed(
    () =>
        props.alert.body.length > LONG_BODY || props.alert.body.includes('\n'),
);
const expanded = ref(false);
const bodyId = computed(() => `attention-bell-body-${props.alert.id}`);
</script>

<template>
    <article
        :class="
            cn(
                'grid gap-1.5 rounded-lg border px-3 py-2.5 text-left',
                surface.surface,
                look.frame,
            )
        "
        :aria-label="`Alerta ${alert.priority_label.toLowerCase()}: ${alert.title}`"
        data-bell-attention-alert
        :data-priority="alert.priority"
        :data-color="alert.color"
    >
        <header class="flex items-center justify-between gap-2">
            <span
                :class="
                    cn(
                        'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-semibold',
                        look.badge,
                        'bg-background',
                    )
                "
            >
                <component
                    :is="look.icon"
                    class="size-3.5"
                    aria-hidden="true"
                />
                {{ alert.priority_label }}
            </span>
            <time
                v-if="alert.sent_at"
                :class="cn('shrink-0 text-xs', surface.secondary)"
                :datetime="alert.sent_at"
                :title="formatDateTime(alert.sent_at)"
                >{{ formatRelative(alert.sent_at) }}</time
            >
        </header>
        <h3 class="text-sm leading-snug font-semibold [overflow-wrap:anywhere]">
            {{ alert.title }}
        </h3>
        <p
            :id="bodyId"
            :class="
                cn(
                    'text-sm [overflow-wrap:anywhere] whitespace-pre-line',
                    long && !expanded && 'line-clamp-3',
                )
            "
            data-bell-attention-body
        >
            {{ alert.body }}
        </p>
        <button
            v-if="long"
            type="button"
            :class="
                cn(
                    'w-fit rounded text-xs font-medium underline underline-offset-2 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                    surface.secondary,
                )
            "
            :aria-expanded="expanded"
            :aria-controls="bodyId"
            data-bell-attention-expand
            @click="expanded = !expanded"
        >
            {{ expanded ? 'Ver menos' : 'Ver mensaje completo' }}
        </button>
        <p :class="cn('text-xs', surface.secondary)">
            De {{ alert.sender }} ·
            <span data-bell-attention-state>Pendiente de confirmar</span>
        </p>
        <footer class="flex flex-wrap justify-end gap-2 pt-0.5">
            <Button
                v-if="alert.action === 'pending_conversations'"
                variant="outline"
                size="sm"
                class="pointer-coarse:h-10"
                @click="emit('viewPending')"
            >
                <ListChecks aria-hidden="true" />
                Ver mis conversaciones
            </Button>
            <Button
                size="sm"
                class="pointer-coarse:h-10"
                :disabled="acknowledging || busy"
                data-bell-attention-acknowledge
                @click="emit('acknowledge')"
            >
                <Spinner v-if="acknowledging" />
                Entendido
            </Button>
        </footer>
    </article>
</template>
