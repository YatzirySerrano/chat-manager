<script setup lang="ts">
import { ListChecks } from '@lucide/vue';
import { computed } from 'vue';
import { ALERT_SURFACE, PRIORITY_LOOK } from '@/components/attention/priority';
import { Button } from '@/components/ui/button';
import { formatDateTime, formatRelative } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { AttentionAlertPriority, ColorSwatch } from '@/types';

/**
 * One attention alert as an agent receives it (and as the composer
 * previews it): priority with its name and icon, title, message (plain
 * text), who sent it and when, a way to the conversations waiting for
 * them when the sender offered it, and «Entendido».
 */
const props = withDefaults(
    defineProps<{
        title: string;
        body: string;
        priority: AttentionAlertPriority;
        priorityLabel: string;
        color: ColorSwatch;
        sender: string;
        sentAt: string | null;
        linkPending: boolean;
        acknowledging?: boolean;
        /** Composer preview: buttons do nothing. */
        preview?: boolean;
    }>(),
    { acknowledging: false, preview: false },
);

const emit = defineEmits<{ acknowledge: []; viewPending: [] }>();

const look = computed(() => PRIORITY_LOOK[props.priority]);
const surface = computed(
    () => ALERT_SURFACE[props.color] ?? ALERT_SURFACE.blue,
);
</script>

<template>
    <article
        :class="
            cn(
                'relative grid gap-2 overflow-hidden rounded-xl border px-4 py-3 shadow-elevated',
                surface.surface,
                look.frame,
            )
        "
        :aria-label="`Alerta ${priorityLabel.toLowerCase()}: ${title}`"
        data-attention-alert
        :data-priority="priority"
        :data-color="color"
    >
        <header class="flex items-start justify-between gap-3">
            <span
                :class="
                    cn(
                        'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-semibold',
                        look.badge,
                        // Opaque, so the badge reads the same on any background.
                        'bg-background',
                    )
                "
            >
                <component
                    :is="look.icon"
                    class="size-3.5"
                    aria-hidden="true"
                />
                {{ priorityLabel }}
            </span>
            <time
                v-if="sentAt"
                :class="cn('shrink-0 text-xs', surface.secondary)"
                :datetime="sentAt"
                :title="formatDateTime(sentAt)"
                >{{ formatRelative(sentAt) }}</time
            >
        </header>
        <h2 class="text-sm leading-snug font-semibold [overflow-wrap:anywhere]">
            {{ title }}
        </h2>
        <p class="text-sm [overflow-wrap:anywhere] whitespace-pre-line">
            {{ body }}
        </p>
        <p :class="cn('text-xs', surface.secondary)">De {{ sender }}</p>
        <footer class="flex flex-wrap justify-end gap-2 pt-1">
            <Button
                v-if="linkPending"
                variant="outline"
                size="sm"
                class="pointer-coarse:h-10"
                :disabled="preview"
                @click="emit('viewPending')"
            >
                <ListChecks aria-hidden="true" />
                Ver mis conversaciones
            </Button>
            <Button
                size="sm"
                class="pointer-coarse:h-10"
                :disabled="preview || acknowledging"
                data-attention-alert-acknowledge
                @click="emit('acknowledge')"
                :loading="acknowledging"
            >
                Entendido
            </Button>
        </footer>
    </article>
</template>
