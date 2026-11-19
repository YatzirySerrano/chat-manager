<script setup lang="ts">
import { useEventListener, watchDebounced } from '@vueuse/core';
import { computed, onBeforeUnmount, ref } from 'vue';
import { launcherIconSvg, launcherMetrics } from '@/widget/launcher';
import type { Appearance } from '@/widget/protocol';

/**
 * Live preview of the web chat widget: the real frame served by the
 * application in preview mode, updated while the form is edited by sending
 * it the unsaved appearance through postMessage. Messages only go to, and
 * are only accepted from, the application's own origin (the frame's).
 * Below it, the floating button as the loader draws it on the site (same
 * shared metrics and icons, resources/js/widget/launcher.ts).
 */
export type WebChatPreviewAppearance = Appearance;

const props = defineProps<{
    frameUrl: string;
    appearance: WebChatPreviewAppearance;
    dirty: boolean;
}>();

const iframe = ref<HTMLIFrameElement | null>(null);

/*
 * Light or dark visitor: the frame's «Sistema» theme follows `color-scheme`
 * of the iframe element (prefers-color-scheme inside it), so the preview
 * starts light whatever the administrator's own system uses. With dark mode
 * off the widget is always light and the toggle is hidden.
 */
const theme = ref<'light' | 'dark'>('light');
const darkAllowed = computed(() => props.appearance.dark_mode !== 'off');
const frameScheme = computed(() => (darkAllowed.value ? theme.value : 'light'));
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
            // A plain copy: Vue's reactive proxies (nested launcher_offset)
            // cannot be structured-cloned and postMessage would throw.
            payload: JSON.parse(JSON.stringify(props.appearance)),
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

const launcher = computed(() => {
    const a = props.appearance;
    const metrics = launcherMetrics(a.accent_color, a, a.launcher_label);

    return {
        metrics,
        icon: launcherIconSvg(a.launcher_icon),
        style: {
            background: metrics.background,
            color: metrics.color,
            height: `${metrics.height}px`,
            minWidth: `${metrics.height}px`,
            width: metrics.width !== null ? `${metrics.width}px` : 'auto',
            borderRadius: `${metrics.radius}px`,
            fontSize: `${metrics.fontSize}px`,
            padding: metrics.showLabel ? '0 16px' : '0',
            '--cm-icon': `${metrics.iconSize}px`,
            '--cm-pulse': metrics.background,
        },
        // The offsets, scaled to the small stage (at most 24 px).
        corner: {
            bottom: `${8 + Math.round(a.launcher_offset.y / 5)}px`,
            [a.position === 'left' ? 'left' : 'right']:
                `${8 + Math.round(a.launcher_offset.x / 5)}px`,
        },
    };
});
</script>

<template>
    <div class="grid gap-2">
        <div class="flex items-center justify-between gap-2">
            <h3 class="text-sm font-medium">Vista previa</h3>
            <div class="flex items-center gap-2">
                <span
                    v-if="dirty"
                    class="text-xs text-muted-foreground"
                    aria-live="polite"
                    >Sin guardar</span
                >
                <div
                    v-if="darkAllowed"
                    class="inline-flex rounded-md border bg-card p-0.5 text-xs"
                    role="group"
                    aria-label="Tema del visitante en la vista previa"
                >
                    <button
                        v-for="option in [
                            { value: 'light', label: 'Claro' },
                            { value: 'dark', label: 'Oscuro' },
                        ] as const"
                        :key="option.value"
                        type="button"
                        class="rounded px-2 py-0.5 font-medium transition-colors"
                        :class="
                            theme === option.value
                                ? 'bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:text-foreground'
                        "
                        :aria-pressed="theme === option.value"
                        @click="theme = option.value"
                    >
                        {{ option.label }}
                    </button>
                </div>
            </div>
        </div>
        <div
            class="relative h-[30rem] overflow-hidden rounded-lg border bg-muted/40"
        >
            <iframe
                ref="iframe"
                :src="previewUrl"
                title="Vista previa del chat web"
                class="size-full border-0"
                :style="{ colorScheme: frameScheme }"
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
        <div
            class="relative h-24 overflow-hidden rounded-lg border bg-muted/40"
            data-testid="web-chat-launcher-preview"
        >
            <span class="absolute top-2 left-2.5 text-xs text-muted-foreground"
                >Botón en tu sitio</span
            >
            <span
                class="absolute top-2 right-2.5 text-xs text-muted-foreground tabular-nums"
                :title="`Ventana del chat: ${appearance.panel.width} × ${appearance.panel.height} px`"
                >{{ appearance.panel.width }} ×
                {{ appearance.panel.height }}</span
            >
            <span
                v-if="appearance.launcher_enabled"
                class="cm-launcher absolute inline-flex max-w-[calc(100%-16px)] items-center justify-center gap-2 font-semibold whitespace-nowrap shadow-lg"
                :class="{ 'cm-launcher-pulse': appearance.launcher_pulse }"
                :style="{ ...launcher.style, ...launcher.corner }"
                aria-hidden="true"
            >
                <!-- The administrator's own image, or static SVG markup from the widget's icon set. -->
                <img
                    v-if="appearance.launcher_image"
                    :src="appearance.launcher_image"
                    alt=""
                    class="shrink-0 object-contain"
                    :style="{
                        width: `${launcher.metrics.imageSize}px`,
                        height: `${launcher.metrics.imageSize}px`,
                    }"
                />
                <span v-else class="contents" v-html="launcher.icon" />
                <span v-if="launcher.metrics.showLabel" class="truncate">{{
                    appearance.launcher_label
                }}</span>
            </span>
            <span
                v-else
                class="absolute inset-x-0 bottom-3 text-center text-xs text-muted-foreground"
                >Sin botón flotante</span
            >
        </div>
    </div>
</template>

<style scoped>
.cm-launcher :deep(svg) {
    width: var(--cm-icon);
    height: var(--cm-icon);
    flex: none;
}

.cm-launcher-pulse::after {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: inherit;
    animation: cm-preview-pulse 2s ease-out infinite;
    pointer-events: none;
}

@keyframes cm-preview-pulse {
    0% {
        box-shadow: 0 0 0 0 var(--cm-pulse);
        opacity: 0.55;
    }

    100% {
        box-shadow: 0 0 0 14px var(--cm-pulse);
        opacity: 0;
    }
}

@media (prefers-reduced-motion: reduce) {
    .cm-launcher-pulse::after {
        animation: none;
    }
}
</style>
