<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import type { HTMLAttributes } from 'vue';
import { getInitials } from '@/composables/useInitials';
import { isHexColor, isPaletteColor } from '@/lib/colors';
import { cn } from '@/lib/utils';
import './directory.css';

/**
 * The gradient tile of a directory record. People get their initials on a
 * gradient whose hue comes from the name, so each one keeps the same colors
 * everywhere; records with their own color (a tag, a category, a
 * department) pass it as `color` (any CSS color) and usually an `icon`.
 * Decorative: the row says the name in words.
 *
 * `src`: a person's profile picture (the WhatsApp one, a private copy
 * served by the platform). It covers the initials once loaded and falls
 * back to them when it is missing or fails to load.
 */
const props = withDefaults(
    defineProps<{
        name: string;
        icon?: LucideIcon;
        /** The record's color (palette key or #hex) instead of the name's hue. */
        color?: string | null;
        size?: 'sm' | 'md' | 'lg' | 'xl';
        shape?: 'circle' | 'square';
        tone?: 'auto' | 'muted' | 'accent';
        class?: HTMLAttributes['class'];
        /** Profile picture; the initials (or icon) are shown without it. */
        src?: string | null;
    }>(),
    {
        icon: undefined,
        color: null,
        size: 'md',
        shape: 'circle',
        tone: 'auto',
        class: undefined,
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

const hue = computed(() => {
    let hash = 0;

    for (let index = 0; index < props.name.length; index += 1) {
        hash = (hash * 31 + props.name.charCodeAt(index)) >>> 0;
    }

    return hash % 360;
});

const tile = computed(() => {
    if (!props.color) {
        return null;
    }

    if (isHexColor(props.color)) {
        return props.color;
    }

    return isPaletteColor(props.color)
        ? `var(--color-${props.color}-500)`
        : props.color;
});

const sizes = {
    sm: 'size-8 text-xs [&_svg]:size-4',
    md: 'size-10 text-sm [&_svg]:size-[1.125rem]',
    lg: 'size-14 text-lg [&_svg]:size-6',
    xl: 'size-20 text-2xl sm:size-24 sm:text-3xl [&_svg]:size-9',
} as const;

const radius = computed(() => {
    if (props.shape === 'circle') {
        return 'rounded-full';
    }

    return props.size === 'xl'
        ? 'rounded-3xl'
        : props.size === 'lg'
          ? 'rounded-2xl'
          : 'rounded-xl';
});
</script>

<template>
    <span
        :class="
            cn(
                'dir-avatar relative inline-flex shrink-0 items-center justify-center font-semibold tracking-tight select-none',
                sizes[size],
                radius,
                props.class,
            )
        "
        :style="tile ? { '--tile': tile } : { '--h': hue }"
        :data-tile="tile ? '' : undefined"
        :data-tone="tone === 'auto' ? undefined : tone"
        aria-hidden="true"
    >
        <component :is="icon" v-if="icon" />
        <template v-else>{{ getInitials(name) || '?' }}</template>
        <img
            v-if="src && !failed"
            :src="src"
            alt=""
            loading="lazy"
            decoding="async"
            referrerpolicy="no-referrer"
            :class="cn('absolute inset-0 size-full object-cover', radius)"
            data-dir-avatar-photo
            @error="failed = true"
        />
        <slot />
    </span>
</template>
