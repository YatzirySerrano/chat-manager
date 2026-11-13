<script setup lang="ts">
import { computed, ref } from 'vue';
import { cn } from '@/lib/utils';

/**
 * Emoji for the internal chat composer: a few groups to browse and the ones
 * the person used most recently first (remembered in this browser only).
 */
const emit = defineEmits<{ pick: [emoji: string] }>();

const RECENT_KEY = 'team-chat.emoji.recent';
const RECENT_MAX = 16;

const GROUPS = [
    {
        id: 'caras',
        label: 'Caras',
        icon: '😀',
        emoji: [
            '😀',
            '😄',
            '😁',
            '😂',
            '🤣',
            '😊',
            '🙂',
            '😉',
            '😍',
            '😘',
            '😎',
            '🤩',
            '🤔',
            '🤨',
            '😐',
            '😅',
            '😬',
            '🙄',
            '😴',
            '😮',
            '😢',
            '😭',
            '😡',
            '🤯',
            '🥳',
            '😇',
            '🤗',
            '🤫',
            '😷',
            '🤒',
            '🥲',
            '😌',
        ],
    },
    {
        id: 'gestos',
        label: 'Gestos',
        icon: '👍',
        emoji: [
            '👍',
            '👎',
            '👌',
            '🙌',
            '👏',
            '🙏',
            '💪',
            '🤝',
            '👋',
            '✌️',
            '🤞',
            '👀',
            '🫡',
            '🤷',
            '🤦',
            '🙋',
            '❤️',
            '💙',
            '💚',
            '💛',
            '🧡',
            '💜',
            '💔',
            '💯',
        ],
    },
    {
        id: 'trabajo',
        label: 'Trabajo',
        icon: '📌',
        emoji: [
            '✅',
            '❌',
            '⚠️',
            '❗',
            '❓',
            '⏰',
            '📅',
            '📌',
            '📎',
            '📝',
            '📞',
            '📧',
            '💬',
            '📣',
            '📦',
            '🚚',
            '🧾',
            '💰',
            '💳',
            '📈',
            '📉',
            '🔍',
            '🔒',
            '🛠️',
            '💡',
            '🚀',
            '🎯',
            '🏆',
        ],
    },
    {
        id: 'otros',
        label: 'Celebración y más',
        icon: '🎉',
        emoji: [
            '🎉',
            '🎊',
            '🎂',
            '🎁',
            '🔥',
            '✨',
            '⭐',
            '🌟',
            '☕',
            '🍕',
            '🌮',
            '🍻',
            '☀️',
            '🌧️',
            '🌙',
            '🚗',
            '🏠',
            '🏢',
            '✈️',
            '⚽',
            '🎵',
            '🐶',
            '🐱',
            '🌵',
        ],
    },
] as const;

type GroupId = (typeof GROUPS)[number]['id'] | 'recientes';

function readRecent(): string[] {
    try {
        const stored: unknown = JSON.parse(
            window.localStorage.getItem(RECENT_KEY) ?? '[]',
        );

        return Array.isArray(stored)
            ? stored
                  .filter((value): value is string => typeof value === 'string')
                  .slice(0, RECENT_MAX)
            : [];
    } catch {
        return [];
    }
}

const recent = ref<string[]>(readRecent());
const active = ref<GroupId>(recent.value.length > 0 ? 'recientes' : 'caras');

const shown = computed<readonly string[]>(() =>
    active.value === 'recientes'
        ? recent.value
        : (GROUPS.find((group) => group.id === active.value)?.emoji ?? []),
);

function pick(emoji: string): void {
    recent.value = [
        emoji,
        ...recent.value.filter((used) => used !== emoji),
    ].slice(0, RECENT_MAX);

    try {
        window.localStorage.setItem(RECENT_KEY, JSON.stringify(recent.value));
    } catch {
        // Storage may be unavailable (private mode): nothing is remembered.
    }

    emit('pick', emoji);
}

const tab =
    'grid h-8 flex-1 place-items-center rounded-md text-base outline-none transition-colors duration-(--duration-fast) hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring';
</script>

<template>
    <div class="w-72 max-w-full">
        <div
            class="flex gap-0.5 border-b p-1"
            role="tablist"
            aria-label="Grupos de emoji"
        >
            <button
                v-if="recent.length > 0"
                type="button"
                role="tab"
                :aria-selected="active === 'recientes'"
                :class="cn(tab, active === 'recientes' && 'bg-primary-soft')"
                aria-label="Recientes"
                title="Recientes"
                @click="active = 'recientes'"
            >
                🕘
            </button>
            <button
                v-for="group in GROUPS"
                :key="group.id"
                type="button"
                role="tab"
                :aria-selected="active === group.id"
                :class="cn(tab, active === group.id && 'bg-primary-soft')"
                :aria-label="group.label"
                :title="group.label"
                @click="active = group.id"
            >
                {{ group.icon }}
            </button>
        </div>
        <div
            class="grid max-h-52 grid-cols-8 gap-0.5 overflow-y-auto p-1.5"
            role="listbox"
            aria-label="Emojis"
        >
            <button
                v-for="emoji in shown"
                :key="emoji"
                type="button"
                role="option"
                aria-selected="false"
                class="grid aspect-square place-items-center rounded-md text-xl transition-[scale,background-color] duration-(--duration-fast) outline-none hover:scale-110 hover:bg-accent focus-visible:bg-accent"
                :aria-label="`Insertar ${emoji}`"
                @click="pick(emoji)"
            >
                {{ emoji }}
            </button>
        </div>
    </div>
</template>
