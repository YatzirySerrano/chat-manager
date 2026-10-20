<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
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
            class="text-[11px] font-semibold tracking-wider text-muted-foreground/80 uppercase"
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
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
