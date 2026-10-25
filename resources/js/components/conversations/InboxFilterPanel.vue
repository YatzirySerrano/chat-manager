<script setup lang="ts">
import { Search, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import TagBadge from '@/components/tags/TagBadge.vue';
import { Button } from '@/components/ui/button';
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
 * Content of the inbox "Filtros" menu (popover on wide screens, bottom
 * sheet on phones), organized in sections. It never applies anything by
 * itself: every change is emitted as a patch and the list applies it
 * (URL state, server validation and the actor's reach stay the rule).
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
    hasFilters: boolean;
}>();

const emit = defineEmits<{
    change: [patch: Partial<InboxFilterState>];
    clear: [];
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
            label: 'Cualquiera de las etiquetas (O)',
            hint: 'Conversaciones con al menos una de las etiquetas elegidas.',
        },
        {
            value: 'all',
            label: 'Todas las etiquetas (Y)',
            hint: 'Solo las conversaciones que tienen todas las etiquetas elegidas.',
        },
    ];

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-base shadow-xs md:text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30 pointer-coarse:h-10';
const legendClass = 'mb-2 text-xs font-semibold text-muted-foreground';
const optionClass =
    'flex cursor-pointer items-center gap-2.5 rounded-md px-2 py-1.5 text-sm hover:bg-accent has-[:checked]:bg-accent/70 has-[:checked]:font-medium has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring pointer-coarse:min-h-10';
</script>

<template>
    <div class="grid gap-4 text-sm">
        <fieldset v-if="views.length > 1">
            <legend :class="legendClass">Vista</legend>
            <div class="grid gap-0.5">
                <label v-for="view in views" :key="view" :class="optionClass">
                    <input
                        type="radio"
                        name="inbox-view"
                        class="size-4 accent-primary"
                        :value="view"
                        :checked="state.view === view"
                        @change="emit('change', { view })"
                    />
                    {{ viewLabels[view] }}
                </label>
            </div>
        </fieldset>

        <fieldset>
            <legend :class="legendClass">Estado</legend>
            <div class="grid gap-0.5">
                <label
                    v-for="option in statusOptions"
                    :key="option.value"
                    :class="optionClass"
                >
                    <input
                        type="radio"
                        name="inbox-status"
                        class="size-4 accent-primary"
                        :value="option.value"
                        :checked="state.status === option.value"
                        @change="emit('change', { status: option.value })"
                    />
                    {{ option.label }}
                </label>
            </div>
        </fieldset>

        <div class="flex items-center justify-between gap-3">
            <Label for="inbox-filter-unread" class="font-normal">
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

        <div class="grid gap-1.5">
            <Label for="inbox-department" :class="legendClass" class="mb-0"
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
            <Label for="inbox-agent" :class="legendClass" class="mb-0"
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
                    {{ agent.name }}{{ agent.is_active ? '' : ' (inactivo)' }}
                </option>
            </select>
        </div>

        <div v-if="channels.length > 1" class="grid gap-1.5">
            <Label for="inbox-channel" :class="legendClass" class="mb-0"
                >Canal</Label
            >
            <select
                id="inbox-channel"
                :value="state.channel"
                :class="selectClass"
                @change="
                    (event) => emit('change', { channel: selectValue(event) })
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

        <div v-if="connections.length > 1" class="grid gap-1.5">
            <Label for="inbox-connection" :class="legendClass" class="mb-0"
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

        <fieldset v-if="tags.length > 0">
            <legend :class="legendClass">
                Etiquetas
                <span v-if="state.tags.length > 0" class="tabular-nums"
                    >({{ state.tags.length }} de máximo {{ maxTags }})</span
                >
            </legend>
            <div class="relative mb-1.5">
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
                class="grid max-h-44 gap-0.5 overflow-y-auto rounded-md border p-1"
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
                class="mt-1 text-xs text-muted-foreground"
                role="status"
            >
                Puedes combinar hasta {{ maxTags }} etiquetas.
            </p>

            <div
                v-if="state.tags.length > 1"
                class="mt-2 grid gap-0.5"
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
                        class="size-4 accent-primary"
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

        <div class="grid gap-1.5">
            <Label for="inbox-sort" :class="legendClass" class="mb-0"
                >Orden</Label
            >
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
            <Label for="inbox-search-in" :class="legendClass" class="mb-0"
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

        <Button
            v-if="hasFilters"
            variant="outline"
            size="sm"
            class="pointer-coarse:h-10"
            @click="emit('clear')"
        >
            <X aria-hidden="true" />
            Limpiar filtros
        </Button>
    </div>
</template>
