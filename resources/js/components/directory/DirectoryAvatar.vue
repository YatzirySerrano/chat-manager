<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import type { HTMLAttributes } from 'vue';
import { getInitials } from '@/composables/useInitials';
import { userAvatarUrl } from '@/composables/useUserAvatar';
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
        /** A teammate: their own profile picture when `src` is not given. */
        userId?: number | null;
    }>(),
    {
        icon: undefined,
        color: null,
        size: 'md',
        shape: 'circle',
        tone: 'auto',
        class: undefined,
        src: null,
        userId: null,
    },
);

const failed = ref(false);
const photo = computed(() => props.src ?? userAvatarUrl(props.userId));

watch(
    () => [props.src, props.userId],
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
    md: 'size-9 text-[0.8125rem] [&_svg]:size-4',
    lg: 'size-12 text-base [&_svg]:size-5',
    xl: 'size-14 text-lg sm:size-16 sm:text-xl [&_svg]:size-7',
} as const;

const radius = computed(() => {
    if (props.shape === 'circle') {
        return 'rounded-full';
    }

    return props.size === 'xl'
        ? 'rounded-2xl'
        : props.size === 'lg'
          ? 'rounded-xl'
          : 'rounded-lg';
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
            v-if="photo && !failed"
            :src="photo"
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
