<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

/**
 * A section of a settings page or a long form, laid out for the full width
 * of the workspace (`form-row` utility): its title and description on the
 * left, its controls in a panel on the right. Below `lg` both stack.
 *
 *   <FormSection title="Perfil" description="Tu nombre y tu correo.">
 *       <div class="field-grid">…fields…</div>
 *       <template #footer><Button>Guardar</Button></template>
 *   </FormSection>
 *
 * Slots: default (the controls), `aside` (extra content under the
 * description, e.g. a link or a status) and `footer` (actions, in a bar at
 * the bottom of the panel). `flush` removes the panel padding (tables,
 * lists that reach the edges); `bare` removes the panel itself when the
 * content already brings its own panels.
 */
const props = withDefaults(
    defineProps<{
        title: string;
        description?: string;
        flush?: boolean;
        bare?: boolean;
        class?: HTMLAttributes['class'];
    }>(),
    {
        description: undefined,
        flush: false,
        bare: false,
        class: undefined,
    },
);
</script>

<template>
    <section data-slot="form-section" :class="cn('form-row', props.class)">
        <div class="min-w-0 space-y-1.5 lg:sticky lg:top-22 lg:pt-1">
            <h2 class="font-display text-xl leading-snug font-semibold">
                {{ title }}
            </h2>
            <p
                v-if="description"
                class="max-w-prose text-sm text-pretty text-muted-foreground"
            >
                {{ description }}
            </p>
            <slot name="aside" />
        </div>
        <div v-if="bare" class="min-w-0 space-y-4">
            <slot />
        </div>
        <div v-else class="min-w-0 overflow-hidden rounded-xl border bg-card">
            <div :class="flush ? undefined : 'p-4 sm:p-6'">
                <slot />
            </div>
            <footer
                v-if="$slots.footer"
                class="flex flex-wrap items-center gap-3 border-t bg-surface-sunken/50 px-4 py-3 sm:px-6"
            >
                <slot name="footer" />
            </footer>
        </div>
    </section>
</template>
