<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import QuickSearch from '@/components/QuickSearch.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
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
    <header
        class="sticky top-0 z-20 flex h-16 shrink-0 items-center gap-3 border-b bg-background/85 px-4 backdrop-blur supports-[backdrop-filter]:bg-background/70 md:px-6"
    >
        <SidebarTrigger class="-ml-1 size-9 rounded-lg text-muted-foreground" />
        <div class="min-w-0 flex-1">
            <Breadcrumbs
                v-if="breadcrumbs && breadcrumbs.length > 0"
                :breadcrumbs="breadcrumbs"
            />
        </div>
        <div class="flex items-center gap-1.5">
            <QuickSearch />
            <span
                v-if="role"
                class="hidden items-center rounded-full border bg-card px-2.5 py-1 text-xs font-medium text-muted-foreground lg:inline-flex"
                :title="`Tu rol: ${role.label}`"
            >
                {{ role.label }}
            </span>
            <ThemeToggle />
        </div>
    </header>
</template>
