<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { BellRing, Palette, ShieldCheck, UserRound } from '@lucide/vue';
import { ref, watch } from 'vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { useSlidingIndicator } from '@/composables/useSlidingIndicator';
import { toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editNotifications } from '@/routes/notifications/preferences';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const sections: NavItem[] = [
    { title: 'Perfil', href: editProfile(), icon: UserRound },
    { title: 'Seguridad', href: editSecurity(), icon: ShieldCheck },
    { title: 'Apariencia', href: editAppearance(), icon: Palette },
    { title: 'Notificaciones', href: editNotifications(), icon: BellRing },
];

const { isCurrentOrParentUrl } = useCurrentUrl();

/** The underline slides to the section that is open. */
const nav = ref<HTMLElement | null>(null);
const { style, animated, update } = useSlidingIndicator(
    nav,
    '[aria-current=page]',
);
const page = usePage();

watch(() => page.url, update, { flush: 'post' });
</script>

<template>
    <!--
      "Mi cuenta": full width. The sections are tabs under the title, and
      each page lays its blocks out with FormSection (description on the
      left, controls on the right).
    -->
    <div class="page-shell">
        <header class="space-y-1.5">
            <h1
                class="font-display text-[1.75rem] leading-[1.15] font-semibold sm:text-3xl"
            >
                Mi cuenta
            </h1>
            <p class="max-w-prose text-sm text-pretty text-muted-foreground">
                Tus datos, tu acceso y la forma en que la plataforma te avisa.
            </p>
        </header>

        <nav
            ref="nav"
            class="relative flex max-w-full gap-1 overflow-x-auto border-b"
            aria-label="Mi cuenta"
        >
            <Link
                v-for="item in sections"
                :key="toUrl(item.href)"
                :href="item.href"
                prefetch
                :aria-current="
                    isCurrentOrParentUrl(item.href) ? 'page' : undefined
                "
                class="group/tab inline-flex h-11 shrink-0 items-center gap-2 rounded-t-md px-3 text-sm font-medium whitespace-nowrap text-muted-foreground outline-none hover:bg-accent/60 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset aria-[current=page]:text-foreground"
            >
                <component
                    :is="item.icon"
                    class="size-4 transition-transform duration-(--duration-base) ease-(--ease-spring) group-hover/tab:scale-110 group-aria-[current=page]/tab:text-primary"
                    aria-hidden="true"
                />
                {{ item.title }}
            </Link>
            <span
                aria-hidden="true"
                class="pointer-events-none absolute top-0 left-0 border-b-2 border-primary"
                :class="
                    animated &&
                    'transition-[transform,width,opacity] duration-(--duration-panel) ease-(--ease-out-quint)'
                "
                :style="style"
            />
        </nav>

        <div class="flex min-w-0 flex-col gap-10 pt-2">
            <slot />
        </div>
    </div>
</template>
