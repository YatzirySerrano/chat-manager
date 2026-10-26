<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import TourHelpButton from '@/components/tour/TourHelpButton.vue';
import TourOverlay from '@/components/tour/TourOverlay.vue';
import { Toaster } from '@/components/ui/sonner';
import { useDockedComposer } from '@/composables/useDockedComposer';
import { useToastClearance } from '@/composables/useToastClearance';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

// Toasts stay at the bottom but above a docked message composer, a sticky
// save bar and the floating help button.
const toastClearance = useToastClearance();

/**
 * Room at the end of scrolling pages so the floating help button never
 * covers their last control (pagination, a row's actions). Not needed when
 * a composer is docked (the button moves to the top bar) or the page has a
 * sticky save bar (the button rises above it); full-height screens
 * (conversations, internal chat) do not scroll as a document.
 */
const { docked, hasDockBar } = useDockedComposer();
const page = usePage();
const FULL_HEIGHT_PAGES = new Set(['conversations/Index', 'team-chat/Index']);
const reserveHelpSpace = computed(
    () =>
        !docked.value &&
        !hasDockBar.value &&
        !FULL_HEIGHT_PAGES.has(page.component),
);

/**
 * Every toast gets a visible close button: swiping or waiting is not
 * possible with a screen reader or on some touch screens. Closing a toast
 * only hides it; a notification is marked read only when it is opened.
 * The button looks 24 px wide, and its ::after extends the touch target.
 * Its colors, hover and focus ring come from the Toaster wrapper
 * (components/ui/sonner), like the rest of the toast.
 */
const toastOptions = {
    closeButtonAriaLabel: 'Cerrar notificación',
    classes: {
        closeButton:
            "size-6! after:absolute after:-inset-2.5 after:content-[''] pointer-coarse:size-8!",
    },
};
</script>

<template>
    <AppShell>
        <AppSidebar />
        <AppContent class="min-w-0 overflow-x-clip">
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <slot />
            <div
                v-if="reserveHelpSpace"
                class="h-16 shrink-0"
                aria-hidden="true"
            />
        </AppContent>
        <Toaster
            :offset="toastClearance.offset()"
            :mobile-offset="toastClearance.mobileOffset()"
            close-button
            close-button-position="top-left"
            container-aria-label="Notificaciones"
            :toast-options="toastOptions"
        />
        <TourHelpButton variant="floating" />
        <TourOverlay />
    </AppShell>
</template>
