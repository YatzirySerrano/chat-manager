<script setup lang="ts">
import { CircleQuestionMark } from '@lucide/vue';
import { ref } from 'vue';
import ModuleHelpSheet from '@/components/help/ModuleHelpSheet.vue';
import { Button } from '@/components/ui/button';
import type { ModuleHelpKey } from '@/help';

/**
 * Trigger for the "¿Cómo funciona este módulo?" panel of a module.
 *
 * `iconOnly` keeps just the icon (with an accessible name and tooltip) for
 * tight toolbars such as the inbox list header. Attributes (class, etc.)
 * go to the button.
 */
defineOptions({ inheritAttrs: false });

withDefaults(
    defineProps<{
        module: ModuleHelpKey;
        label?: string;
        iconOnly?: boolean;
    }>(),
    {
        label: '¿Cómo funciona?',
        iconOnly: false,
    },
);

const open = ref(false);
</script>

<template>
    <span class="contents">
        <Button
            type="button"
            variant="ghost"
            :size="iconOnly ? 'icon-sm' : 'sm'"
            class="text-muted-foreground hover:text-foreground"
            :aria-label="iconOnly ? '¿Cómo funciona este módulo?' : undefined"
            :title="iconOnly ? '¿Cómo funciona este módulo?' : undefined"
            aria-haspopup="dialog"
            :aria-expanded="open"
            v-bind="$attrs"
            @click="open = true"
        >
            <CircleQuestionMark aria-hidden="true" />
            <span v-if="!iconOnly">{{ label }}</span>
        </Button>
        <ModuleHelpSheet v-model:open="open" :module="module" />
    </span>
</template>
