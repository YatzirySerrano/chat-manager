<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import { Toaster } from '@/components/ui/sonner';
import { useToastClearance } from '@/composables/useToastClearance';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

// Toasts stay at the bottom but above a docked message composer.
const toastClearance = useToastClearance();
</script>

<template>
    <AppShell>
        <AppSidebar />
        <AppContent class="min-w-0 overflow-x-clip">
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <slot />
        </AppContent>
        <Toaster
            :offset="toastClearance.offset()"
            :mobile-offset="toastClearance.mobileOffset()"
        />
    </AppShell>
</template>
