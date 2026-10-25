<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps<{
    icon?: LucideIcon;
    title: string;
    description?: string;
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <div
        :class="
            cn(
                'flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed bg-card/60 px-6 py-10 text-center',
                props.class,
            )
        "
    >
        <div
            v-if="icon"
            class="flex size-10 items-center justify-center rounded-lg bg-primary-soft text-primary-soft-foreground"
        >
            <component :is="icon" class="size-5" aria-hidden="true" />
        </div>
        <div class="max-w-sm space-y-1">
            <p class="text-sm font-semibold">{{ title }}</p>
            <p v-if="description" class="text-sm text-muted-foreground">
                {{ description }}
            </p>
        </div>
        <slot />
    </div>
</template>
