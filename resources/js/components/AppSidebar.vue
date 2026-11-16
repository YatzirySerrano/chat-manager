<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
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
    useSidebar,
} from '@/components/ui/sidebar';
import { useNavigation } from '@/composables/useNavigation';
import { useSlidingIndicator } from '@/composables/useSlidingIndicator';

const { sections, home } = useNavigation();

/**
 * One highlight for the whole navigation: it slides from the previous
 * section to the current one instead of each item switching its own
 * background. It follows the URL, the collapsed/expanded rail and resizes.
 */
const content = ref<{ $el?: HTMLElement } | null>(null);
const contentElement = computed(() => content.value?.$el ?? null);
const { style, animated, update } = useSlidingIndicator(
    contentElement,
    '[data-sidebar=menu-button][data-active=true]',
);

const page = usePage();
const { state } = useSidebar();

watch(() => page.url, update, { flush: 'post' });
watch(
    () => sections.value.map((section) => section.items.length).join(','),
    update,
    { flush: 'post' },
);
// The rail animates its width for 200 ms: measure again once it settles.
watch(state, () => window.setTimeout(update, 220));
</script>

<template>
    <Sidebar collapsible="icon">
        <SidebarHeader class="h-14 justify-center px-3 sm:h-16">
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        size="lg"
                        as-child
                        class="hover:bg-transparent active:bg-transparent"
                    >
                        <Link :href="home" class="group/logo">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent
            ref="content"
            class="relative gap-2 pt-1 pb-3"
            data-tour="shell.nav"
        >
            <span
                aria-hidden="true"
                class="pointer-events-none absolute top-0 left-0 rounded-lg bg-sidebar-accent shadow-[0_0_0_1px_var(--sidebar-border),0_1px_2px_color-mix(in_oklch,var(--shadow-ink)_10%,transparent),0_6px_16px_-8px_color-mix(in_oklch,var(--shadow-ink)_18%,transparent)] before:absolute before:inset-y-2 before:-left-3 before:w-0.75 before:rounded-r-full before:bg-sidebar-primary before:shadow-[0_0_10px_1px_color-mix(in_oklch,var(--sidebar-primary)_55%,transparent)] group-data-[collapsible=icon]:before:-left-2 dark:shadow-[inset_0_1px_0_var(--edge-highlight),inset_0_0_0_1px_color-mix(in_oklch,var(--sidebar-primary)_22%,transparent),0_6px_16px_-8px_rgb(0_0_0/0.5)]"
                :class="
                    animated &&
                    'transition-[transform,width,height,opacity] duration-[420ms] ease-(--ease-spring-soft)'
                "
                :style="style"
            />
            <NavMain
                v-for="section in sections"
                :key="section.label"
                :items="section.items"
                :label="section.label"
            />
        </SidebarContent>

        <SidebarFooter class="border-t border-sidebar-border px-3 py-2.5">
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
