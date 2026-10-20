<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { MessageSquareText, TriangleAlert } from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { computed, nextTick, ref, useId, watch } from 'vue';
import { Spinner } from '@/components/ui/spinner';
import { available } from '@/routes/quick-replies';
import type { AvailableQuickReply } from '@/types';

/**
 * Command-style list of the quick replies the user can insert, filtered by
 * the text typed after "/". It only inserts: the parent composer receives the
 * rendered text through `select` and the agent decides whether to send it.
 *
 * The parent keeps focus in its textarea and forwards key presses through the
 * exposed `handleKeydown` (ArrowUp/ArrowDown/Enter/Tab/Escape).
 */
const props = withDefaults(
    defineProps<{
        /** Text typed after "/". */
        query: string;
        contactId?: number | null;
        departmentId?: number | null;
    }>(),
    {
        contactId: null,
        departmentId: null,
    },
);

const emit = defineEmits<{
    select: [reply: AvailableQuickReply];
    close: [];
}>();

const listId = useId();
const http = useHttp<Record<string, never>, { data: AvailableQuickReply[] }>(
    {},
);
const replies = ref<AvailableQuickReply[]>([]);
const activeIndex = ref(0);
const loaded = ref(false);
const failed = ref(false);
const listElement = ref<HTMLElement | null>(null);

let latestRequest = 0;

async function load(): Promise<void> {
    const request = ++latestRequest;

    http.cancel();
    failed.value = false;

    const query: Record<string, string | number> = {};

    if (props.query !== '') {
        query.q = props.query;
    }

    if (props.contactId !== null) {
        query.contact_id = props.contactId;
    }

    if (props.departmentId !== null) {
        query.department_id = props.departmentId;
    }

    try {
        const response = await http.get(available.url({ query }));

        if (request !== latestRequest) {
            return;
        }

        replies.value = response.data;
        activeIndex.value = 0;
        loaded.value = true;
    } catch {
        // A cancelled request was replaced by a newer one.
        if (request === latestRequest) {
            failed.value = true;
        }
    }
}

watchDebounced(() => [props.query, props.contactId, props.departmentId], load, {
    debounce: 150,
    immediate: true,
});

const activeReply = computed(() => replies.value[activeIndex.value] ?? null);

watch(activeIndex, async () => {
    await nextTick();
    listElement.value
        ?.querySelector<HTMLElement>('[aria-selected="true"]')
        ?.scrollIntoView({ block: 'nearest' });
});

function choose(reply: AvailableQuickReply | null): void {
    if (reply) {
        emit('select', reply);
    }
}

/**
 * Returns true when the key was handled, so the parent can prevent the
 * default textarea behavior.
 */
function handleKeydown(event: KeyboardEvent): boolean {
    switch (event.key) {
        case 'ArrowDown':
            if (replies.value.length > 0) {
                activeIndex.value =
                    (activeIndex.value + 1) % replies.value.length;
            }

            return true;
        case 'ArrowUp':
            if (replies.value.length > 0) {
                activeIndex.value =
                    (activeIndex.value - 1 + replies.value.length) %
                    replies.value.length;
            }

            return true;
        case 'Enter':
        case 'Tab':
            if (activeReply.value) {
                choose(activeReply.value);

                return true;
            }

            return false;
        case 'Escape':
            emit('close');

            return true;
        default:
            return false;
    }
}

defineExpose({
    handleKeydown,
    activeOptionId: computed(() =>
        activeReply.value ? `${listId}-${activeReply.value.id}` : undefined,
    ),
    listId,
});
</script>

<template>
    <div
        class="overflow-hidden rounded-lg border bg-popover text-popover-foreground shadow-elevated"
    >
        <div
            class="flex items-center justify-between gap-2 border-b px-3 py-2 text-xs text-muted-foreground"
        >
            <span class="inline-flex items-center gap-1.5">
                <MessageSquareText class="size-3.5" aria-hidden="true" />
                Respuestas rápidas
                <span v-if="query" class="font-mono text-foreground"
                    >/{{ query }}</span
                >
            </span>
            <Spinner v-if="http.processing" class="size-3.5" />
        </div>

        <ul
            v-if="replies.length > 0"
            :id="listId"
            ref="listElement"
            role="listbox"
            aria-label="Respuestas rápidas disponibles"
            class="max-h-64 overflow-y-auto p-1"
        >
            <li
                v-for="(reply, index) in replies"
                :id="`${listId}-${reply.id}`"
                :key="reply.id"
                role="option"
                :aria-selected="index === activeIndex"
                class="cursor-pointer rounded-md px-2.5 py-2 text-sm"
                :class="
                    index === activeIndex ? 'bg-accent' : 'hover:bg-accent/60'
                "
                @mousedown.prevent="choose(reply)"
                @mousemove="activeIndex = index"
            >
                <div class="flex items-center gap-2">
                    <span
                        class="shrink-0 font-mono text-xs font-medium whitespace-nowrap text-primary"
                        >/{{ reply.shortcut }}</span
                    >
                    <span class="min-w-0 truncate font-medium">{{
                        reply.title
                    }}</span>
                    <span
                        class="shrink-0 rounded bg-muted px-1.5 py-0.5 text-[11px] text-muted-foreground"
                    >
                        {{
                            reply.is_global
                                ? 'Global'
                                : reply.departments
                                      .map((department) => department.name)
                                      .join(', ')
                        }}
                    </span>
                    <TriangleAlert
                        v-if="reply.missing.length > 0"
                        class="ml-auto size-3.5 shrink-0 text-warning"
                        aria-label="Faltan datos para algunas variables"
                    />
                </div>
                <p class="mt-0.5 line-clamp-2 text-xs text-muted-foreground">
                    {{ reply.text }}
                </p>
            </li>
        </ul>

        <p
            v-else-if="failed"
            class="px-3 py-4 text-center text-sm text-destructive"
        >
            No se pudieron cargar las respuestas rápidas.
        </p>
        <p
            v-else-if="loaded && !http.processing"
            class="px-3 py-4 text-center text-sm text-muted-foreground"
        >
            {{
                query
                    ? `Ninguna respuesta coincide con /${query}.`
                    : 'No tienes respuestas rápidas disponibles.'
            }}
        </p>

        <p
            class="border-t px-3 py-1.5 text-[11px] text-muted-foreground"
            aria-hidden="true"
        >
            ↑ ↓ para moverte · Enter para insertar · Esc para cerrar
        </p>
    </div>
</template>
