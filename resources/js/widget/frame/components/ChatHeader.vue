<script setup lang="ts">
import { X } from '@lucide/vue';
import { computed } from 'vue';
import type { Appearance } from '../../protocol';

/**
 * Header of the widget: avatar, title, subtitle and who is attending (or
 * the team's availability), plus the close button (asks the host page to
 * close the panel; on phones the panel covers the whole screen).
 */
const props = defineProps<{
    appearance: Appearance;
    statusText: string | null;
    online: boolean;
    closable: boolean;
}>();

defineEmits<{ close: [] }>();

const initial = computed(
    () => props.appearance.title.trim().charAt(0).toUpperCase() || 'C',
);
</script>

<template>
    <header
        class="flex shrink-0 items-center gap-3 bg-(--cm-accent) px-[max(1rem,env(safe-area-inset-left))] pt-[max(0.75rem,env(safe-area-inset-top))] pb-3 text-(--cm-accent-fg)"
    >
        <img
            v-if="appearance.avatar"
            :src="appearance.avatar"
            alt=""
            class="size-10 shrink-0 rounded-full bg-white/20 object-cover"
        />
        <span
            v-else
            class="grid size-10 shrink-0 place-items-center rounded-full bg-white/20 text-lg font-semibold"
            aria-hidden="true"
            >{{ initial }}</span
        >
        <div class="min-w-0 flex-1">
            <h1 class="truncate text-base leading-tight font-semibold">
                {{ appearance.title }}
            </h1>
            <p
                v-if="appearance.subtitle"
                class="truncate text-sm leading-tight opacity-90"
            >
                {{ appearance.subtitle }}
            </p>
            <p
                v-if="statusText"
                class="mt-0.5 flex items-center gap-1.5 text-xs leading-tight opacity-90"
            >
                <span
                    class="inline-block size-2 shrink-0 rounded-full"
                    :class="online ? 'bg-emerald-400' : 'bg-gray-300'"
                    aria-hidden="true"
                />
                <span class="truncate">{{ statusText }}</span>
            </p>
        </div>
        <button
            v-if="closable"
            type="button"
            class="grid size-11 shrink-0 place-items-center rounded-full hover:bg-white/15 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-current"
            aria-label="Cerrar chat"
            @click="$emit('close')"
        >
            <X class="size-5" aria-hidden="true" />
        </button>
    </header>
</template>
