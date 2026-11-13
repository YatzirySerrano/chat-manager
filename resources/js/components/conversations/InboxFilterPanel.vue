<script setup lang="ts">
import { Search } from '@lucide/vue';
import { RadioGroupItem, RadioGroupRoot } from 'reka-ui';
import { computed, ref } from 'vue';
import TagBadge from '@/components/tags/TagBadge.vue';
import { AppSelect } from '@/components/ui/app-select';
import type { AppSelectOption } from '@/components/ui/app-select';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioCards } from '@/components/ui/radio-cards';
import { Switch } from '@/components/ui/switch';
import type { InboxFilterState } from '@/components/conversations/inboxFilters';
import { useFeatures } from '@/composables/useFeatures';
import { formatPhone } from '@/lib/phone';
import type {
    ConnectionChannel,
    ConversationSearchScope,
    ConversationSort,
    ConversationStatusFilter,
    InboxTagMatch,
    InboxView,
    TagOption,
} from '@/types';

/**
 * Content of the inbox "Filtros" panel (InboxFilterSurface: popover on wide
 * screens, bottom sheet on phones), in four sections: what conversations,
 * who and where, tags, order and search. It edits the list's draft: every
 * change is emitted as a patch and nothing reaches the list until
 * «Aplicar». The options come from the server (the actor's reach), the URL
 * stays the state and the server validates.
 */
const props = defineProps<{
    state: InboxFilterState;
    views: InboxView[];
    /** "Vista" options the viewer has, with their labels. */
    viewLabels: Record<InboxView, string>;
    statusOptions: { value: ConversationStatusFilter; label: string }[];
    unreadCount: number;
    departments: { id: number; name: string; is_active: boolean }[];
    canSeeUnrouted: boolean;
    /** Responsible agent filter (team inboxes of supervisors only). */
    showAgents: boolean;
    agents: { id: number; name: string; is_active: boolean }[];
    channels: { value: ConnectionChannel; label: string }[];
    /** Active request categories. */
    categories: { id: number; name: string }[];
    connections: {
        id: number;
        name: string;
        display_phone_number: string | null;
    }[];
    tags: TagOption[];
    maxTags: number;
    sortOptions: { value: ConversationSort; label: string }[];
    searchScopes: { value: ConversationSearchScope; label: string }[];
}>();

/* The readiness filter is phase two (ignored by the server while it is off). */
const { phaseTwo } = useFeatures();

const emit = defineEmits<{
    change: [patch: Partial<InboxFilterState>];
}>();

const ALL = '';

const tagSearch = ref('');

const filteredTags = computed(() => {
    const term = tagSearch.value.trim().toLocaleLowerCase('es');

    return term === ''
        ? props.tags
        : props.tags.filter((tag) =>
              tag.name.toLocaleLowerCase('es').includes(term),
          );
});

const tagLimitReached = computed(
    () => props.state.tags.length >= props.maxTags,
);

function toggleTag(id: number, checked: boolean): void {
    if (checked && tagLimitReached.value) {
        return;
    }

    emit('change', {
        tags: checked
            ? [...new Set([...props.state.tags, id])]
            : props.state.tags.filter((tag) => tag !== id),
    });
}

const tagMatchOptions: {
    value: InboxTagMatch;
    label: string;
    description: string;
}[] = [
    {
        value: 'any',
        label: 'Con al menos una de estas etiquetas',
        description:
            'Conversaciones con al menos una de las etiquetas elegidas.',
    },
    {
        value: 'all',
        label: 'Con todas estas etiquetas a la vez',
        description:
            'Solo las conversaciones que tienen todas las etiquetas elegidas.',
    },
];

/* Options of the selects: the values stay the strings the URL carries. */
const departmentOptions = computed<AppSelectOption<string>[]>(() => [
    { value: ALL, label: 'Todos los departamentos' },
    ...(props.canSeeUnrouted
        ? [{ value: 'none', label: 'Sin departamento' }]
        : []),
    ...props.departments.map((department) => ({
        value: String(department.id),
        label: `${department.name}${department.is_active ? '' : ' (inactivo)'}`,
    })),
]);

const agentOptions = computed<AppSelectOption<string>[]>(() => [
    { value: ALL, label: 'Todos los agentes' },
    { value: 'none', label: 'Sin agente' },
    ...props.agents.map((agent) => ({
        value: String(agent.id),
        label: `${agent.name}${agent.is_active ? '' : ' (inactivo)'}`,
    })),
]);

const connectionOptions = computed<AppSelectOption<string>[]>(() => [
    { value: ALL, label: 'Todas las conexiones' },
    ...props.connections.map((connection) => ({
        value: String(connection.id),
        label: connection.name,
        description: connection.display_phone_number
            ? formatPhone(connection.display_phone_number)
            : undefined,
    })),
]);

const channelOptions = computed<AppSelectOption<string>[]>(() => [
    { value: ALL, label: 'Todos los canales' },
    ...props.channels,
]);

const categoryOptions = computed<AppSelectOption<string>[]>(() => [
    { value: ALL, label: 'Todas las categorías' },
    ...props.categories.map((category) => ({
        value: String(category.id),
        label: category.name,
    })),
]);

const readinessOptions: AppSelectOption<string>[] = [
    { value: ALL, label: 'Todas' },
    { value: 'needs_info', label: 'Necesita información' },
    { value: 'ready', label: 'Lista para atención' },
];

const sectionClass =
    'grid min-w-0 gap-3 border-t pt-4 first:border-t-0 first:pt-0';
const sectionLegendClass = 'font-display text-sm font-semibold text-foreground';
const fieldLabelClass = 'text-xs font-medium text-muted-foreground';
/* One option of an exclusive group shown as a chip (view, status). */
const chipClass =
    'inline-flex min-h-8 cursor-pointer items-center gap-1.5 rounded-lg border bg-card px-2.5 text-sm outline-none transition-[background-color,border-color,color,box-shadow] duration-(--duration-fast) ease-(--ease-standard) hover:border-border-strong hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-primary/25 data-[state=checked]:border-primary data-[state=checked]:bg-primary-soft data-[state=checked]:font-semibold data-[state=checked]:text-primary-soft-foreground pointer-coarse:min-h-10';
</script>

<template>
    <div class="grid gap-4 text-sm">
        <section :class="sectionClass" data-inbox-filter-section="what">
            <h3 :class="sectionLegendClass">Qué conversaciones</h3>

            <div v-if="views.length > 1" class="grid min-w-0 gap-1.5">
                <span id="inbox-view-label" :class="fieldLabelClass"
                    >Vista</span
                >
                <RadioGroupRoot
                    :model-value="state.view"
                    aria-labelledby="inbox-view-label"
                    class="flex flex-wrap gap-1.5"
                    data-inbox-filter-view
                    @update:model-value="
                        (value) => emit('change', { view: value as InboxView })
                    "
                >
                    <RadioGroupItem
                        v-for="view in views"
                        :key="view"
                        :value="view"
                        :class="chipClass"
                    >
                        {{ viewLabels[view] }}
                    </RadioGroupItem>
                </RadioGroupRoot>
            </div>

            <div class="grid min-w-0 gap-1.5">
                <span id="inbox-status-label" :class="fieldLabelClass"
                    >Estado</span
                >
                <RadioGroupRoot
                    :model-value="state.status"
                    aria-labelledby="inbox-status-label"
                    class="flex flex-wrap gap-1.5"
                    data-inbox-filter-status
                    @update:model-value="
                        (value) =>
                            emit('change', {
                                status: value as ConversationStatusFilter,
                            })
                    "
                >
                    <RadioGroupItem
                        v-for="option in statusOptions"
                        :key="option.value"
                        :value="option.value"
                        :class="chipClass"
                    >
                        {{ option.label }}
                    </RadioGroupItem>
                </RadioGroupRoot>
            </div>

            <div
                class="flex items-center justify-between gap-3 rounded-lg bg-surface-sunken px-3 py-2 pointer-coarse:min-h-10"
            >
                <Label for="inbox-filter-unread" class="text-sm font-normal">
                    Solo no leídas
                    <span class="text-xs text-muted-foreground tabular-nums"
                        >({{ unreadCount }})</span
                    >
                </Label>
                <Switch
                    id="inbox-filter-unread"
                    :model-value="state.unread"
                    @update:model-value="
                        (value) => emit('change', { unread: value === true })
                    "
                />
            </div>
        </section>

        <section :class="sectionClass" data-inbox-filter-section="who">
            <h3 :class="sectionLegendClass">Quién y dónde</h3>

            <div class="grid gap-1.5">
                <Label for="inbox-department" :class="fieldLabelClass"
                    >Departamento</Label
                >
                <AppSelect
                    id="inbox-department"
                    :model-value="state.department"
                    :options="departmentOptions"
                    @update:model-value="
                        (value) => emit('change', { department: value ?? ALL })
                    "
                />
            </div>

            <div v-if="showAgents" class="grid gap-1.5">
                <Label for="inbox-agent" :class="fieldLabelClass"
                    >Agente responsable</Label
                >
                <AppSelect
                    id="inbox-agent"
                    :model-value="state.agent"
                    :options="agentOptions"
                    @update:model-value="
                        (value) => emit('change', { agent: value ?? ALL })
                    "
                />
            </div>

            <div v-if="connections.length > 1" class="grid gap-1.5">
                <Label for="inbox-connection" :class="fieldLabelClass"
                    >Conexión</Label
                >
                <AppSelect
                    id="inbox-connection"
                    :model-value="state.connection"
                    :options="connectionOptions"
                    @update:model-value="
                        (value) => emit('change', { connection: value ?? ALL })
                    "
                />
            </div>

            <div v-if="channels.length > 1" class="grid gap-1.5">
                <Label for="inbox-channel" :class="fieldLabelClass"
                    >Canal</Label
                >
                <AppSelect
                    id="inbox-channel"
                    :model-value="state.channel"
                    :options="channelOptions"
                    @update:model-value="
                        (value) => emit('change', { channel: value ?? ALL })
                    "
                />
            </div>

            <div v-if="categories.length > 0" class="grid gap-1.5">
                <Label for="inbox-category" :class="fieldLabelClass"
                    >Categoría de la solicitud</Label
                >
                <AppSelect
                    id="inbox-category"
                    :model-value="state.category"
                    :options="categoryOptions"
                    data-inbox-filter-category
                    @update:model-value="
                        (value) => emit('change', { category: value ?? ALL })
                    "
                />
            </div>

            <div v-if="phaseTwo" class="grid gap-1.5">
                <Label for="inbox-readiness" :class="fieldLabelClass"
                    >Preparación de la solicitud</Label
                >
                <AppSelect
                    id="inbox-readiness"
                    :model-value="state.readiness"
                    :options="readinessOptions"
                    data-inbox-filter-readiness
                    @update:model-value="
                        (value) => emit('change', { readiness: value ?? ALL })
                    "
                />
            </div>
        </section>

        <section
            v-if="tags.length > 0"
            :class="sectionClass"
            data-inbox-filter-section="tags"
        >
            <h3 :class="sectionLegendClass">
                Etiquetas
                <span
                    v-if="state.tags.length > 0"
                    class="font-sans text-xs font-normal text-muted-foreground tabular-nums"
                    >({{ state.tags.length }} de máximo {{ maxTags }})</span
                >
            </h3>
            <div class="relative">
                <Search
                    class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                    aria-hidden="true"
                />
                <Input
                    v-model="tagSearch"
                    type="search"
                    placeholder="Buscar etiqueta"
                    class="h-9 pl-8 pointer-coarse:h-10"
                    aria-label="Buscar etiqueta para filtrar"
                />
            </div>
            <div
                class="grid max-h-48 gap-0.5 overflow-y-auto overscroll-contain rounded-lg border bg-surface-sunken/60 p-1"
                data-inbox-filter-tags
            >
                <p
                    v-if="filteredTags.length === 0"
                    class="px-2 py-3 text-sm text-muted-foreground"
                >
                    Ninguna etiqueta coincide.
                </p>
                <Label
                    v-for="tag in filteredTags"
                    :key="tag.id"
                    class="flex cursor-pointer items-center gap-2.5 rounded-md px-2 py-1.5 font-normal transition-colors duration-(--duration-fast) hover:bg-accent pointer-coarse:min-h-10"
                    :class="
                        tagLimitReached &&
                        !state.tags.includes(tag.id) &&
                        'cursor-not-allowed opacity-60'
                    "
                >
                    <Checkbox
                        :model-value="state.tags.includes(tag.id)"
                        :disabled="
                            tagLimitReached && !state.tags.includes(tag.id)
                        "
                        @update:model-value="
                            (checked) => toggleTag(tag.id, checked === true)
                        "
                    />
                    <TagBadge :name="tag.name" :color="tag.color" />
                </Label>
            </div>
            <p
                v-if="tagLimitReached"
                class="text-xs text-muted-foreground"
                role="status"
            >
                Puedes combinar hasta {{ maxTags }} etiquetas.
            </p>

            <RadioCards
                v-if="state.tags.length > 1"
                :model-value="state.tagMatch"
                :options="tagMatchOptions"
                aria-label="Cómo combinar las etiquetas"
                class="grid-cols-1 [--grid-gap:0.375rem]"
                data-inbox-filter-tag-match
                @update:model-value="
                    (value) => value && emit('change', { tagMatch: value })
                "
            />
        </section>

        <section :class="sectionClass" data-inbox-filter-section="order">
            <h3 :class="sectionLegendClass">Orden y búsqueda</h3>

            <div class="grid gap-3 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label for="inbox-sort" :class="fieldLabelClass"
                        >Orden</Label
                    >
                    <AppSelect
                        id="inbox-sort"
                        :model-value="state.sort"
                        :options="sortOptions"
                        @update:model-value="
                            (value) => value && emit('change', { sort: value })
                        "
                    />
                </div>

                <div class="grid gap-1.5">
                    <Label for="inbox-search-in" :class="fieldLabelClass"
                        >Buscar en</Label
                    >
                    <AppSelect
                        id="inbox-search-in"
                        :model-value="state.searchIn"
                        :options="searchScopes"
                        @update:model-value="
                            (value) =>
                                value && emit('change', { searchIn: value })
                        "
                    />
                </div>
            </div>
        </section>
    </div>
</template>
