<script setup lang="ts">
/**
 * Full-panel state of the widget (not authorized, offline, session
 * expired, identification needed, error) with an optional action.
 */
defineProps<{
    title: string;
    description?: string | null;
    action?: string | null;
}>();

defineEmits<{ action: [] }>();
</script>

<template>
    <div
        class="grid flex-1 place-content-center gap-3 p-6 text-center"
        role="status"
    >
        <p class="text-base font-semibold text-gray-900 dark:text-gray-100">
            {{ title }}
        </p>
        <p
            v-if="description"
            class="text-[15px] leading-relaxed whitespace-pre-line text-gray-600 dark:text-gray-400"
        >
            {{ description }}
        </p>
        <div v-if="action">
            <button
                type="button"
                class="min-h-11 rounded-xl bg-(--cm-accent) px-4 text-[15px] font-semibold text-(--cm-accent-fg) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--cm-ring)"
                @click="$emit('action')"
            >
                {{ action }}
            </button>
        </div>
    </div>
</template>
