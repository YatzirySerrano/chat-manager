<script setup lang="ts">
import { useEventListener, watchDebounced } from '@vueuse/core';
import { computed, onBeforeUnmount, ref } from 'vue';

/**
 * Live preview of the web chat widget: the real frame served by the
 * application in preview mode, updated while the form is edited by sending
 * it the unsaved appearance through postMessage. Messages only go to, and
 * are only accepted from, the application's own origin (the frame's).
 */
export type WebChatPreviewAppearance = {
    title: string;
    subtitle: string | null;
    welcome_text: string | null;
    offline_text: string | null;
    accent_color: string;
    position: 'right' | 'left';
    launcher_enabled: boolean;
    launcher_label: string | null;
    avatar: string | null;
};

const props = defineProps<{
    frameUrl: string;
    appearance: WebChatPreviewAppearance;
    dirty: boolean;
}>();

const iframe = ref<HTMLIFrameElement | null>(null);
const state = ref<'loading' | 'ready' | 'unavailable'>('loading');
let unavailableTimer: ReturnType<typeof setTimeout> | undefined;

const previewUrl = computed(() => {
    const url = new URL(props.frameUrl, window.location.origin);
    url.searchParams.set('preview', '1');

    return url.toString();
});

const frameOrigin = computed(
    () => new URL(props.frameUrl, window.location.origin).origin,
);

function sendAppearance(): void {
    const target = iframe.value?.contentWindow;

    if (!target) {
        return;
    }

    target.postMessage(
        {
            v: 1,
            source: 'chat-manager',
            type: 'cm:preview-appearance',
            payload: { ...props.appearance },
        },
        frameOrigin.value,
    );
}

function onLoad(): void {
    sendAppearance();
    clearTimeout(unavailableTimer);
    unavailableTimer = setTimeout(() => {
        if (state.value !== 'ready') {
            state.value = 'unavailable';
        }
    }, 4000);
}

useEventListener(window, 'message', (event: MessageEvent) => {
    if (
        event.source !== iframe.value?.contentWindow ||
        event.origin !== frameOrigin.value
    ) {
        return;
    }

    const data = event.data as { source?: string; type?: string } | null;

    if (data?.source !== 'chat-manager') {
        return;
    }

    if (data.type === 'cm:ready') {
        state.value = 'ready';
        sendAppearance();
    }
});

watchDebounced(() => props.appearance, sendAppearance, {
    debounce: 150,
    deep: true,
});

onBeforeUnmount(() => clearTimeout(unavailableTimer));
</script>

<template>
    <div class="grid gap-2">
        <div class="flex items-center justify-between gap-2">
            <h3 class="text-sm font-medium">Vista previa</h3>
            <span
                v-if="dirty"
                class="text-xs text-muted-foreground"
                aria-live="polite"
                >Con cambios sin guardar</span
            >
        </div>
        <div
            class="relative h-[32rem] overflow-hidden rounded-lg border bg-muted/40"
        >
            <iframe
                ref="iframe"
                :src="previewUrl"
                title="Vista previa del chat web"
                class="size-full border-0"
                sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-popups-to-escape-sandbox"
                referrerpolicy="origin"
                allow=""
                loading="lazy"
                data-testid="web-chat-preview"
                @load="onLoad"
            />
            <div
                v-if="state === 'loading'"
                class="pointer-events-none absolute inset-0 grid place-items-center"
                aria-hidden="true"
            >
                <div class="grid w-2/3 animate-pulse gap-2">
                    <div class="h-10 rounded-lg bg-muted" />
                    <div class="h-4 w-3/4 rounded bg-muted" />
                    <div class="h-4 w-1/2 rounded bg-muted" />
                </div>
            </div>
            <p
                v-else-if="state === 'unavailable'"
                class="absolute inset-x-3 bottom-3 rounded-lg bg-background/95 p-3 text-xs text-muted-foreground shadow-card"
                role="status"
            >
                La vista previa no respondió. Si acabas de instalar la
                plataforma, compila el widget (npm run build) y recarga la
                página.
            </p>
        </div>
        <p class="text-xs text-muted-foreground">
            Así verán el chat tus visitantes. Los mensajes de la vista previa no
            se envían a nadie.
        </p>
    </div>
</template>
