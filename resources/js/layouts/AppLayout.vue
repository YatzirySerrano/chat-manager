<script setup lang="ts">
import { useTeamPresenceConnection } from '@/composables/useTeamPresence';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItem } from '@/types';

const { breadcrumbs = [] } = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
}>();

// Signed-in people count as online in the internal chat on every page.
useTeamPresenceConnection();
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <slot />
    </AppLayout>
</template>
