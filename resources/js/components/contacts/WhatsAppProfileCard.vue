<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    BadgeCheck,
    Building2,
    Clock,
    Globe,
    Info,
    Mail,
    MapPin,
    RefreshCw,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import SurfaceCard from '@/components/SurfaceCard.vue';
import { Button } from '@/components/ui/button';
import { formatDate, formatRelative } from '@/lib/format';
import type { WhatsAppProfile } from '@/types';

/**
 * «Perfil de WhatsApp»: what WhatsApp shares about the contact through a
 * WhatsApp Web (QR) connection: the «Info» (the "about" text and when it
 * was set) and, for business accounts, their public business profile. The
 * picture itself is shown by the avatar of the page or panel.
 *
 * WhatsApp Cloud API shares neither, so for those contacts nothing is
 * shown, except one muted line in the inbox panel. «Actualizar» asks
 * WhatsApp again (the server throttles it) and reloads the given props.
 */
const props = withDefaults(
    defineProps<{
        profile: WhatsAppProfile;
        /** Inertia props to reload after «Actualizar». */
        reload: string[];
        variant?: 'panel' | 'page';
    }>(),
    { variant: 'panel' },
);

const refreshing = ref(false);

const business = computed(() => props.profile.business);
const hasInfo = computed(
    () => props.profile.about !== null || business.value !== null,
);

function refresh(): void {
    const url = props.profile.refresh_url;

    if (!url || refreshing.value) {
        return;
    }

    router.post(
        url,
        {},
        {
            preserveScroll: true,
            preserveState: true,
            only: props.reload,
            onStart: () => (refreshing.value = true),
            onFinish: () => (refreshing.value = false),
        },
    );
}

/** Only web addresses become links (the server already filters them). */
function safeUrl(url: string): string | null {
    return /^https?:\/\//i.test(url) ? url : null;
}

function siteLabel(url: string): string {
    return url.replace(/^https?:\/\//i, '').replace(/\/$/, '');
}
</script>

<template>
    <!-- WhatsApp Cloud API: one muted line in the panel, nothing on the page. -->
    <p
        v-if="profile.source === 'cloud_api' && variant === 'panel'"
        class="px-1 text-xs text-muted-foreground"
        data-whatsapp-profile="cloud_api"
    >
        WhatsApp no comparte la foto ni la info en conexiones de la API oficial.
    </p>

    <component
        :is="variant === 'page' ? SurfaceCard : 'section'"
        v-else-if="profile.source === 'whatsapp_web'"
        v-bind="
            variant === 'page'
                ? { title: 'Perfil de WhatsApp', bodyClass: 'p-5' }
                : {
                      class: 'grid gap-3 rounded-2xl border border-border/70 bg-card px-4 pt-4 pb-5 text-sm shadow-(--shadow-card)',
                  }
        "
        data-whatsapp-profile="whatsapp_web"
    >
        <template v-if="variant === 'page'" #actions>
            <Button
                v-if="profile.can_refresh"
                variant="outline"
                size="sm"
                class="rounded-full"
                :disabled="refreshing"
                :aria-busy="refreshing"
                data-whatsapp-profile-refresh
                @click="refresh"
            >
                <RefreshCw
                    :class="refreshing && 'motion-safe:animate-spin'"
                    aria-hidden="true"
                />
                {{ refreshing ? 'Actualizando…' : 'Actualizar' }}
            </Button>
        </template>

        <div v-if="variant === 'panel'" class="flex items-center gap-2">
            <h3 class="flex flex-1 items-center gap-1.5 section-title">
                <Info class="size-3.5" aria-hidden="true" />
                Perfil de WhatsApp
            </h3>
            <Button
                v-if="profile.can_refresh"
                variant="ghost"
                size="sm"
                class="-my-1 h-7 rounded-full px-2.5 text-xs pointer-coarse:h-10"
                :disabled="refreshing"
                :aria-busy="refreshing"
                title="Volver a leer la foto y la info de WhatsApp"
                data-whatsapp-profile-refresh
                @click="refresh"
            >
                <RefreshCw
                    class="size-3.5"
                    :class="refreshing && 'motion-safe:animate-spin'"
                    aria-hidden="true"
                />
                {{ refreshing ? 'Actualizando…' : 'Actualizar' }}
            </Button>
        </div>

        <div class="grid gap-3 text-sm">
            <div v-if="profile.about" class="grid gap-0.5" data-whatsapp-about>
                <p class="text-xs text-muted-foreground">Info</p>
                <p class="break-words whitespace-pre-line">
                    {{ profile.about }}
                </p>
                <p
                    v-if="profile.about_set_at"
                    class="text-xs text-muted-foreground"
                >
                    Desde el {{ formatDate(profile.about_set_at) }}
                </p>
            </div>

            <div v-if="business" class="grid gap-2" data-whatsapp-business>
                <p class="flex flex-wrap items-center gap-1.5">
                    <span
                        class="inline-flex items-center gap-1 rounded-full bg-success-soft px-2 py-0.5 text-xs font-medium text-success-soft-foreground"
                    >
                        <BadgeCheck class="size-3.5" aria-hidden="true" />
                        Cuenta de empresa
                    </span>
                    <span
                        v-if="business.category"
                        class="inline-flex items-center gap-1 rounded-full bg-muted/70 px-2 py-0.5 text-xs text-muted-foreground"
                    >
                        <Building2 class="size-3.5" aria-hidden="true" />
                        {{ business.category }}
                    </span>
                </p>
                <p
                    v-if="business.description"
                    class="break-words whitespace-pre-line"
                >
                    {{ business.description }}
                </p>
                <dl class="grid gap-1.5">
                    <div v-if="business.email" class="flex items-start gap-2">
                        <dt class="sr-only">Correo</dt>
                        <Mail
                            class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <dd class="min-w-0 break-all">{{ business.email }}</dd>
                    </div>
                    <div
                        v-for="site in business.website"
                        :key="site"
                        class="flex items-start gap-2"
                    >
                        <dt class="sr-only">Sitio web</dt>
                        <Globe
                            class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <dd class="min-w-0">
                            <a
                                v-if="safeUrl(site)"
                                :href="safeUrl(site) ?? undefined"
                                target="_blank"
                                rel="noopener noreferrer nofollow"
                                class="break-all text-primary underline-offset-2 hover:underline"
                                >{{ siteLabel(site) }}</a
                            >
                        </dd>
                    </div>
                    <div v-if="business.address" class="flex items-start gap-2">
                        <dt class="sr-only">Dirección</dt>
                        <MapPin
                            class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <dd class="min-w-0 break-words">
                            {{ business.address }}
                        </dd>
                    </div>
                    <div v-if="business.hours" class="flex items-start gap-2">
                        <dt class="sr-only">Horario</dt>
                        <Clock
                            class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <dd class="min-w-0 flex-1">
                            <ul class="grid gap-0.5">
                                <li
                                    v-for="day in business.hours.days"
                                    :key="day.day"
                                    class="flex justify-between gap-3"
                                >
                                    <span class="text-muted-foreground">{{
                                        day.label
                                    }}</span>
                                    <span class="text-right tabular-nums">{{
                                        day.hours
                                    }}</span>
                                </li>
                            </ul>
                        </dd>
                    </div>
                </dl>
            </div>

            <p v-if="!hasInfo" class="text-muted-foreground">
                {{
                    profile.fetched_at
                        ? 'Este contacto no comparte su info en WhatsApp.'
                        : 'Todavía no se ha leído el perfil de WhatsApp de este contacto.'
                }}
            </p>

            <p class="text-xs text-muted-foreground" data-whatsapp-fetched-at>
                {{
                    profile.fetched_at
                        ? `Actualizado ${formatRelative(profile.fetched_at)}`
                        : 'Sin actualizar'
                }}
            </p>
        </div>
    </component>
</template>
