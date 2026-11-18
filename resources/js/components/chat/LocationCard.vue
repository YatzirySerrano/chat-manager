<script setup lang="ts">
import { ExternalLink, Globe, MapPin, Radio } from '@lucide/vue';
import { computed, ref } from 'vue';
import { googleMapsUrl } from '@/lib/conversations/media';
import { linkDomain } from '@/lib/links';

/**
 * A place shared in a chat: a shared location (WhatsApp) or a Google Maps
 * link written in a message. The map area and «Abrir en Google Maps» open
 * the place in a new tab (or the Maps app on a phone). No map service is
 * called from the browser: the picture is the one the server kept for a
 * Maps link (never hot-linked), or a drawn map with a pin otherwise.
 * With `custom` (personal chat colors) boxes and links use the bubble's
 * text color, the only one whose contrast is guaranteed.
 */
const props = withDefaults(
    defineProps<{
        name?: string | null;
        address?: string | null;
        latitude?: number | null;
        longitude?: number | null;
        live?: boolean;
        /** The place's own web page. */
        website?: string | null;
        /** Text sent with the location. */
        comment?: string | null;
        /** Where «Abrir» goes when it is not the point itself (the Maps link as written). */
        href?: string | null;
        /** Map picture kept by the server (link previews). */
        image?: string | null;
        custom?: boolean;
    }>(),
    {
        name: null,
        address: null,
        latitude: null,
        longitude: null,
        live: false,
        website: null,
        comment: null,
        href: null,
        image: null,
        custom: false,
    },
);

const located = computed(
    () =>
        typeof props.latitude === 'number' &&
        typeof props.longitude === 'number' &&
        Number.isFinite(props.latitude) &&
        Number.isFinite(props.longitude),
);

const mapsUrl = computed(() =>
    located.value
        ? googleMapsUrl(props.latitude as number, props.longitude as number)
        : (props.href ?? null),
);

const coordinates = computed(() =>
    located.value
        ? `${(props.latitude as number).toFixed(5)}, ${(props.longitude as number).toFixed(5)}`
        : null,
);

const title = computed(
    () =>
        props.name ??
        (props.live ? 'Ubicación en tiempo real' : 'Ubicación compartida'),
);

const websiteDomain = computed(() =>
    props.website ? linkDomain(props.website) : null,
);

/* A picture that fails to load falls back to the drawn map. */
const imageFailed = ref(false);
const showImage = computed(() => Boolean(props.image) && !imageFailed.value);

const secondary = computed(() => (props.custom ? '' : 'text-muted-foreground'));
</script>

<template>
    <div
        class="w-72 max-w-full overflow-hidden rounded-xl border"
        :class="custom ? 'border-current/30' : 'bg-background/60'"
        data-location-card
    >
        <component
            :is="mapsUrl ? 'a' : 'div'"
            :href="mapsUrl ?? undefined"
            :target="mapsUrl ? '_blank' : undefined"
            :rel="mapsUrl ? 'noopener noreferrer nofollow' : undefined"
            class="relative block h-28 overflow-hidden outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
            :class="!showImage && 'location-card-map'"
            :aria-label="mapsUrl ? `Abrir ${title} en Google Maps` : undefined"
            tabindex="-1"
            data-location-map
        >
            <img
                v-if="showImage"
                :src="image ?? ''"
                alt=""
                loading="lazy"
                decoding="async"
                class="size-full object-cover"
                @error="imageFailed = true"
            />
            <span
                class="absolute inset-0 grid place-items-center"
                aria-hidden="true"
            >
                <span
                    class="grid size-10 -translate-y-1 place-items-center rounded-full shadow-md ring-4"
                    :class="
                        custom
                            ? 'bg-current/15 ring-current/20'
                            : 'bg-destructive text-destructive-foreground ring-destructive/25'
                    "
                >
                    <Radio v-if="live" class="size-5" />
                    <MapPin v-else class="size-5" />
                </span>
            </span>
        </component>

        <div class="grid gap-0.5 p-2.5">
            <p class="font-medium [overflow-wrap:anywhere]">{{ title }}</p>
            <p
                v-if="address"
                class="text-xs [overflow-wrap:anywhere]"
                :class="secondary"
            >
                {{ address }}
            </p>
            <p
                v-if="coordinates"
                class="text-xs tabular-nums"
                :class="secondary"
            >
                {{ coordinates }}
            </p>
            <p v-else-if="!href" class="text-xs" :class="secondary">
                El mensaje llegó sin coordenadas.
            </p>
            <a
                v-if="website && websiteDomain"
                :href="website"
                target="_blank"
                rel="noopener noreferrer nofollow"
                class="inline-flex w-fit max-w-full items-center gap-1 text-xs underline underline-offset-2"
                :class="custom ? 'font-medium' : 'text-primary'"
            >
                <Globe class="size-3 shrink-0" aria-hidden="true" />
                <span class="truncate">{{ websiteDomain }}</span>
            </a>
            <p
                v-if="comment"
                class="mt-1 text-sm [overflow-wrap:anywhere] whitespace-pre-line"
            >
                {{ comment }}
            </p>
        </div>

        <a
            v-if="mapsUrl"
            :href="mapsUrl"
            target="_blank"
            rel="noopener noreferrer nofollow"
            class="flex items-center justify-center gap-1.5 border-t px-3 py-2 text-xs font-semibold transition-colors duration-(--duration-fast) outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset pointer-coarse:py-3"
            :class="
                custom
                    ? 'border-current/30 hover:underline'
                    : 'text-primary hover:bg-accent/60'
            "
            data-location-link
        >
            <ExternalLink class="size-3.5" aria-hidden="true" />
            Abrir en Google Maps
        </a>
    </div>
</template>

<style scoped>
/* A drawn street map (no map service): a grid of streets and two avenues,
   in the surrounding text color so it reads in light, dark and personal
   chat colors alike. */
.location-card-map {
    background-color: color-mix(in oklab, currentColor 6%, transparent);
    background-image:
        linear-gradient(
            115deg,
            transparent 46%,
            color-mix(in oklab, currentColor 16%, transparent) 46% 50%,
            transparent 50%
        ),
        linear-gradient(
            20deg,
            transparent 60%,
            color-mix(in oklab, currentColor 13%, transparent) 60% 63%,
            transparent 63%
        ),
        repeating-linear-gradient(
            0deg,
            transparent 0 22px,
            color-mix(in oklab, currentColor 8%, transparent) 22px 24px
        ),
        repeating-linear-gradient(
            90deg,
            transparent 0 30px,
            color-mix(in oklab, currentColor 8%, transparent) 30px 32px
        );
}
</style>
