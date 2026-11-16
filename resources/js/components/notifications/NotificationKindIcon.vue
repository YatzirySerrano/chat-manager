<script setup lang="ts">
import { computed } from 'vue';
import {
    TONE_CLASSES,
    notificationKind,
    notificationTone,
} from '@/lib/notifications/kinds';
import { cn } from '@/lib/utils';
import type { AppNotification } from '@/types';

/**
 * The icon of a notification's kind on a small solid tinted square, in the
 * tone it has today (see notificationTone). Decorative: the title says it.
 *
 * - `md`: the leading square of a bell row or a toast (36 px).
 * - `badge`: the corner badge over an avatar (18 px, ringed).
 */
const props = withDefaults(
    defineProps<{
        item: Pick<AppNotification, 'kind' | 'level' | 'url' | 'accessible'>;
        size?: 'md' | 'sm' | 'badge';
    }>(),
    { size: 'md' },
);

const meta = computed(() => notificationKind(props.item));
const tone = computed(() => notificationTone(props.item));
</script>

<template>
    <span
        :class="
            cn(
                'inline-flex shrink-0 items-center justify-center',
                TONE_CLASSES[tone],
                size === 'md' && 'size-9 rounded-[0.625rem]',
                size === 'sm' && 'size-7 rounded-lg',
                size === 'badge' &&
                    'size-[1.125rem] rounded-[0.3125rem] ring-2 ring-popover',
            )
        "
        :data-tone="tone"
        aria-hidden="true"
    >
        <component
            :is="meta.icon"
            :class="
                size === 'badge'
                    ? 'size-[0.6875rem]'
                    : size === 'sm'
                      ? 'size-3.5'
                      : 'size-[1.0625rem]'
            "
            :stroke-width="size === 'badge' ? 2.5 : 2"
        />
    </span>
</template>
