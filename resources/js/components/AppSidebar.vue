<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useNavigation } from '@/composables/useNavigation';
import { dashboard } from '@/routes';

const { sections } = useNavigation();
</script>

<template>
    <Sidebar collapsible="icon">
        <SidebarHeader
            class="h-16 justify-center border-b border-sidebar-border px-3"
        >
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent class="gap-3 py-3" data-tour="shell.nav">
            <NavMain
                v-for="section in sections"
                :key="section.label"
                :items="section.items"
                :label="section.label"
            />
        </SidebarContent>

        <SidebarFooter class="border-t border-sidebar-border">
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
