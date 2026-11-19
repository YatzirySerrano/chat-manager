<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowRight,
    Bot,
    ListOrdered,
    MessageSquareText,
    Plus,
    Smartphone,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ChatbotAutoMessagesCard from '@/components/chatbot/ChatbotAutoMessagesCard.vue';
import ChatbotConnectionPicker from '@/components/chatbot/ChatbotConnectionPicker.vue';
import ChatbotMenuEditor from '@/components/chatbot/ChatbotMenuEditor.vue';
import KeywordRuleCard from '@/components/chatbot/KeywordRuleCard.vue';
import KeywordRuleDialog from '@/components/chatbot/KeywordRuleDialog.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Segmented } from '@/components/ui/segmented';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { index as chatbotIndex } from '@/routes/admin/chatbot';
import {
    active as activateRule,
    destroy as destroyRule,
    order as orderRules,
} from '@/routes/admin/chatbot/rules';
import { index as connectionsIndex } from '@/routes/connections';
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
    defaults: {
        retry_message: string;
        navigation_hint: string;
        throttle_minutes: number;
    };
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

/* The number being configured. First the ones whose menu is on: that is what people come to adjust. */
const selectedId = ref<number | null>(
    (
        props.connections.find((connection) => connection.menu.is_enabled) ??
        props.connections[0]
    )?.id ?? null,
);
const selected = computed(
    () =>
        props.connections.find(
            (connection) => connection.id === selectedId.value,
        ) ?? null,
);

/** Whether a rule answers on a connection (no connections chosen: all of them). */
const appliesTo = (rule: ChatbotKeywordRule, connectionId: number): boolean =>
    rule.connection_ids.length === 0 ||
    rule.connection_ids.includes(connectionId);

/** Active keyword rules per connection, for the picker. */
const ruleCounts = computed(
    () =>
        new Map(
            props.connections.map((connection) => [
                connection.id,
                props.rules.filter(
                    (rule) => rule.is_active && appliesTo(rule, connection.id),
                ).length,
            ]),
        ),
);

function activityLabel(connection: ChatbotConnection): string {
    const { routed, answered, fallback } = connection.activity;

    if (routed + answered + fallback === 0) {
        return `Nadie lo ha usado en ${props.activityDays} días`;
    }

    return `Últimos ${props.activityDays} días: ${routed} pasaron a un departamento · ${answered} contestadas por el bot · ${fallback} sin opción válida o sin respuesta`;
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

const ruleScope = ref<'connection' | 'all'>('connection');

const rulesForSelected = computed(() => {
    const connection = selected.value;

    return connection === null
        ? props.rules
        : props.rules.filter((rule) => appliesTo(rule, connection.id));
});

const shownRules = computed(() =>
    ruleScope.value === 'all' ? props.rules : rulesForSelected.value,
);

const scopeOptions = computed(() => [
    {
        value: 'connection' as const,
        label: `Aplican a ${selected.value?.name ?? 'este número'} (${rulesForSelected.value.length})`,
    },
    { value: 'all' as const, label: `Todas (${props.rules.length})` },
]);

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

/**
 * Moves a rule one place among the ones shown: it swaps with its visible
 * neighbour, so the order of the hidden ones never changes.
 */
function move(rule: ChatbotKeywordRule, offset: -1 | 1): void {
    const visible = shownRules.value;
    const neighbour = visible[visible.indexOf(rule) + offset];

    if (neighbour === undefined) {
        return;
    }

    const ids = props.rules.map((item) => item.id);
    const from = ids.indexOf(rule.id);
    const to = ids.indexOf(neighbour.id);

    [ids[from], ids[to]] = [ids[to], ids[from]];
    busyId.value = rule.id;
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
            description="Respuestas automáticas para tus números de WhatsApp: contestan al instante mientras tu equipo atiende."
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

        <EmptyState
            v-if="connections.length === 0"
            :icon="Smartphone"
            title="Primero conecta un número de WhatsApp"
            description="El chatbot se configura por número. Agrega uno en Conexiones y regresa aquí."
        >
            <Button as-child>
                <Link :href="connectionsIndex()">
                    Ir a Conexiones
                    <ArrowRight />
                </Link>
            </Button>
        </EmptyState>

        <template v-else>
            <!-- Which number -->
            <section class="grid gap-3" aria-labelledby="chatbot-picker-title">
                <div class="grid gap-0.5">
                    <h2
                        id="chatbot-picker-title"
                        class="flex items-center gap-2 font-display text-lg font-semibold"
                    >
                        <Smartphone
                            class="size-5 shrink-0 text-primary"
                            aria-hidden="true"
                        />
                        Elige el número de WhatsApp que vas a configurar
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        Cada número tiene su propio menú de bienvenida.
                    </p>
                </div>

                <ChatbotConnectionPicker
                    v-model="selectedId"
                    :connections="connections"
                    :rule-counts="ruleCounts"
                />

                <ChatbotAutoMessagesCard
                    v-if="selected"
                    :key="selected.id"
                    :connection="selected"
                />
            </section>

            <Tabs v-model="tab" class="grid gap-5">
                <TabsList
                    :aria-label="`Qué configurar en ${selected?.name ?? 'el número'}`"
                >
                    <TabsTrigger value="menu" class="gap-2">
                        <ListOrdered class="size-4" aria-hidden="true" />
                        Menú de bienvenida
                        <span
                            class="size-2 rounded-full"
                            :class="
                                selected?.menu.is_enabled
                                    ? 'bg-success'
                                    : 'bg-border-strong'
                            "
                            :title="
                                selected?.menu.is_enabled
                                    ? 'Encendido'
                                    : 'Apagado'
                            "
                            aria-hidden="true"
                        />
                    </TabsTrigger>
                    <TabsTrigger value="keywords" class="gap-2">
                        <MessageSquareText class="size-4" aria-hidden="true" />
                        Palabras clave
                        <span
                            class="rounded-full bg-muted px-1.5 text-xs text-muted-foreground tabular-nums"
                            >{{
                                selected
                                    ? (ruleCounts.get(selected.id) ?? 0)
                                    : 0
                            }}</span
                        >
                    </TabsTrigger>
                </TabsList>

                <TabsContent value="menu" class="grid gap-5">
                    <div
                        class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl bg-primary-soft/40 px-4 py-3 text-sm"
                    >
                        <ListOrdered
                            class="size-5 shrink-0 text-primary"
                            aria-hidden="true"
                        />
                        <p class="min-w-0 flex-1">
                            Cuando alguien escribe por primera vez, el bot le
                            manda un menú con números y lo pasa al departamento
                            que elija.
                            <span class="text-muted-foreground"
                                >Ej.: «1 - Ventas · 2 - Soporte».</span
                            >
                        </p>
                        <p
                            v-if="selected"
                            class="text-xs text-muted-foreground"
                        >
                            {{ activityLabel(selected) }}
                        </p>
                    </div>

                    <ChatbotMenuEditor
                        v-if="selected"
                        :key="selected.id"
                        :connection="selected"
                        :departments="departments"
                        :variables="variables"
                        :limits="limits"
                        :default-retry="defaults.retry_message"
                        :default-navigation="defaults.navigation_hint"
                    />
                </TabsContent>

                <TabsContent value="keywords" class="grid gap-5">
                    <div
                        class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl bg-primary-soft/40 px-4 py-3 text-sm"
                    >
                        <MessageSquareText
                            class="size-5 shrink-0 text-primary"
                            aria-hidden="true"
                        />
                        <p class="min-w-0 flex-1">
                            Si el cliente escribe ciertas palabras, el bot le
                            contesta al momento y avisa al agente.
                            <span class="text-muted-foreground"
                                >Ej.: escribe «precio» → «Nuestros precios están
                                en…».</span
                            >
                        </p>
                    </div>

                    <EmptyState
                        v-if="rules.length === 0"
                        :icon="Bot"
                        title="Aún no hay palabras clave"
                        description="Crea la primera: por ejemplo, que el bot conteste el horario cuando alguien escriba «horario» o «a qué hora abren»."
                    >
                        <Button v-if="can.manage" @click="openCreate">
                            <Plus />
                            Crear la primera palabra clave
                        </Button>
                    </EmptyState>

                    <template v-else>
                        <div
                            class="flex flex-wrap items-center justify-between gap-3"
                        >
                            <Segmented
                                v-model="ruleScope"
                                :options="scopeOptions"
                                size="sm"
                                aria-label="Qué palabras clave mostrar"
                            />
                            <p class="text-xs text-muted-foreground">
                                Se revisan en orden: contesta la primera que
                                coincide.
                            </p>
                        </div>

                        <EmptyState
                            v-if="shownRules.length === 0"
                            :icon="MessageSquareText"
                            :title="`Ninguna palabra clave aplica a ${selected?.name ?? 'este número'}`"
                            description="Las que ya existen están limitadas a otros números."
                        >
                            <Button
                                variant="outline"
                                @click="ruleScope = 'all'"
                            >
                                Ver todas
                            </Button>
                            <Button v-if="can.manage" @click="openCreate">
                                <Plus />
                                Nueva palabra clave
                            </Button>
                        </EmptyState>

                        <ul
                            v-else
                            class="auto-grid reveal-stagger [--col-min:20rem]"
                            role="list"
                        >
                            <li
                                v-for="(rule, index) in shownRules"
                                :key="rule.id"
                            >
                                <KeywordRuleCard
                                    :rule="rule"
                                    :position="rules.indexOf(rule)"
                                    :can-move-up="index > 0"
                                    :can-move-down="
                                        index < shownRules.length - 1
                                    "
                                    :connection-names="
                                        names(
                                            rule.connection_ids,
                                            connectionNames,
                                        )
                                    "
                                    :department-names="
                                        names(
                                            rule.department_ids,
                                            departmentNames,
                                        )
                                    "
                                    :activity-days="activityDays"
                                    :can-manage="can.manage"
                                    :busy="busyId === rule.id"
                                    @toggle="(value) => toggle(rule, value)"
                                    @edit="openEdit(rule)"
                                    @remove="askRemove(rule)"
                                    @move="(offset) => move(rule, offset)"
                                />
                            </li>
                        </ul>
                    </template>
                </TabsContent>
            </Tabs>
        </template>
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
