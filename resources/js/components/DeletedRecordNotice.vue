<script setup lang="ts">
import { Trash2 } from '@lucide/vue';
import { formatDateTime } from '@/lib/format';

/**
 * Banner of a detail page whose record is logically deleted: it is
 * read-only until restored, and its history is kept.
 */
defineProps<{
    /** "Este departamento está eliminado", "Esta conexión está eliminada"… */
    title: string;
    deletedAt?: string | null;
}>();
</script>

<template>
    <div
        class="flex gap-3 rounded-xl bg-destructive-soft px-4 py-3 text-sm"
        role="status"
    >
        <Trash2
            class="mt-0.5 size-4 shrink-0 text-destructive"
            aria-hidden="true"
        />
        <div class="space-y-0.5">
            <p class="font-medium text-foreground">
                {{ title
                }}<template v-if="deletedAt">
                    desde el {{ formatDateTime(deletedAt) }}</template
                >.
            </p>
            <p class="text-muted-foreground">
                <slot>
                    Se conserva su historial y puede restaurarse. Mientras tanto
                    no puede modificarse.
                </slot>
            </p>
        </div>
    </div>
</template>
