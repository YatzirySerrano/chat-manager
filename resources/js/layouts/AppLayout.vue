<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { usePermissionRefresh } from '@/composables/usePermissionRefresh';
import { useTeamPresenceConnection } from '@/composables/useTeamPresence';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItem } from '@/types';

const { breadcrumbs = [] } = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
}>();

// A page that serves two modules (Conversaciones / Mensajes web) sends its own.
const page = usePage();
const trail = computed(
    () =>
        (page.props.breadcrumbs as BreadcrumbItem[] | undefined) ?? breadcrumbs,
);

// Signed-in people count as online in the internal chat on every page.
useTeamPresenceConnection();

// Open pages follow role and permission changes made by an administrator.
usePermissionRefresh();
</script>

<template>
    <AppLayout :breadcrumbs="trail">
        <slot />
    </AppLayout>
</template>
