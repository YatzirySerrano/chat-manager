<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Bot, ListOrdered, MessageSquareText, Plus } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ChatbotMenuEditor from '@/components/chatbot/ChatbotMenuEditor.vue';
import KeywordRuleCard from '@/components/chatbot/KeywordRuleCard.vue';
import KeywordRuleDialog from '@/components/chatbot/KeywordRuleDialog.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { index as chatbotIndex } from '@/routes/admin/chatbot';
import {
    active as activateRule,
    destroy as destroyRule,
    order as orderRules,
} from '@/routes/admin/chatbot/rules';
import type {
    ChatbotConnection,
    ChatbotDepartment,
    ChatbotKeywordRule,
    ChatbotLimits,
    ChatbotVariable,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Chatbot', href: chatbotIndex() }],
    },
});

const props = defineProps<{
    connections: ChatbotConnection[];
    departments: ChatbotDepartment[];
    rules: ChatbotKeywordRule[];
    variables: ChatbotVariable[];
    limits: ChatbotLimits;
    defaults: { retry_message: string; throttle_minutes: number };
    activityDays: number;
    can: { manage: boolean };
}>();

const TAB_KEY = 'chatbot.tab';

function storedTab(): 'menu' | 'keywords' {
    try {
        return window.localStorage.getItem(TAB_KEY) === 'keywords'
            ? 'keywords'
            : 'menu';
    } catch {
        return 'menu';
    }
}

const tab = ref<'menu' | 'keywords'>(storedTab());

watch(tab, (value) => {
    try {
        window.localStorage.setItem(TAB_KEY, value);
    } catch {
        // A blocked storage only forgets the tab.
    }
});

/* Menú de bienvenida: one connection at a time. */
const selectedId = ref<number | null>(props.connections[0]?.id ?? null);
const selected = computed(
    () =>
        props.connections.find(
            (connection) => connection.id === selectedId.value,
        ) ?? null,
);

const enabledMenus = computed(
    () => props.connections.filter((item) => item.menu.is_enabled).length,
);

function activityLabel(connection: ChatbotConnection): string {
    const { routed, fallback } = connection.activity;

    if (routed + fallback === 0) {
        return `Sin uso en ${props.activityDays} días`;
    }

    return `${routed} eligieron · ${fallback} sin opción`;
}

/* Palabras clave. */
const connectionNames = computed(
    () => new Map(props.connections.map((item) => [item.id, item.name])),
);
const departmentNames = computed(
    () => new Map(props.departments.map((item) => [item.id, item.name])),
);

const names = (ids: number[], map: Map<number, string>): string[] =>
    ids.map((id) => map.get(id)).filter((name): name is string => !!name);

const activeRules = computed(
    () => props.rules.filter((rule) => rule.is_active).length,
);

const dialogOpen = ref(false);
const editing = ref<ChatbotKeywordRule | null>(null);

function openCreate(): void {
    editing.value = null;
    dialogOpen.value = true;
}

function openEdit(rule: ChatbotKeywordRule): void {
    editing.value = rule;
    dialogOpen.value = true;
}

const busyId = ref<number | null>(null);

function toggle(rule: ChatbotKeywordRule, value: boolean): void {
    busyId.value = rule.id;
    router.patch(
        activateRule.url(rule.id),
        { is_active: value },
        {
            preserveScroll: true,
            only: ['rules'],
            onFinish: () => (busyId.value = null),
        },
    );
}

function move(position: number, offset: -1 | 1): void {
    const ids = props.rules.map((rule) => rule.id);
    const target = position + offset;

    if (target < 0 || target >= ids.length) {
        return;
    }

    [ids[position], ids[target]] = [ids[target], ids[position]];
    busyId.value = ids[target];
    router.put(
        orderRules.url(),
        { ids },
        {
            preserveScroll: true,
            only: ['rules'],
            onFinish: () => (busyId.value = null),
        },
    );
}

const removing = ref<ChatbotKeywordRule | null>(null);
const removeOpen = ref(false);
const removeProcessing = ref(false);

function askRemove(rule: ChatbotKeywordRule): void {
    removing.value = rule;
    removeOpen.value = true;
}

function confirmRemove(): void {
    if (removing.value === null) {
        return;
    }

    removeProcessing.value = true;
    router.delete(destroyRule.url(removing.value.id), {
        preserveScroll: true,
        only: ['rules'],
        onSuccess: () => (removeOpen.value = false),
        onFinish: () => (removeProcessing.value = false),
    });
}
</script>

<template>
    <Head title="Chatbot" />

    <div class="page-shell">
        <PageHeader
            title="Chatbot"
            description="Respuestas automáticas: un menú al iniciar cada conversación y respuestas por palabras clave. El equipo recibe aviso de lo que se respondió."
        >
            <template v-if="can.manage && tab === 'keywords'" #actions>
                <Button
                    class="max-sm:w-full"
                    data-test="chatbot-new-rule"
                    @click="openCreate"
                >
                    <Plus />
                    Nueva palabra clave
                </Button>
            </template>
        </PageHeader>

        <Tabs v-model="tab" class="grid gap-6">
            <TabsList aria-label="Partes del chatbot">
                <TabsTrigger value="menu" class="gap-2">
                    <ListOrdered class="size-4" aria-hidden="true" />
                    Menú de bienvenida
                    <span
                        class="rounded-full bg-muted px-1.5 text-xs text-muted-foreground tabular-nums"
                        >{{ enabledMenus }}/{{ connections.length }}</span
                    >
                </TabsTrigger>
                <TabsTrigger value="keywords" class="gap-2">
                    <MessageSquareText class="size-4" aria-hidden="true" />
                    Palabras clave
                    <span
                        class="rounded-full bg-muted px-1.5 text-xs text-muted-foreground tabular-nums"
                        >{{ activeRules }}/{{ rules.length }}</span
                    >
                </TabsTrigger>
            </TabsList>

            <TabsContent value="menu" class="grid gap-5">
                <EmptyState
                    v-if="connections.length === 0"
                    :icon="Bot"
                    title="Aún no hay conexiones"
                    description="El menú se configura por conexión: crea una en Conexiones."
                />

                <template v-else>
                    <div
                        class="flex gap-2 overflow-x-auto pb-1"
                        role="radiogroup"
                        aria-label="Conexión"
                    >
                        <button
                            v-for="connection in connections"
                            :key="connection.id"
                            type="button"
                            role="radio"
                            :aria-checked="connection.id === selectedId"
                            class="grid min-w-52 shrink-0 gap-1 rounded-xl border bg-card px-3.5 py-2.5 text-left transition-[border-color,background-color] duration-(--duration-fast) hover:border-border-strong"
                            :class="
                                connection.id === selectedId &&
                                'border-primary bg-primary-soft/40 hover:border-primary'
                            "
                            :data-connection="connection.id"
                            @click="selectedId = connection.id"
                        >
                            <span
                                class="flex items-center justify-between gap-2"
                            >
                                <span class="truncate text-sm font-semibold">{{
                                    connection.name
                                }}</span>
                                <span
                                    class="inline-flex shrink-0 items-center gap-1 text-xs"
                                    :class="
                                        connection.menu.is_enabled
                                            ? 'text-success-soft-foreground'
                                            : 'text-muted-foreground'
                                    "
                                >
                                    <span
                                        class="size-1.5 rounded-full"
                                        :class="
                                            connection.menu.is_enabled
                                                ? 'bg-success'
                                                : 'bg-border-strong'
                                        "
                                        aria-hidden="true"
                                    />
                                    {{
                                        connection.menu.is_enabled
                                            ? 'Activo'
                                            : 'Apagado'
                                    }}
                                </span>
                            </span>
                            <span class="truncate text-xs text-muted-foreground"
                                >{{
                                    connection.is_whatsapp_web
                                        ? 'WhatsApp (QR)'
                                        : connection.channel.label
                                }}
                                · {{ activityLabel(connection) }}</span
                            >
                        </button>
                    </div>

                    <ChatbotMenuEditor
                        v-if="selected"
                        :key="selected.id"
                        :connection="selected"
                        :departments="departments"
                        :variables="variables"
                        :limits="limits"
                        :default-retry="defaults.retry_message"
                    />
                </template>
            </TabsContent>

            <TabsContent value="keywords" class="grid gap-5">
                <EmptyState
                    v-if="rules.length === 0"
                    :icon="MessageSquareText"
                    title="Sin palabras clave"
                    description="Cuando un cliente escriba una palabra que definas (por ejemplo «horario»), el chatbot responderá solo y avisará al equipo."
                >
                    <Button v-if="can.manage" @click="openCreate">
                        <Plus />
                        Nueva palabra clave
                    </Button>
                </EmptyState>

                <template v-else>
                    <p class="max-w-prose text-sm text-muted-foreground">
                        Se revisan en orden: responde la primera que coincide.
                    </p>
                    <ul
                        class="auto-grid reveal-stagger [--col-min:20rem]"
                        role="list"
                    >
                        <li v-for="(rule, position) in rules" :key="rule.id">
                            <KeywordRuleCard
                                :rule="rule"
                                :position="position"
                                :total="rules.length"
                                :connection-names="
                                    names(rule.connection_ids, connectionNames)
                                "
                                :department-names="
                                    names(rule.department_ids, departmentNames)
                                "
                                :activity-days="activityDays"
                                :can-manage="can.manage"
                                :busy="busyId === rule.id"
                                @toggle="(value) => toggle(rule, value)"
                                @edit="openEdit(rule)"
                                @remove="askRemove(rule)"
                                @move="(offset) => move(position, offset)"
                            />
                        </li>
                    </ul>
                </template>
            </TabsContent>
        </Tabs>
    </div>

    <KeywordRuleDialog
        v-model:open="dialogOpen"
        :rule="editing"
        :connections="connections"
        :departments="departments"
        :variables="variables"
        :limits="limits"
        :default-throttle="defaults.throttle_minutes"
    />

    <ConfirmDialog
        v-model:open="removeOpen"
        :title="`¿Eliminar «${removing?.name ?? ''}»?`"
        description="El chatbot dejará de responder a estas palabras. Las respuestas ya enviadas se quedan en sus conversaciones."
        confirm-label="Eliminar"
        destructive
        :processing="removeProcessing"
        @confirm="confirmRemove"
    />
</template>
