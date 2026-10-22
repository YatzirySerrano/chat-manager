<script setup lang="ts">
import {
    ExternalLink,
    FileText,
    Image,
    Phone,
    Reply,
    Video,
} from '@lucide/vue';
import { computed } from 'vue';
import WhatsAppText from '@/components/WhatsAppText.vue';
import type { MessageTemplateSummary } from '@/types';

/**
 * How the customer sees a template in WhatsApp: header, body with its
 * variables (filled with the given values or Meta's examples), footer and
 * buttons. Text is rendered as text nodes only.
 */
const props = defineProps<{
    template: MessageTemplateSummary;
    values?: Record<string, string>;
}>();

const placeholder = /\{\{\s*([A-Za-z0-9_]+)\s*\}\}/g;

function fill(text: string | null, component: string): string {
    if (!text) {
        return '';
    }

    return text.replace(placeholder, (match: string, name: string) => {
        const key = `${component}.${name}`;
        const value =
            props.values?.[key] ??
            props.template.parameters.find((parameter) => parameter.key === key)
                ?.example;

        return value && value !== '' ? value : match;
    });
}

const header = computed(() => props.template.structure.header);
const mediaIcon = computed(() =>
    header.value?.format === 'IMAGE'
        ? Image
        : header.value?.format === 'VIDEO'
          ? Video
          : FileText,
);
</script>

<template>
    <div
        class="max-w-sm rounded-xl rounded-tl-sm border bg-card p-3 text-sm shadow-card"
    >
        <div
            v-if="header && header.format !== 'TEXT'"
            class="mb-2 flex h-28 items-center justify-center rounded-lg bg-muted text-muted-foreground"
        >
            <component :is="mediaIcon" class="size-8" aria-hidden="true" />
            <span class="sr-only">Encabezado multimedia</span>
        </div>
        <p v-else-if="header?.text" class="mb-1 font-semibold">
            {{ fill(header.text, 'header') }}
        </p>

        <p class="break-words whitespace-pre-wrap">
            <WhatsAppText :text="fill(template.structure.body, 'body')" />
        </p>

        <p
            v-if="template.structure.footer"
            class="mt-2 text-xs text-muted-foreground"
        >
            {{ template.structure.footer }}
        </p>

        <ul
            v-if="template.structure.buttons.length > 0"
            class="mt-3 grid gap-1 border-t pt-2"
        >
            <li
                v-for="(button, index) in template.structure.buttons"
                :key="index"
                class="flex items-center justify-center gap-1.5 py-1 text-sm font-medium text-primary"
            >
                <ExternalLink
                    v-if="button.type === 'URL'"
                    class="size-3.5"
                    aria-hidden="true"
                />
                <Phone
                    v-else-if="button.type === 'PHONE_NUMBER'"
                    class="size-3.5"
                    aria-hidden="true"
                />
                <Reply v-else class="size-3.5" aria-hidden="true" />
                {{ button.text }}
            </li>
        </ul>
    </div>
</template>
