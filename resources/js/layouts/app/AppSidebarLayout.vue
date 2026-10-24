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

/**
 * Every toast gets a visible close button: swiping or waiting is not
 * possible with a screen reader or on some touch screens. Closing a toast
 * only hides it; a notification is marked read only when it is opened.
 * The button looks 24 px wide, and its ::after extends the touch target.
 */
const toastOptions = {
    closeButtonAriaLabel: 'Cerrar notificación',
    classes: {
        closeButton:
            "size-6! border-border! bg-popover! text-popover-foreground! hover:bg-accent! focus-visible:ring-2 focus-visible:ring-ring after:absolute after:-inset-2.5 after:content-[''] pointer-coarse:size-8!",
    },
};
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
            close-button
            close-button-position="top-left"
            container-aria-label="Notificaciones"
            :toast-options="toastOptions"
        />
    </AppShell>
</template>
