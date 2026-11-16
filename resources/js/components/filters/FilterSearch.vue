<script setup lang="ts">
import { Search, X } from '@lucide/vue';
import { useEventListener } from '@vueuse/core';
import { ref } from 'vue';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';

/**
 * The search box of a list (inbox, internal chat): always visible, with the
 * results following as the person types (the parent debounces), a clear
 * button and a keyboard shortcut. «/» or Ctrl+K (⌘K) jump to it from
 * anywhere on the page except while typing somewhere else, so a person who
 * answers all day never reaches for the mouse to find someone. Escape clears
 * the text, and a second Escape leaves the box; Arrow down moves to the
 * first row of the list (`next`).
 */
const props = withDefaults(
    defineProps<{
        placeholder: string;
        /** Accessible name of the box. */
        label: string;
        /** Results are on their way (a spinner replaces the clear button's spot). */
        loading?: boolean;
        maxlength?: number;
        /** Listen to «/» and Ctrl+K on the page. */
        shortcut?: boolean;
        title?: string;
        id?: string;
    }>(),
    {
        loading: false,
        maxlength: 100,
        shortcut: true,
        title: undefined,
        id: undefined,
    },
);

const model = defineModel<string>({ required: true });

const emit = defineEmits<{
    /** Arrow down from the box: the list takes the focus. */
    next: [];
}>();

const input = ref<HTMLInputElement | null>(null);
const focused = ref(false);

function focus(): void {
    input.value?.focus();
    input.value?.select();
}

function clear(): void {
    model.value = '';
    input.value?.focus();
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        if (model.value !== '') {
            event.preventDefault();
            event.stopPropagation();
            model.value = '';
        } else {
            input.value?.blur();
        }

        return;
    }

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        emit('next');
    }
}

/* Typing in another field, editor or menu never triggers the shortcut. */
function isTyping(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) {
        return false;
    }

    return (
        target.isContentEditable ||
        ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName) ||
        target.closest('[role="dialog"], [role="menu"], [role="listbox"]') !==
            null
    );
}

useEventListener(globalThis.document, 'keydown', (event: KeyboardEvent) => {
    if (!props.shortcut || event.defaultPrevented || event.isComposing) {
        return;
    }

    const slash =
        event.key === '/' && !event.ctrlKey && !event.metaKey && !event.altKey;
    const commandK =
        event.key.toLowerCase() === 'k' &&
        (event.ctrlKey || event.metaKey) &&
        !event.altKey &&
        !event.shiftKey;

    if ((slash || commandK) && !isTyping(event.target)) {
        event.preventDefault();
        focus();
    }
});

defineExpose({ focus });
</script>

<template>
    <div class="group/search relative min-w-0 flex-1" role="search">
        <Search
            class="pointer-events-none absolute top-1/2 left-3.5 z-[1] size-4 -translate-y-1/2 transition-[color,scale] duration-(--duration-base) ease-(--ease-spring)"
            :class="
                focused ? 'scale-110 text-primary' : 'text-muted-foreground'
            "
            aria-hidden="true"
        />
        <input
            :id="id"
            ref="input"
            v-model="model"
            type="search"
            autocomplete="off"
            spellcheck="false"
            enterkeyhint="search"
            :maxlength="maxlength"
            :placeholder="placeholder"
            :aria-label="label"
            :title="title"
            aria-keyshortcuts="/ Control+K Meta+K"
            :class="
                cn(
                    'h-10 w-full min-w-0 rounded-xl border border-border/80 bg-card pr-24 pl-10 text-sm text-foreground shadow-(--shadow-control) outline-none placeholder:text-muted-foreground',
                    'focus-glow hover:border-border-strong focus-visible:bg-background',
                    'pointer-coarse:h-10 [&::-webkit-search-cancel-button]:hidden',
                )
            "
            data-filter-search
            @focus="focused = true"
            @blur="focused = false"
            @keydown="onKeydown"
        />
        <span
            class="absolute top-1/2 right-1.5 flex -translate-y-1/2 items-center gap-1"
        >
            <Spinner
                v-if="loading && model !== ''"
                class="size-3.5 text-muted-foreground"
                aria-hidden="true"
            />
            <button
                v-if="model !== ''"
                type="button"
                class="flex size-7 items-center justify-center rounded-full text-muted-foreground outline-none hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring active:scale-90 motion-safe:transition-colors motion-safe:transition-transform pointer-coarse:size-8"
                aria-label="Limpiar búsqueda"
                title="Limpiar búsqueda (Esc)"
                data-filter-search-clear
                @click="clear"
            >
                <X class="size-3.5" aria-hidden="true" />
            </button>
            <span
                v-else-if="shortcut && !focused"
                class="pointer-events-none mr-1 hidden items-center gap-1 sm:inline-flex pointer-coarse:hidden"
                aria-hidden="true"
            >
                <kbd
                    class="inline-flex h-5 min-w-5 items-center justify-center rounded-md border border-b-2 bg-background px-1 font-sans text-[0.7rem] font-medium text-muted-foreground"
                    >/</kbd
                >
                <kbd
                    class="inline-flex h-5 items-center justify-center rounded-md border border-b-2 bg-background px-1 font-sans text-[0.7rem] font-medium text-muted-foreground"
                    >Ctrl K</kbd
                >
            </span>
        </span>
    </div>
</template>
