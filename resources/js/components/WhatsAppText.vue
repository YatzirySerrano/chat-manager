<script setup lang="ts">
import { computed } from 'vue';
import { parseWhatsAppText } from '@/lib/whatsappFormatting';

/**
 * Renders a WhatsApp message with its *bold*, _italic_, ~strike~ and
 * ```monospace``` formatting. Each segment is output through text
 * interpolation (never v-html), so the content cannot inject markup.
 * With `links`, web addresses (http/https only) become links that open in a
 * new tab without giving the destination access to this page.
 */
const props = withDefaults(
    defineProps<{
        text: string;
        links?: boolean;
    }>(),
    { links: false },
);

const URL_PATTERN = /(https?:\/\/[^\s<>"'`]+[^\s<>"'`.,;:!?)\]])/giu;

const segments = computed(() =>
    parseWhatsAppText(props.text).map((segment) => ({
        ...segment,
        parts:
            props.links && !segment.mono
                ? segment.text
                      .split(URL_PATTERN)
                      .filter((part) => part !== '')
                      .map((part) => ({
                          text: part,
                          href: /^https?:\/\//iu.test(part) ? part : null,
                      }))
                : [{ text: segment.text, href: null }],
    })),
);
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
                ><template
                    v-for="(part, partIndex) in segment.parts"
                    :key="partIndex"
                    ><a
                        v-if="part.href"
                        :href="part.href"
                        target="_blank"
                        rel="noopener noreferrer nofollow"
                        class="break-all text-primary underline underline-offset-2"
                        >{{ part.text }}</a
                    ><template v-else>{{ part.text }}</template></template
                ></span
            ></template
        ></span
    >
</template>
