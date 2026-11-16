<script setup lang="ts">
import { History, Sparkle } from '@lucide/vue';
import { Spinner } from '@/components/ui/spinner';

/**
 * The top of the history: a button that loads older messages (they also
 * load on their own when scrolling up), or a quiet mark of where the
 * conversation began.
 */
withDefaults(
    defineProps<{
        hasMore: boolean;
        loading: boolean;
        /** There is history on screen (otherwise the empty state speaks). */
        hasItems: boolean;
        /** Personal chat colors: the mark keeps the theme's background. */
        custom?: boolean;
    }>(),
    { custom: false },
);

defineEmits<{ load: [] }>();
</script>

<template>
    <div class="flex justify-center py-2">
        <button
            v-if="hasMore"
            type="button"
            class="inline-flex h-8 items-center gap-1.5 rounded-full border bg-background/90 px-3.5 text-xs font-medium text-muted-foreground shadow-(--shadow-control) backdrop-blur-sm transition-[color,background-color,border-color] duration-(--duration-fast) outline-none hover:border-border-strong hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/45 disabled:cursor-progress pointer-coarse:h-10"
            :disabled="loading"
            :aria-busy="loading"
            @click="$emit('load')"
        >
            <Spinner v-if="loading" class="size-3.5" />
            <History v-else class="size-3.5" aria-hidden="true" />
            {{ loading ? 'Cargando mensajes…' : 'Cargar mensajes anteriores' }}
        </button>
        <p
            v-else-if="hasItems"
            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs text-muted-foreground"
            :class="custom && 'bg-background'"
        >
            <Sparkle class="size-3" aria-hidden="true" />
            Inicio de la conversación
        </p>
    </div>
</template>
