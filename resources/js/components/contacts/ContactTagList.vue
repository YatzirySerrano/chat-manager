<script setup lang="ts">
import TagBadge from '@/components/tags/TagBadge.vue';
import type { ContactTag } from '@/types';

/**
 * Compact read-only list of a contact's tags for tables. Deleted tags stay
 * visible (muted).
 */
withDefaults(
    defineProps<{
        tags: ContactTag[];
        limit?: number;
    }>(),
    { limit: undefined },
);
</script>

<template>
    <div v-if="tags.length > 0" class="flex flex-wrap gap-1">
        <TagBadge
            v-for="tag in limit ? tags.slice(0, limit) : tags"
            :key="tag.id"
            :name="tag.name"
            :color="tag.color"
            :deleted="!tag.is_active"
        />
        <span
            v-if="limit && tags.length > limit"
            class="self-center text-xs text-muted-foreground"
        >
            +{{ tags.length - limit }}
        </span>
    </div>
    <span v-else class="text-xs text-muted-foreground">Sin etiquetas</span>
</template>
