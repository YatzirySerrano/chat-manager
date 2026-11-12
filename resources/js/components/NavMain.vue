<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import type { NavItem } from '@/types';

withDefaults(
    defineProps<{
        items: NavItem[];
        label?: string;
    }>(),
    { label: 'Plataforma' },
);

const { isCurrentOrParentUrl } = useCurrentUrl();

/** On phones the sidebar is a sheet over the page: close it after choosing. */
const { setOpenMobile } = useSidebar();
</script>

<template>
    <SidebarGroup class="relative px-3 py-1.5">
        <SidebarGroupLabel class="text-xs font-semibold text-sidebar-muted">
            {{ label }}
        </SidebarGroupLabel>
        <SidebarMenu class="gap-0.5">
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <!--
                  The active background is the sliding highlight of
                  AppSidebar, so the item itself only changes its text and
                  icon color. Links prefetch on hover: the page is usually
                  ready when the click lands.
                -->
                <SidebarMenuButton
                    as-child
                    :is-active="isCurrentOrParentUrl(item.href)"
                    :tooltip="item.title"
                    class="group/nav relative h-10 rounded-lg font-medium text-sidebar-foreground transition-colors hover:bg-sidebar-accent/55 hover:text-sidebar-accent-foreground active:bg-sidebar-accent/70 data-[active=true]:bg-transparent data-[active=true]:font-semibold data-[active=true]:text-sidebar-accent-foreground pointer-coarse:h-11 [&>svg]:size-4.5 [&>svg]:text-sidebar-muted [&>svg]:transition-[color,scale,rotate] [&>svg]:duration-(--duration-base) [&>svg]:ease-(--ease-spring) hover:[&>svg]:scale-110 hover:[&>svg]:-rotate-6 hover:[&>svg]:text-sidebar-accent-foreground data-[active=true]:[&>svg]:text-sidebar-primary"
                >
                    <Link
                        :href="item.href"
                        prefetch
                        @click="setOpenMobile(false)"
                    >
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
                <SidebarMenuBadge
                    v-if="item.badge"
                    class="rounded-full bg-sidebar-primary px-1.5 text-xs font-semibold text-sidebar-primary-foreground peer-hover/menu-button:text-sidebar-primary-foreground peer-data-[active=true]/menu-button:text-sidebar-primary-foreground peer-data-[size=default]/menu-button:top-2.5 pointer-coarse:peer-data-[size=default]/menu-button:top-3"
                    :aria-label="`${item.badge} sin leer`"
                >
                    {{ item.badge > 99 ? '99+' : item.badge }}
                </SidebarMenuBadge>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
