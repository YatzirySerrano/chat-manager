<script setup lang="ts">
import { Check, Monitor, Moon, Sun } from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';
import type { ThemeChoice } from '../storage';

/**
 * The visitor's theme in the chat header: one round button with the icon
 * of the current choice that opens a small menu (Sistema, Claro, Oscuro).
 * Arrow keys move inside the menu, Escape or a click outside closes it.
 */
const choice = defineModel<ThemeChoice>({ required: true });

const OPTIONS = [
    { value: 'system', label: 'Sistema', icon: Monitor },
    { value: 'light', label: 'Claro', icon: Sun },
    { value: 'dark', label: 'Oscuro', icon: Moon },
] as const;

const open = ref(false);
const root = ref<HTMLElement | null>(null);
const items = ref<HTMLButtonElement[]>([]);
const current = computed(
    () => OPTIONS.find((option) => option.value === choice.value) ?? OPTIONS[0],
);

function onOutside(event: PointerEvent): void {
    if (root.value && !root.value.contains(event.target as Node)) {
        close();
    }
}

function toggle(): void {
    if (open.value) {
        close();

        return;
    }

    open.value = true;
    document.addEventListener('pointerdown', onOutside);
    nextTick(() => {
        const index = OPTIONS.findIndex((o) => o.value === choice.value);
        items.value[Math.max(0, index)]?.focus();
    });
}

function close(): void {
    open.value = false;
    document.removeEventListener('pointerdown', onOutside);
}

function pick(value: ThemeChoice): void {
    choice.value = value;
    close();
    root.value?.querySelector<HTMLButtonElement>('button')?.focus();
}

function onMenuKeydown(event: KeyboardEvent): void {
    const index = items.value.indexOf(
        document.activeElement as HTMLButtonElement,
    );

    if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        close();
        root.value?.querySelector<HTMLButtonElement>('button')?.focus();
    } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        const step = event.key === 'ArrowDown' ? 1 : -1;
        items.value[(index + step + OPTIONS.length) % OPTIONS.length]?.focus();
    }
}

onBeforeUnmount(() => document.removeEventListener('pointerdown', onOutside));
</script>

<template>
    <div ref="root" class="relative shrink-0">
        <button
            type="button"
            class="grid size-9 place-items-center rounded-full hover:bg-white/15 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-current"
            :aria-label="`Tema: ${current.label}`"
            :title="`Tema: ${current.label}`"
            aria-haspopup="menu"
            :aria-expanded="open"
            @click="toggle"
        >
            <component :is="current.icon" class="size-4.5" aria-hidden="true" />
        </button>
        <div
            v-if="open"
            role="menu"
            aria-label="Tema del chat"
            class="absolute top-full right-0 z-20 mt-1 grid w-36 gap-0.5 rounded-xl border border-gray-200 bg-white p-1 text-sm text-gray-900 shadow-lg dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
            @keydown="onMenuKeydown"
        >
            <button
                v-for="option in OPTIONS"
                :key="option.value"
                ref="items"
                type="button"
                role="menuitemradio"
                :aria-checked="choice === option.value"
                class="flex min-h-9 items-center gap-2 rounded-lg px-2.5 text-left hover:bg-gray-100 focus-visible:bg-gray-100 focus-visible:outline-none dark:hover:bg-gray-800 dark:focus-visible:bg-gray-800"
                @click="pick(option.value)"
            >
                <component
                    :is="option.icon"
                    class="size-4 shrink-0"
                    aria-hidden="true"
                />
                <span class="flex-1">{{ option.label }}</span>
                <Check
                    v-if="choice === option.value"
                    class="size-4 shrink-0"
                    aria-hidden="true"
                />
            </button>
        </div>
    </div>
</template>
