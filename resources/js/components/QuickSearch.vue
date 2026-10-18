<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { CornerDownLeft, Search } from '@lucide/vue';
import { useEventListener } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { useNavigation } from '@/composables/useNavigation';
import { toUrl } from '@/lib/utils';

/**
 * Quick access to the modules the user is allowed to open (⌘K / Ctrl+K).
 */
const { sections } = useNavigation();

const open = ref(false);
const query = ref('');
const activeIndex = ref(0);

const normalize = (value: string): string =>
    value
        .toLocaleLowerCase('es')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '');

const results = computed(() => {
    const term = normalize(query.value.trim());

    return sections.value
        .flatMap((section) =>
            section.items.map((item) => ({ ...item, section: section.label })),
        )
        .filter(
            (item) =>
                term === '' ||
                normalize(`${item.title} ${item.description}`).includes(term),
        );
});

watch(query, () => (activeIndex.value = 0));
watch(open, (isOpen) => {
    if (isOpen) {
        query.value = '';
        activeIndex.value = 0;
    }
});

useEventListener(window, 'keydown', (event: KeyboardEvent) => {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        open.value = !open.value;
    }
});

function go(index: number): void {
    const item = results.value[index];

    if (!item) {
        return;
    }

    open.value = false;
    router.visit(toUrl(item.href));
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        activeIndex.value = Math.min(
            activeIndex.value + 1,
            results.value.length - 1,
        );
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        activeIndex.value = Math.max(activeIndex.value - 1, 0);
    } else if (event.key === 'Enter') {
        event.preventDefault();
        go(activeIndex.value);
    }
}
</script>

<template>
    <Button
        variant="outline"
        class="h-9 w-9 justify-start gap-2 rounded-lg bg-card px-0 text-muted-foreground shadow-none sm:w-64 sm:px-3"
        aria-label="Buscar módulo"
        @click="open = true"
    >
        <Search class="mx-auto size-4 sm:mx-0" />
        <span class="hidden flex-1 text-left text-sm font-normal sm:inline">
            Buscar módulo…
        </span>
        <kbd
            class="hidden rounded border bg-muted px-1.5 font-mono text-[10px] sm:inline"
        >
            ⌘K
        </kbd>
    </Button>

    <Dialog v-model:open="open">
        <DialogContent
            class="top-[20%] translate-y-0 gap-0 overflow-hidden p-0 sm:max-w-lg"
            :show-close-button="false"
        >
            <DialogTitle class="sr-only">Buscar módulo</DialogTitle>
            <DialogDescription class="sr-only">
                Escribe para filtrar y presiona Enter para abrir el módulo.
            </DialogDescription>
            <div class="flex items-center gap-2 border-b px-4">
                <Search class="size-4 text-muted-foreground" />
                <input
                    v-model="query"
                    type="search"
                    class="h-12 flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                    placeholder="Buscar módulo…"
                    aria-label="Buscar módulo"
                    role="combobox"
                    aria-expanded="true"
                    aria-controls="quick-search-results"
                    autofocus
                    @keydown="onKeydown"
                />
            </div>
            <ul
                id="quick-search-results"
                role="listbox"
                class="max-h-80 overflow-y-auto p-2"
            >
                <li
                    v-for="(item, index) in results"
                    :key="item.title"
                    role="option"
                    :aria-selected="index === activeIndex"
                >
                    <button
                        type="button"
                        class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm transition-colors"
                        :class="
                            index === activeIndex
                                ? 'bg-primary-soft text-primary-soft-foreground'
                                : 'hover:bg-accent'
                        "
                        @mouseenter="activeIndex = index"
                        @click="go(index)"
                    >
                        <component
                            :is="item.icon"
                            class="size-4 shrink-0 opacity-80"
                        />
                        <span class="flex-1">
                            <span class="block font-medium">
                                {{ item.title }}
                            </span>
                            <span class="block text-xs text-muted-foreground">
                                {{ item.section }} · {{ item.description }}
                            </span>
                        </span>
                        <CornerDownLeft
                            v-if="index === activeIndex"
                            class="size-3.5 opacity-60"
                        />
                    </button>
                </li>
                <li
                    v-if="results.length === 0"
                    class="px-3 py-6 text-center text-sm text-muted-foreground"
                >
                    No hay módulos que coincidan con «{{ query }}».
                </li>
            </ul>
        </DialogContent>
    </Dialog>
</template>
