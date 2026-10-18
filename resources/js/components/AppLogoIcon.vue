<script setup lang="ts">
import { MessagesSquare } from '@lucide/vue';
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <MessagesSquare
        :class="className"
        v-bind="$attrs"
        style="fill: none"
        aria-hidden="true"
    />
</template>
