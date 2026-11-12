<script setup lang="ts">
type Props = {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
};

withDefaults(defineProps<Props>(), {
    variant: 'default',
});
</script>

<template>
    <header :class="variant === 'small' ? 'space-y-0.5' : 'mb-8 space-y-1'">
        <h2
            :class="
                variant === 'small'
                    ? 'font-display text-lg leading-snug font-semibold'
                    : 'font-display text-2xl font-semibold'
            "
        >
            {{ title }}
        </h2>
        <p
            v-if="description"
            class="max-w-prose text-sm text-pretty text-muted-foreground"
        >
            {{ description }}
        </p>
    </header>
</template>
