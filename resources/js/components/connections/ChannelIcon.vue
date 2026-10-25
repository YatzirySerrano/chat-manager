<script setup lang="ts">
import { Globe, MessageCircle } from '@lucide/vue';
import type { ConnectionChannel } from '@/types';

/**
 * Icon of a connection channel. Lucide has no brand icons, so Messenger and
 * Instagram use simple outline glyphs drawn like the rest of the icon set
 * (24×24, currentColor stroke); size and color come from the class.
 */
defineProps<{ channel: ConnectionChannel }>();
</script>

<template>
    <MessageCircle v-if="channel === 'whatsapp'" />
    <Globe v-else-if="channel === 'web_chat'" />
    <svg
        v-else-if="channel === 'messenger'"
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
    >
        <path
            d="M12 2.5c-5.3 0-9.5 3.9-9.5 8.8 0 2.7 1.3 5.1 3.5 6.7v3.5l3.2-1.8c.9.3 1.8.4 2.8.4 5.3 0 9.5-3.9 9.5-8.8S17.3 2.5 12 2.5Z"
        />
        <path d="m7 13.5 3.3-3.5 2.6 2.4 4.1-3.9" />
    </svg>
    <svg
        v-else
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
    >
        <rect x="2.5" y="2.5" width="19" height="19" rx="5" />
        <circle cx="12" cy="12" r="4" />
        <path d="M17.5 6.5h.01" />
    </svg>
</template>
