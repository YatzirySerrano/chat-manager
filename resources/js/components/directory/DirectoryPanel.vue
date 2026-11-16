<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';
import './directory.css';

/**
 * The floating panel of a catalog list (contacts, quick replies, templates,
 * tags, categories, departments). Its `toolbar` (search, views, filters)
 * sticks under the shell's top bar on glass while the rows scroll beneath
 * it; a thin progress line runs under it while a filter loads (`busy`), and
 * the rows dim a little. `footer` holds the pagination.
 */
const props = withDefaults(
    defineProps<{
        busy?: boolean;
        class?: HTMLAttributes['class'];
        toolbarClass?: HTMLAttributes['class'];
        as?: 'section' | 'div';
    }>(),
    { busy: false, class: undefined, toolbarClass: undefined, as: 'section' },
);
</script>

<template>
    <component
        :is="props.as"
        :class="cn('dir-panel', props.class)"
        data-slot="surface-card"
    >
        <div
            v-if="$slots.toolbar"
            :class="cn('dir-toolbar p-3 sm:p-4', props.toolbarClass)"
        >
            <slot name="toolbar" />
            <span v-if="busy" class="dir-progress" aria-hidden="true" />
        </div>
        <div class="dir-body" :aria-busy="busy">
            <slot />
        </div>
        <footer v-if="$slots.footer" class="dir-footer px-4 py-3">
            <slot name="footer" />
        </footer>
    </component>
</template>
