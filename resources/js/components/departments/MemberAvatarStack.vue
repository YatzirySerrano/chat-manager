<script setup lang="ts">
import { computed } from 'vue';
import DirectoryAvatar from '@/components/directory/DirectoryAvatar.vue';

/**
 * The first members of a department as overlapping avatars plus «+N» for
 * the rest. The names go in a tooltip and in the accessible label.
 */
const props = withDefaults(
    defineProps<{
        members: { id: number; name: string }[];
        /** Total members (the list may be only the first ones). */
        total: number;
        max?: number;
    }>(),
    { max: 4 },
);

const shown = computed(() => props.members.slice(0, props.max));
const rest = computed(() => Math.max(0, props.total - shown.value.length));

const label = computed(() => {
    if (props.total === 0) {
        return 'Sin miembros';
    }

    const names = shown.value.map((member) => member.name).join(', ');

    return rest.value > 0 ? `${names} y ${rest.value} más` : names;
});
</script>

<template>
    <span
        v-if="total > 0"
        class="inline-flex items-center"
        role="img"
        :aria-label="`Miembros: ${label}`"
        :title="label"
    >
        <DirectoryAvatar
            v-for="(member, position) in shown"
            :key="member.id"
            :name="member.name"
            size="sm"
            class="ring-2 ring-card"
            :class="position > 0 && '-ml-1.5'"
        />
        <span
            v-if="rest > 0"
            class="-ml-1.5 flex size-8 items-center justify-center rounded-full bg-muted text-xs font-semibold text-muted-foreground tabular-nums ring-2 ring-card"
            >+{{ rest }}</span
        >
    </span>
    <span v-else class="text-xs text-warning-soft-foreground"
        >Sin miembros</span
    >
</template>
