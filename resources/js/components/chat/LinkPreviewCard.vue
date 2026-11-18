<script setup lang="ts">
import { Globe } from '@lucide/vue';
import { useIntersectionObserver } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import LocationCard from '@/components/chat/LocationCard.vue';
import { isMapsUrl, linkDomain } from '@/lib/links';
import { loadLinkPreview } from '@/lib/linkPreviews';
import type { LinkPreviewData } from '@/lib/linkPreviews';

/**
 * The card under a message with a link: the page's title, description,
 * site and picture (Open Graph, read by the server only when the bubble
 * comes into view), opening the link in a new tab. A Google Maps link is
 * drawn as a place (LocationCard) right away, with its point once the
 * server has read it. Without anything to show (the site gave nothing,
 * it could not be read, or the message is not stored yet) only the link
 * in the text remains.
 */
const props = withDefaults(
    defineProps<{
        /** The first address of the message, as the chat sees it. */
        url: string;
        /** The message's preview endpoint; null: not asked (unsaved message). */
        endpoint: string | null;
        /** Personal chat colors: boxes and text in the bubble's text color. */
        custom?: boolean;
    }>(),
    { custom: false },
);

const root = ref<HTMLElement | null>(null);
const preview = ref<LinkPreviewData | null>(null);
const asked = ref<string | null>(null);

function ask(): void {
    const key = props.endpoint ? `${props.endpoint} ${props.url}` : null;

    if (!props.endpoint || key === asked.value) {
        return;
    }

    asked.value = key;
    const endpoint = props.endpoint;
    const url = props.url;

    void loadLinkPreview(endpoint, url).then((data) => {
        if (props.endpoint === endpoint && props.url === url) {
            preview.value = data;
        }
    });
}

const visible = ref(false);
const { stop } = useIntersectionObserver(
    root,
    ([entry]) => {
        if (entry?.isIntersecting) {
            visible.value = true;
            stop();
        }
    },
    { rootMargin: '200px' },
);

watch(
    () => [visible.value, props.endpoint, props.url] as const,
    ([shown, endpoint, url]) => {
        if (shown && `${endpoint} ${url}` !== asked.value) {
            preview.value = null;
            ask();
        }
    },
);

const maps = computed(() => preview.value?.maps ?? isMapsUrl(props.url));

/* Google names a place «Nombre · Dirección». */
const place = computed(() => {
    const title = preview.value?.title ?? null;
    const [name, ...rest] = (title ?? '').split(' · ');

    return {
        name: name?.trim() || null,
        address: rest.join(' · ').trim() || preview.value?.description || null,
    };
});

const ready = computed(() =>
    preview.value?.status === 'ready' &&
    (preview.value.title ||
        preview.value.description ||
        preview.value.image_url)
        ? preview.value
        : null,
);

const domain = computed(
    () => ready.value?.domain ?? linkDomain(props.url) ?? props.url,
);

/* A wide picture goes on top; a small or square one (a logo) beside the text. */
const wide = computed(() => {
    const width = ready.value?.image_width ?? 0;
    const height = ready.value?.image_height ?? 0;

    return width >= 300 && height > 0 && width / height >= 1.3;
});

const imageFailed = ref(false);
const iconFailed = ref(false);
const image = computed(() =>
    ready.value?.image_url && !imageFailed.value ? ready.value.image_url : null,
);
const icon = computed(() =>
    ready.value?.icon_url && !iconFailed.value ? ready.value.icon_url : null,
);

const secondary = computed(() => (props.custom ? '' : 'text-muted-foreground'));
</script>

<template>
    <div ref="root" class="max-w-full min-w-0" data-link-preview-slot>
        <LocationCard
            v-if="maps"
            :name="place.name ?? 'Ubicación de Google Maps'"
            :address="place.address"
            :latitude="preview?.location?.latitude ?? null"
            :longitude="preview?.location?.longitude ?? null"
            :href="url"
            :image="preview?.image_url ?? null"
            :custom="custom"
            data-link-preview="maps"
        />
        <a
            v-else-if="ready"
            :href="url"
            target="_blank"
            rel="noopener noreferrer nofollow"
            class="block w-72 max-w-full overflow-hidden rounded-xl border transition-colors duration-(--duration-fast) outline-none focus-visible:ring-2 focus-visible:ring-ring"
            :class="
                custom
                    ? 'border-current/30 hover:border-current/60'
                    : 'bg-background/60 hover:bg-accent/60'
            "
            data-link-preview="page"
        >
            <img
                v-if="image && wide"
                :src="image"
                alt=""
                loading="lazy"
                decoding="async"
                class="aspect-[1.91/1] w-full object-cover"
                :width="ready.image_width ?? undefined"
                :height="ready.image_height ?? undefined"
                @error="imageFailed = true"
            />
            <div class="flex min-w-0 gap-2.5 p-2.5">
                <img
                    v-if="image && !wide"
                    :src="image"
                    alt=""
                    loading="lazy"
                    decoding="async"
                    class="size-14 shrink-0 rounded-lg object-cover"
                    @error="imageFailed = true"
                />
                <div class="grid min-w-0 flex-1 content-start gap-0.5">
                    <p
                        class="flex min-w-0 items-center gap-1.5 text-xs"
                        :class="secondary"
                    >
                        <img
                            v-if="icon"
                            :src="icon"
                            alt=""
                            loading="lazy"
                            class="size-4 shrink-0 rounded-sm"
                            @error="iconFailed = true"
                        />
                        <Globe
                            v-else
                            class="size-3.5 shrink-0"
                            aria-hidden="true"
                        />
                        <span class="truncate">{{
                            ready.site_name ?? domain
                        }}</span>
                    </p>
                    <p
                        v-if="ready.title"
                        class="line-clamp-2 font-medium [overflow-wrap:anywhere]"
                    >
                        {{ ready.title }}
                    </p>
                    <p
                        v-if="ready.description"
                        class="line-clamp-2 text-xs [overflow-wrap:anywhere]"
                        :class="secondary"
                    >
                        {{ ready.description }}
                    </p>
                    <p
                        v-if="ready.site_name"
                        class="truncate text-[0.7rem]"
                        :class="secondary"
                    >
                        {{ domain }}
                    </p>
                </div>
            </div>
        </a>
    </div>
</template>
