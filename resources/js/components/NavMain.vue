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
    <SidebarGroup class="px-3 py-1">
        <SidebarGroupLabel
            class="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase"
        >
            {{ label }}
        </SidebarGroupLabel>
        <SidebarMenu class="gap-0.5">
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <SidebarMenuButton
                    as-child
                    :is-active="isCurrentOrParentUrl(item.href)"
                    :tooltip="item.title"
                    class="h-9 rounded-lg font-medium text-sidebar-foreground transition-colors hover:bg-sidebar-accent data-[active=true]:bg-primary-soft data-[active=true]:text-primary-soft-foreground [&>svg]:text-muted-foreground data-[active=true]:[&>svg]:text-primary-soft-foreground"
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
                    class="rounded-full bg-primary px-1.5 text-[11px] text-primary-foreground peer-hover/menu-button:text-primary-foreground peer-data-[active=true]/menu-button:text-primary-foreground"
                    :aria-label="`${item.badge} sin leer`"
                >
                    {{ item.badge > 99 ? '99+' : item.badge }}
                </SidebarMenuBadge>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
