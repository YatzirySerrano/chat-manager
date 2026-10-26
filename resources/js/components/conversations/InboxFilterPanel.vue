<script setup lang="ts">
import { Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import TagBadge from '@/components/tags/TagBadge.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import type { InboxFilterState } from '@/components/conversations/inboxFilters';
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

function selectValue(event: Event): string {
    return (event.target as HTMLSelectElement).value;
}

const tagMatchOptions: { value: InboxTagMatch; label: string; hint: string }[] =
    [
        {
            value: 'any',
            label: 'Con al menos una de estas etiquetas',
            hint: 'Conversaciones con al menos una de las etiquetas elegidas.',
        },
        {
            value: 'all',
            label: 'Con todas estas etiquetas a la vez',
            hint: 'Solo las conversaciones que tienen todas las etiquetas elegidas.',
        },
    ];

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-base shadow-xs md:text-sm outline-none hover:border-border-strong focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30 pointer-coarse:h-10 motion-safe:transition-colors';
const sectionClass =
    'grid min-w-0 gap-3 border-t pt-3 first:border-t-0 first:pt-0';
const sectionLegendClass = 'mb-1 text-sm font-semibold text-foreground';
const fieldLabelClass = 'text-xs font-medium text-muted-foreground';
const optionClass =
    'flex cursor-pointer items-center gap-2.5 rounded-md px-2 py-1.5 text-sm hover:bg-accent has-[:checked]:bg-accent/70 has-[:checked]:font-medium has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring pointer-coarse:min-h-10';
</script>

<template>
    <div class="grid gap-4 text-sm">
        <fieldset :class="sectionClass" data-inbox-filter-section="what">
            <legend :class="sectionLegendClass" class="float-left w-full">
                Qué conversaciones
            </legend>

            <fieldset v-if="views.length > 1" class="grid min-w-0 gap-1">
                <legend :class="fieldLabelClass" class="mb-1">Vista</legend>
                <div class="grid gap-0.5">
                    <label
                        v-for="view in views"
                        :key="view"
                        :class="optionClass"
                    >
                        <input
                            type="radio"
                            name="inbox-view"
                            class="size-4 shrink-0 accent-primary"
                            :value="view"
                            :checked="state.view === view"
                            @change="emit('change', { view })"
                        />
                        {{ viewLabels[view] }}
                    </label>
                </div>
            </fieldset>

            <fieldset class="grid min-w-0 gap-1">
                <legend :class="fieldLabelClass" class="mb-1">Estado</legend>
                <div class="grid gap-0.5">
                    <label
                        v-for="option in statusOptions"
                        :key="option.value"
                        :class="optionClass"
                    >
                        <input
                            type="radio"
                            name="inbox-status"
                            class="size-4 shrink-0 accent-primary"
                            :value="option.value"
                            :checked="state.status === option.value"
                            @change="emit('change', { status: option.value })"
                        />
                        {{ option.label }}
                    </label>
                </div>
            </fieldset>

            <div
                class="flex items-center justify-between gap-3 rounded-md px-2 py-1 pointer-coarse:min-h-10"
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
        </fieldset>

        <fieldset :class="sectionClass" data-inbox-filter-section="who">
            <legend :class="sectionLegendClass" class="float-left w-full">
                Quién y dónde
            </legend>

            <div class="grid gap-1.5">
                <Label for="inbox-department" :class="fieldLabelClass"
                    >Departamento</Label
                >
                <select
                    id="inbox-department"
                    :value="state.department"
                    :class="selectClass"
                    @change="
                        (event) =>
                            emit('change', { department: selectValue(event) })
                    "
                >
                    <option :value="ALL">Todos los departamentos</option>
                    <option v-if="canSeeUnrouted" value="none">
                        Sin departamento
                    </option>
                    <option
                        v-for="department in departments"
                        :key="department.id"
                        :value="String(department.id)"
                    >
                        {{ department.name
                        }}{{ department.is_active ? '' : ' (inactivo)' }}
                    </option>
                </select>
            </div>

            <div v-if="showAgents" class="grid gap-1.5">
                <Label for="inbox-agent" :class="fieldLabelClass"
                    >Agente responsable</Label
                >
                <select
                    id="inbox-agent"
                    :value="state.agent"
                    :class="selectClass"
                    @change="
                        (event) => emit('change', { agent: selectValue(event) })
                    "
                >
                    <option :value="ALL">Todos los agentes</option>
                    <option value="none">Sin agente</option>
                    <option
                        v-for="agent in agents"
                        :key="agent.id"
                        :value="String(agent.id)"
                    >
                        {{ agent.name
                        }}{{ agent.is_active ? '' : ' (inactivo)' }}
                    </option>
                </select>
            </div>

            <div v-if="connections.length > 1" class="grid gap-1.5">
                <Label for="inbox-connection" :class="fieldLabelClass"
                    >Conexión</Label
                >
                <select
                    id="inbox-connection"
                    :value="state.connection"
                    :class="selectClass"
                    @change="
                        (event) =>
                            emit('change', { connection: selectValue(event) })
                    "
                >
                    <option :value="ALL">Todas las conexiones</option>
                    <option
                        v-for="connection in connections"
                        :key="connection.id"
                        :value="String(connection.id)"
                    >
                        {{ connection.name
                        }}{{
                            connection.display_phone_number
                                ? ` · ${formatPhone(connection.display_phone_number)}`
                                : ''
                        }}
                    </option>
                </select>
            </div>

            <div v-if="channels.length > 1" class="grid gap-1.5">
                <Label for="inbox-channel" :class="fieldLabelClass"
                    >Canal</Label
                >
                <select
                    id="inbox-channel"
                    :value="state.channel"
                    :class="selectClass"
                    @change="
                        (event) =>
                            emit('change', { channel: selectValue(event) })
                    "
                >
                    <option :value="ALL">Todos los canales</option>
                    <option
                        v-for="channel in channels"
                        :key="channel.value"
                        :value="channel.value"
                    >
                        {{ channel.label }}
                    </option>
                </select>
            </div>
        </fieldset>

        <fieldset
            v-if="tags.length > 0"
            :class="sectionClass"
            data-inbox-filter-section="tags"
        >
            <legend :class="sectionLegendClass" class="float-left w-full">
                Etiquetas
                <span
                    v-if="state.tags.length > 0"
                    class="text-xs font-normal text-muted-foreground tabular-nums"
                    >({{ state.tags.length }} de máximo {{ maxTags }})</span
                >
            </legend>
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
                class="grid max-h-48 gap-0.5 overflow-y-auto overscroll-contain rounded-md border p-1"
                data-inbox-filter-tags
            >
                <p
                    v-if="filteredTags.length === 0"
                    class="px-2 py-3 text-center text-sm text-muted-foreground"
                >
                    Ninguna etiqueta coincide.
                </p>
                <Label
                    v-for="tag in filteredTags"
                    :key="tag.id"
                    class="flex cursor-pointer items-center gap-2.5 rounded-md px-2 py-1.5 font-normal hover:bg-accent pointer-coarse:min-h-10"
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

            <div
                v-if="state.tags.length > 1"
                class="grid gap-0.5"
                role="radiogroup"
                aria-label="Cómo combinar las etiquetas"
            >
                <label
                    v-for="option in tagMatchOptions"
                    :key="option.value"
                    :class="optionClass"
                    :title="option.hint"
                >
                    <input
                        type="radio"
                        name="inbox-tag-match"
                        class="size-4 shrink-0 accent-primary"
                        :value="option.value"
                        :checked="state.tagMatch === option.value"
                        @change="emit('change', { tagMatch: option.value })"
                    />
                    <span class="grid">
                        <span>{{ option.label }}</span>
                        <span class="text-xs font-normal text-muted-foreground">
                            {{ option.hint }}
                        </span>
                    </span>
                </label>
            </div>
        </fieldset>

        <fieldset :class="sectionClass" data-inbox-filter-section="order">
            <legend :class="sectionLegendClass" class="float-left w-full">
                Orden y búsqueda
            </legend>

            <div class="grid gap-1.5">
                <Label for="inbox-sort" :class="fieldLabelClass">Orden</Label>
                <select
                    id="inbox-sort"
                    :value="state.sort"
                    :class="selectClass"
                    @change="
                        (event) =>
                            emit('change', {
                                sort: selectValue(event) as ConversationSort,
                            })
                    "
                >
                    <option
                        v-for="option in sortOptions"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </select>
            </div>

            <div class="grid gap-1.5">
                <Label for="inbox-search-in" :class="fieldLabelClass"
                    >Buscar en</Label
                >
                <select
                    id="inbox-search-in"
                    :value="state.searchIn"
                    :class="selectClass"
                    @change="
                        (event) =>
                            emit('change', {
                                searchIn: selectValue(
                                    event,
                                ) as ConversationSearchScope,
                            })
                    "
                >
                    <option
                        v-for="option in searchScopes"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </select>
            </div>
        </fieldset>
    </div>
</template>
