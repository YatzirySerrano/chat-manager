<script setup lang="ts">
import { useEventListener } from '@vueuse/core';
import { nextTick, onMounted, ref, watch } from 'vue';
import { useSlidingIndicator } from '@/composables/useSlidingIndicator';

/**
 * Section navigation of a long form (connection, platform settings): one
 * link per section of the page, with an underline that slides to the
 * section being read. It stays under the top bar while the page scrolls, so
 * any section is one click away.
 *
 * `sections` are the `id`s of the page's sections, in order. `flagged`
 * marks the ones that need attention (a validation error inside them).
 */
const props = withDefaults(
    defineProps<{
        sections: { id: string; label: string }[];
        ariaLabel?: string;
        flagged?: string[];
    }>(),
    {
        ariaLabel: 'Secciones',
        flagged: () => [],
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
        ref="nav"
        :aria-label="ariaLabel"
        data-slot="section-nav"
        class="sticky top-14 z-10 -mx-(--page-gutter) flex gap-1 overflow-x-auto border-b bg-background/85 px-(--page-gutter) backdrop-blur-md sm:top-16 md:-mx-(--page-gutter-md) md:px-(--page-gutter-md) lg:-mx-(--page-gutter-lg) lg:px-(--page-gutter-lg)"
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
                class="size-1.5 rounded-full bg-destructive"
            >
                <span class="sr-only">(tiene campos por revisar)</span>
            </span>
        </a>
        <span
            aria-hidden="true"
            class="pointer-events-none absolute top-0 left-0 border-b-2 border-primary"
            :class="
                animated &&
                'transition-[transform,width,opacity] duration-(--duration-panel) ease-(--ease-out-quint)'
            "
            :style="style"
        />
    </nav>
</template>
