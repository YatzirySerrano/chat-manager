<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';
import '@/components/admin/admin.css';

/**
 * A section of a settings page as one glass card: an icon tile, the title
 * and its description at the top, the controls in the body and the actions
 * in a bar at the bottom. Same slots and flags as FormSection, so a page can
 * switch from one to the other without touching its fields:
 *
 *   <SettingsSection :icon="User" title="Perfil" description="…">
 *       <div class="field-grid">…fields…</div>
 *       <template #footer><Button>Guardar</Button></template>
 *   </SettingsSection>
 *
 * Slots: default (controls), `aside` (extra content under the description),
 * `actions` (header, right side) and `footer`. `flush` removes the body
 * padding (lists that reach the edges); `bare` drops the card and keeps
 * only the heading, for content that already brings its own cards.
 */
const props = withDefaults(
    defineProps<{
        title: string;
        description?: string;
        icon?: LucideIcon;
        flush?: boolean;
        bare?: boolean;
        class?: HTMLAttributes['class'];
        bodyClass?: HTMLAttributes['class'];
    }>(),
    {
        description: undefined,
        icon: undefined,
        flush: false,
        bare: false,
        class: undefined,
        bodyClass: undefined,
    },
);
</script>

<template>
    <section
        data-slot="settings-section"
        :class="
            cn('min-w-0 enter-spring', !bare && 'admin-section', props.class)
        "
    >
        <header
            class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
            :class="bare ? 'mb-4' : 'px-4 pt-4 pb-1 sm:px-6 sm:pt-5'"
        >
            <div class="flex min-w-0 items-start gap-3">
                <span v-if="icon" class="admin-icon-tile size-10">
                    <component :is="icon" class="size-5" aria-hidden="true" />
                </span>
                <div class="min-w-0 space-y-1 pt-0.5">
                    <h2
                        class="font-display text-lg leading-snug font-semibold tracking-tight"
                    >
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
            </div>
            <div
                v-if="$slots.actions"
                class="flex shrink-0 flex-wrap items-center gap-2"
            >
                <slot name="actions" />
            </div>
        </header>

        <div v-if="bare" :class="cn('min-w-0 space-y-4', bodyClass)">
            <slot />
        </div>
        <template v-else>
            <div
                :class="
                    cn(
                        flush ? 'mt-3 border-t border-border/70' : 'p-4 sm:p-6',
                        bodyClass,
                    )
                "
            >
                <slot />
            </div>
            <footer
                v-if="$slots.footer"
                class="flex flex-wrap items-center gap-3 rounded-b-[inherit] border-t border-border/70 bg-surface-sunken/50 px-4 py-3 sm:px-6"
            >
                <slot name="footer" />
            </footer>
        </template>
    </section>
</template>
