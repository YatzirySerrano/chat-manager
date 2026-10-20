<script setup lang="ts">
import { computed } from 'vue';
import { parseWhatsAppText } from '@/lib/whatsappFormatting';

/**
 * Renders a WhatsApp message with its *bold*, _italic_, ~strike~ and
 * ```monospace``` formatting. Each segment is output through text
 * interpolation (never v-html), so the content cannot inject markup.
 */
const props = defineProps<{
    text: string;
}>();

const segments = computed(() => parseWhatsAppText(props.text));
</script>

<template>
    <span class="break-words whitespace-pre-wrap"
        ><template v-for="(segment, index) in segments" :key="index"
            ><code
                v-if="segment.mono"
                class="rounded bg-black/5 px-1 font-mono text-[0.85em] dark:bg-white/10"
                >{{ segment.text }}</code
            ><span
                v-else
                :class="[
                    segment.bold && 'font-semibold',
                    segment.italic && 'italic',
                    segment.strike && 'line-through',
                ]"
                >{{ segment.text }}</span
            ></template
        ></span
    >
</template>
