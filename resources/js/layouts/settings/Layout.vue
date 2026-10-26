<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editNotifications } from '@/routes/notifications/preferences';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Perfil',
        href: editProfile(),
    },
    {
        title: 'Seguridad',
        href: editSecurity(),
    },
    {
        title: 'Apariencia',
        href: editAppearance(),
    },
    {
        title: 'Notificaciones',
        href: editNotifications(),
    },
];

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <div class="page-shell [--page-max:64rem]">
        <header class="space-y-1">
            <h1 class="text-2xl font-semibold tracking-tight">Mi cuenta</h1>
        </header>
        <div class="flex flex-col gap-6 lg:flex-row lg:gap-12">
            <aside class="w-full lg:w-48 lg:shrink-0">
                <nav
                    class="flex flex-wrap gap-1 lg:flex-col"
                    aria-label="Mi cuenta"
                >
                    <Button
                        v-for="item in sidebarNavItems"
                        :key="toUrl(item.href)"
                        variant="ghost"
                        :class="[
                            'justify-start lg:w-full max-lg:pointer-coarse:h-11',
                            isCurrentOrParentUrl(item.href)
                                ? 'bg-primary-soft text-primary-soft-foreground hover:bg-primary-soft hover:text-primary-soft-foreground'
                                : 'text-muted-foreground hover:text-foreground',
                        ]"
                        as-child
                    >
                        <Link
                            :href="item.href"
                            :aria-current="
                                isCurrentOrParentUrl(item.href)
                                    ? 'page'
                                    : undefined
                            "
                        >
                            <component :is="item.icon" class="h-4 w-4" />
                            {{ item.title }}
                        </Link>
                    </Button>
                </nav>
            </aside>

            <Separator class="lg:hidden" />

            <div class="min-w-0 flex-1 md:max-w-2xl">
                <section class="max-w-xl space-y-12">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
