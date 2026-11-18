<script setup lang="ts">
import {
    ArrowDown,
    ArrowUp,
    BellRing,
    Clock,
    CornerDownRight,
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
 * One keyword rule as a readable sentence: «Si el cliente escribe: …
 * → El bot responde: … y avisa al agente», where it applies, its switch
 * and how much it answered lately.
 */
const props = defineProps<{
    rule: ChatbotKeywordRule;
    position: number;
    connectionNames: string[];
    departmentNames: string[];
    activityDays: number;
    canManage: boolean;
    busy?: boolean;
    /** Whether it can move up / down in the list being shown (filtered or not). */
    canMoveUp: boolean;
    canMoveDown: boolean;
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
            ? 'Todos los números'
            : props.connectionNames.join(', '),
        props.departmentNames.length === 0
            ? 'todos los departamentos'
            : props.departmentNames.join(', '),
    ].join(' · '),
);
</script>

<template>
    <article
        class="grid h-full content-start gap-3 rounded-xl border bg-card p-4 transition-[border-color,opacity] duration-(--duration-base)"
        :class="rule.is_active ? 'hover:border-border-strong' : 'border-dashed'"
        :data-active="rule.is_active ? '' : undefined"
    >
        <header class="flex items-start gap-3">
            <span
                class="grid size-7 shrink-0 place-items-center rounded-md bg-muted font-display text-sm font-semibold text-muted-foreground tabular-nums"
                :title="`Se revisa en el lugar ${position + 1}`"
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
            <label class="flex shrink-0 items-center gap-2">
                <span
                    class="text-xs font-medium"
                    :class="
                        rule.is_active
                            ? 'text-success-soft-foreground'
                            : 'text-muted-foreground'
                    "
                    aria-hidden="true"
                    >{{ rule.is_active ? 'Activa' : 'Pausada' }}</span
                >
                <Switch
                    :model-value="rule.is_active"
                    :disabled="!canManage || busy"
                    :aria-label="`${rule.is_active ? 'Pausar' : 'Activar'} «${rule.name}»`"
                    @update:model-value="
                        (value: boolean) => emit('toggle', value)
                    "
                />
            </label>
        </header>

        <div class="grid gap-1.5" :class="!rule.is_active && 'opacity-60'">
            <p class="text-xs font-semibold text-muted-foreground">
                Si el cliente escribe:
            </p>
            <ul class="flex flex-wrap gap-1" aria-label="Palabras clave">
                <li
                    v-for="keyword in rule.keywords"
                    :key="keyword"
                    class="rounded-full bg-primary-soft px-2 py-0.5 text-xs font-medium text-primary-soft-foreground"
                >
                    {{ keyword }}
                </li>
            </ul>
        </div>

        <div class="grid gap-1.5" :class="!rule.is_active && 'opacity-60'">
            <p
                class="flex items-center gap-1 text-xs font-semibold text-muted-foreground"
            >
                <CornerDownRight class="size-3.5" aria-hidden="true" />
                El bot responde:
            </p>
            <div
                class="rounded-bubble rounded-tl-sm bg-success-soft/60 px-3 py-2 text-sm"
            >
                <div class="line-clamp-4">
                    <WhatsAppText :text="reply" />
                </div>
            </div>
            <p class="flex items-center gap-1 text-xs text-muted-foreground">
                <BellRing class="size-3.5" aria-hidden="true" />
                y avisa al agente de la conversación
            </p>
        </div>

        <footer
            class="mt-auto flex flex-wrap items-center gap-x-3 gap-y-1 border-t pt-3 text-xs text-muted-foreground"
        >
            <span class="inline-flex items-center gap-1">
                <MessageSquareReply class="size-3.5" aria-hidden="true" />
                {{ rule.replies === 1 ? '1 vez' : `${rule.replies} veces` }}
                en {{ activityDays }} días
            </span>
            <span
                class="inline-flex items-center gap-1"
                title="Tiempo mínimo antes de volver a responder lo mismo en la misma conversación"
            >
                <Clock class="size-3.5" aria-hidden="true" />
                No repite antes de {{ rule.throttle_minutes }} min
            </span>
            <div v-if="canManage" class="ml-auto flex items-center">
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    :disabled="!canMoveUp || busy"
                    :aria-label="`Subir la prioridad de «${rule.name}»`"
                    @click="emit('move', -1)"
                >
                    <ArrowUp />
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    :disabled="!canMoveDown || busy"
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
