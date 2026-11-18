<script setup lang="ts">
import {
    ArrowDown,
    ArrowUp,
    Clock,
    MessageSquareReply,
    Pencil,
    PlugZap,
    Trash2,
} from '@lucide/vue';
import { computed } from 'vue';
import WhatsAppText from '@/components/WhatsAppText.vue';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { defaultSample, renderChatbotText } from '@/lib/chatbotText';
import type { ChatbotKeywordRule } from '@/types';

/**
 * One keyword rule as a card: switch, keyword chips, the reply as the
 * customer reads it, where it applies and how much it answered lately.
 */
const props = defineProps<{
    rule: ChatbotKeywordRule;
    position: number;
    total: number;
    connectionNames: string[];
    departmentNames: string[];
    activityDays: number;
    canManage: boolean;
    busy?: boolean;
}>();

const emit = defineEmits<{
    toggle: [active: boolean];
    edit: [];
    remove: [];
    move: [offset: -1 | 1];
}>();

const reply = computed(() =>
    renderChatbotText(props.rule.reply_message, defaultSample()),
);

const scope = computed(() =>
    [
        props.connectionNames.length === 0
            ? 'Todas las conexiones'
            : props.connectionNames.join(', '),
        props.departmentNames.length === 0
            ? 'todos los departamentos'
            : props.departmentNames.join(', '),
    ].join(' · '),
);
</script>

<template>
    <article
        class="grid content-start gap-3 rounded-xl border bg-card p-4 transition-[border-color,opacity] duration-(--duration-base)"
        :class="rule.is_active ? 'hover:border-border-strong' : 'opacity-70'"
        :data-active="rule.is_active ? '' : undefined"
    >
        <header class="flex items-start gap-3">
            <span
                class="grid size-7 shrink-0 place-items-center rounded-md bg-muted font-display text-sm font-semibold text-muted-foreground tabular-nums"
                :title="`Prioridad ${position + 1}`"
                aria-hidden="true"
                >{{ position + 1 }}</span
            >
            <div class="grid min-w-0 flex-1 gap-0.5">
                <h3 class="truncate font-display text-base font-semibold">
                    {{ rule.name }}
                </h3>
                <p
                    class="truncate text-xs text-muted-foreground"
                    :title="scope"
                >
                    <PlugZap class="inline size-3" aria-hidden="true" />
                    {{ scope }}
                </p>
            </div>
            <Switch
                :model-value="rule.is_active"
                :disabled="!canManage || busy"
                :aria-label="`${rule.is_active ? 'Desactivar' : 'Activar'} «${rule.name}»`"
                @update:model-value="(value: boolean) => emit('toggle', value)"
            />
        </header>

        <ul class="flex flex-wrap gap-1" aria-label="Palabras clave">
            <li
                v-for="keyword in rule.keywords"
                :key="keyword"
                class="rounded-sm bg-primary-soft px-1.5 py-0.5 text-xs font-medium text-primary-soft-foreground"
            >
                {{ keyword }}
            </li>
        </ul>

        <div
            class="rounded-bubble rounded-br-sm bg-surface-sunken px-3 py-2 text-sm"
        >
            <div class="line-clamp-4">
                <WhatsAppText :text="reply" />
            </div>
        </div>

        <footer
            class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground"
        >
            <span class="inline-flex items-center gap-1">
                <MessageSquareReply class="size-3.5" aria-hidden="true" />
                {{
                    rule.replies === 1
                        ? '1 respuesta'
                        : `${rule.replies} respuestas`
                }}
                en {{ activityDays }} días
            </span>
            <span class="inline-flex items-center gap-1">
                <Clock class="size-3.5" aria-hidden="true" />
                Repite tras {{ rule.throttle_minutes }} min
            </span>
            <div v-if="canManage" class="ml-auto flex items-center">
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    :disabled="position === 0 || busy"
                    :aria-label="`Subir la prioridad de «${rule.name}»`"
                    @click="emit('move', -1)"
                >
                    <ArrowUp />
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    :disabled="position === total - 1 || busy"
                    :aria-label="`Bajar la prioridad de «${rule.name}»`"
                    @click="emit('move', 1)"
                >
                    <ArrowDown />
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    :aria-label="`Editar «${rule.name}»`"
                    @click="emit('edit')"
                >
                    <Pencil />
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    class="hover:text-destructive"
                    :aria-label="`Eliminar «${rule.name}»`"
                    @click="emit('remove')"
                >
                    <Trash2 />
                </Button>
            </div>
        </footer>
    </article>
</template>
