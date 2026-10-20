<script setup lang="ts">
import { TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import type { QuickReplyVariableOption } from '@/types';
import WhatsAppText from '@/components/WhatsAppText.vue';

/**
 * Outgoing chat bubble showing how a rendered quick reply will look, with a
 * notice for variables that had no value.
 */
const props = withDefaults(
    defineProps<{
        text: string;
        missing?: string[];
        variables?: QuickReplyVariableOption[];
        maxLength?: number;
        caption?: string;
    }>(),
    {
        missing: () => [],
        variables: () => [],
        maxLength: undefined,
        caption: undefined,
    },
);

const missingLabels = computed(() =>
    props.missing.map(
        (name) =>
            props.variables.find((variable) => variable.value === name)
                ?.label ?? `{${name}}`,
    ),
);

const length = computed(() => [...props.text].length);
const tooLong = computed(
    () => props.maxLength !== undefined && length.value > props.maxLength,
);
</script>

<template>
    <div class="grid gap-2">
        <div
            class="rounded-xl border bg-muted/50 bg-[radial-gradient(circle_at_1px_1px,var(--color-border)_1px,transparent_0)] [background-size:14px_14px] p-3"
        >
            <div class="flex justify-end">
                <div
                    class="relative max-w-[85%] rounded-2xl rounded-tr-sm bg-primary-soft px-3 py-2 text-sm text-foreground shadow-sm"
                >
                    <WhatsAppText v-if="text.trim() !== ''" :text="text" />
                    <span v-else class="text-muted-foreground italic">
                        El mensaje aparecerá aquí.
                    </span>
                </div>
            </div>
            <p
                v-if="caption"
                class="mt-2 text-right text-[11px] text-muted-foreground"
            >
                {{ caption }}
            </p>
        </div>

        <div
            v-if="missingLabels.length > 0"
            class="flex gap-2 rounded-lg bg-warning/10 p-2 text-xs text-foreground"
            role="status"
        >
            <TriangleAlert
                class="mt-0.5 size-3.5 shrink-0 text-warning"
                aria-hidden="true"
            />
            <p>
                Sin valor para:
                <strong>{{ missingLabels.join(', ') }}</strong
                >. Se dejará en blanco; revisa el texto antes de enviarlo.
            </p>
        </div>

        <p
            v-if="maxLength !== undefined"
            class="text-right text-xs"
            :class="tooLong ? 'text-destructive' : 'text-muted-foreground'"
        >
            {{ length }} / {{ maxLength }} caracteres
        </p>
    </div>
</template>
