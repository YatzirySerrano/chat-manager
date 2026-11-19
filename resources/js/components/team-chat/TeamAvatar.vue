<script setup lang="ts">
import { UsersRound } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { personTint } from '@/components/team-chat/teamChatFormat';
import { getInitials } from '@/composables/useInitials';
import { userAvatarUrl } from '@/composables/useUserAvatar';
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
        /** The person's profile picture (by id) over the initials. */
        userId?: number | null;
        src?: string | null;
    }>(),
    {
        group: false,
        deleted: false,
        online: false,
        size: 'md',
        userId: null,
        src: null,
    },
);

const failed = ref(false);

watch(
    () => [props.src, props.userId],
    () => {
        failed.value = false;
    },
);

const photo = computed(() =>
    props.group || props.deleted || failed.value
        ? null
        : (props.src ?? userAvatarUrl(props.userId)),
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
        <img
            v-if="photo"
            :src="photo"
            alt=""
            loading="lazy"
            decoding="async"
            class="absolute inset-0 size-full rounded-full object-cover"
            @error="failed = true"
        />
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
