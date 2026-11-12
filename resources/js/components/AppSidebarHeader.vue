<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import NotificationBell from '@/components/notifications/NotificationBell.vue';
import RealtimeStatus from '@/components/RealtimeStatus.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
import TourHelpButton from '@/components/tour/TourHelpButton.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const page = usePage();
const role = computed(() => page.props.auth?.role);
</script>

<template>
    <!--
      Translucent top bar over the scrolling page. data-shell-topbar keeps it
      still during page changes (view transition group, see app.css).
    -->
    <header
        data-shell-topbar
        class="sticky top-0 z-20 flex h-14 shrink-0 items-center gap-2 border-b border-border/80 bg-background/75 px-3 backdrop-blur-md backdrop-saturate-150 sm:h-16 sm:gap-3 sm:px-4 md:px-6 lg:px-8 min-[100rem]:px-10 [@media(max-height:32rem)]:h-12! [@media(max-height:32rem)]:gap-1.5! [@media(max-height:32rem)]:px-2! [@media(max-height:32rem)]:sm:px-3!"
    >
        <SidebarTrigger
            data-tour="shell.menu"
            class="-ml-1.5 size-9 shrink-0 rounded-lg text-muted-foreground pointer-coarse:size-10"
        />
        <span
            class="h-5 w-px shrink-0 bg-border max-sm:hidden"
            aria-hidden="true"
        />
        <div class="min-w-0 flex-1 overflow-hidden">
            <Breadcrumbs
                v-if="breadcrumbs && breadcrumbs.length > 0"
                :breadcrumbs="breadcrumbs"
            />
        </div>
        <div class="flex shrink-0 items-center gap-1.5">
            <span class="inline-flex empty:hidden" data-tour="shell.realtime">
                <RealtimeStatus />
            </span>
            <span
                v-if="role"
                class="hidden max-w-40 items-center truncate rounded-md border bg-card px-2 py-1 text-xs font-medium text-muted-foreground sm:inline-block"
                :title="`Tu rol: ${role.label}`"
            >
                <span class="sr-only">Tu rol: </span>{{ role.label }}
            </span>
            <TourHelpButton variant="inline" />
            <span class="inline-flex" data-tour="shell.notifications">
                <NotificationBell />
            </span>
            <span class="inline-flex" data-tour="shell.theme">
                <ThemeToggle />
            </span>
        </div>
    </header>
</template>
