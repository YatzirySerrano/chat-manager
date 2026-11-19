<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';
import { useEventListener } from '@vueuse/core';
import { nextTick, onMounted, ref, watch } from 'vue';
import { useSlidingIndicator } from '@/composables/useSlidingIndicator';
import '@/components/filters/filters.css';

/**
 * Section navigation of a long form (connection, platform settings): one
 * link per section of the page, with an underline that slides to the
 * section being read. It stays under the top bar while the page scrolls, so
 * any section is one click away.
 *
 * `sections` are the `id`s of the page's sections, in order. `flagged`
 * marks the ones that need attention (a validation error inside them).
 *
 * `vertical` draws it as a sticky side index instead (one row per section,
 * with its optional `icon`), for pages that keep a rail next to the content.
 */
const props = withDefaults(
    defineProps<{
        sections: { id: string; label: string; icon?: LucideIcon }[];
        ariaLabel?: string;
        flagged?: string[];
        vertical?: boolean;
    }>(),
    {
        ariaLabel: 'Secciones',
        flagged: () => [],
        vertical: false,
    },
);

const active = ref<string | null>(props.sections[0]?.id ?? null);
const nav = ref<HTMLElement | null>(null);
const { style, animated, update } = useSlidingIndicator(
    nav,
    '[aria-current=location]',
);

/** Distance from the top of the viewport at which a section counts as read. */
const READING_LINE = 180;

/** While a click scrolls the page, the spy must not jump between sections. */
let lockedUntil = 0;

function spy(): void {
    if (performance.now() < lockedUntil) {
        return;
    }

    let current = props.sections[0]?.id ?? null;

    for (const section of props.sections) {
        const element = document.getElementById(section.id);

        if (element && element.getBoundingClientRect().top <= READING_LINE) {
            current = section.id;
        }
    }

    // The last section may be too short to ever reach the reading line.
    const page = document.documentElement;

    if (
        page.scrollHeight > window.innerHeight &&
        window.innerHeight + window.scrollY >= page.scrollHeight - 4
    ) {
        current = props.sections.at(-1)?.id ?? current;
    }

    active.value = current;
}

function go(id: string): void {
    const element = document.getElementById(id);

    if (!element) {
        return;
    }

    active.value = id;
    lockedUntil = performance.now() + 700;
    element.scrollIntoView({ behavior: 'smooth', block: 'start' });
    history.replaceState(history.state, '', `#${id}`);
}

useEventListener(window, 'scroll', spy, { passive: true });
useEventListener(window, 'resize', spy, { passive: true });

onMounted(async () => {
    await nextTick();
    spy();
});

watch(active, async () => {
    if (props.vertical) {
        return;
    }

    await update();
    nav.value
        ?.querySelector<HTMLElement>('[aria-current=location]')
        ?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
});

watch(
    () => props.sections.map((section) => section.id).join(),
    async () => {
        await nextTick();
        spy();
        await update();
    },
);
</script>

<template>
    <nav
        v-if="vertical"
        ref="nav"
        :aria-label="ariaLabel"
        data-slot="section-nav"
        data-orientation="vertical"
        class="grid content-start gap-0.5"
    >
        <a
            v-for="section in sections"
            :key="section.id"
            :href="`#${section.id}`"
            :aria-current="active === section.id ? 'location' : undefined"
            class="group/nav flex min-h-9 items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-sm font-medium text-muted-foreground transition-colors outline-none hover:bg-accent/60 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring aria-[current=location]:bg-primary-soft aria-[current=location]:text-primary-soft-foreground"
            @click.prevent="go(section.id)"
        >
            <component
                :is="section.icon"
                v-if="section.icon"
                class="size-4 shrink-0 opacity-70 group-aria-[current=location]/nav:opacity-100"
                aria-hidden="true"
            />
            <span class="min-w-0 flex-1 truncate">{{ section.label }}</span>
            <span
                v-if="flagged.includes(section.id)"
                class="size-1.5 shrink-0 rounded-full bg-destructive shadow-[0_0_0_3px_color-mix(in_oklch,var(--destructive)_18%,transparent)]"
            >
                <span class="sr-only">(tiene campos por revisar)</span>
            </span>
        </a>
    </nav>
    <nav
        v-else
        ref="nav"
        :aria-label="ariaLabel"
        data-slot="section-nav"
        class="filter-tabs-scroll sticky top-14 z-10 -mx-(--page-gutter) flex gap-1 overflow-x-auto glass-bar px-(--page-gutter) sm:top-16 md:-mx-(--page-gutter-md) md:px-(--page-gutter-md) lg:-mx-(--page-gutter-lg) lg:px-(--page-gutter-lg) [@media(max-height:32rem)]:top-12!"
    >
        <a
            v-for="section in sections"
            :key="section.id"
            :href="`#${section.id}`"
            :aria-current="active === section.id ? 'location' : undefined"
            class="relative inline-flex h-11 shrink-0 items-center gap-1.5 rounded-t-md px-3 text-sm font-medium whitespace-nowrap text-muted-foreground outline-none hover:bg-accent/60 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset aria-[current=location]:text-foreground"
            @click.prevent="go(section.id)"
        >
            {{ section.label }}
            <span
                v-if="flagged.includes(section.id)"
                class="size-1.5 rounded-full bg-destructive shadow-[0_0_0_3px_color-mix(in_oklch,var(--destructive)_18%,transparent)]"
            >
                <span class="sr-only">(tiene campos por revisar)</span>
            </span>
        </a>
        <span
            aria-hidden="true"
            class="pointer-events-none absolute top-0 left-0 border-b-2 border-primary shadow-[0_6px_10px_-8px_var(--primary)]"
            :class="
                animated &&
                'transition-[transform,width,opacity] duration-(--duration-panel) ease-(--ease-out-quint)'
            "
            :style="style"
        />
    </nav>
</template>
