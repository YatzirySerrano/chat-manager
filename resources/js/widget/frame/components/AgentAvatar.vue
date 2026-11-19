<script setup lang="ts">
import { Bot } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

/**
 * Round picture of who answers the visitor: the agent's photo (a signed
 * URL bound to the session), their initials while there is none or it
 * fails to load, or the bot icon for the virtual assistant.
 */
const props = defineProps<{
    name: string | null;
    url?: string | null;
    bot?: boolean;
}>();

const failed = ref(false);

watch(
    () => props.url,
    () => {
        failed.value = false;
    },
);

const initials = computed(() => {
    const words = (props.name ?? '').trim().split(/\s+/).filter(Boolean);

    return (
        words
            .slice(0, 2)
            .map((word) => word.charAt(0).toUpperCase())
            .join('') || '?'
    );
});
</script>

<template>
    <span
        class="grid shrink-0 place-items-center overflow-hidden rounded-full font-semibold"
        aria-hidden="true"
    >
        <Bot v-if="bot" class="size-[60%]" />
        <img
            v-else-if="url && !failed"
            :src="url"
            alt=""
            class="size-full object-cover"
            referrerpolicy="no-referrer"
            @error="failed = true"
        />
        <template v-else>{{ initials }}</template>
    </span>
</template>
