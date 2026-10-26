<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { Check, Search } from '@lucide/vue';
import { watchDebounced } from '@vueuse/core';
import { onMounted, ref, useId } from 'vue';
import DepartmentTag from '@/components/DepartmentTag.vue';
import TeamAvatar from '@/components/team-chat/TeamAvatar.vue';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { people } from '@/routes/team-chat';
import type { TeamContact } from '@/types/team-chat';

/**
 * Searchable list of the people the viewer may chat with (the server applies
 * the viewer's reach: same departments, or everyone with the cross-department
 * permission or an organization-wide scope). Single mode emits `pick`;
 * multiple mode toggles ids in the v-model.
 */
const props = withDefaults(
    defineProps<{
        multiple?: boolean;
        excludeThreadId?: number | null;
    }>(),
    { multiple: false, excludeThreadId: null },
);

const selected = defineModel<number[]>('selected', { default: () => [] });

const emit = defineEmits<{
    pick: [contact: TeamContact];
}>();

const inputId = useId();
const search = ref('');
const contacts = ref<TeamContact[]>([]);
const reachesEveryone = ref(false);
const loaded = ref(false);
const failed = ref(false);
const http = useHttp<
    Record<string, never>,
    { data: TeamContact[]; reaches_everyone: boolean }
>({});

let latest = 0;

async function load(): Promise<void> {
    const request = ++latest;
    failed.value = false;
    http.cancel();

    const query: Record<string, string | number> = {};

    if (search.value.trim() !== '') {
        query.search = search.value.trim();
    }

    if (props.excludeThreadId !== null) {
        query.exclude_thread = props.excludeThreadId;
    }

    try {
        const response = await http.get(people.url({ query }));

        if (request === latest) {
            contacts.value = response.data;
            reachesEveryone.value = response.reaches_everyone;
            loaded.value = true;
        }
    } catch {
        if (request === latest) {
            failed.value = true;
        }
    }
}

onMounted(load);
watchDebounced(search, load, { debounce: 250 });

function isSelected(contact: TeamContact): boolean {
    return selected.value.includes(contact.id);
}

function choose(contact: TeamContact): void {
    if (contact.already_participant) {
        return;
    }

    if (!props.multiple) {
        emit('pick', contact);

        return;
    }

    selected.value = isSelected(contact)
        ? selected.value.filter((id) => id !== contact.id)
        : [...selected.value, contact.id];
}
</script>

<template>
    <div class="space-y-3">
        <div class="relative">
            <Search
                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                aria-hidden="true"
            />
            <label :for="inputId" class="sr-only">Buscar personas</label>
            <Input
                :id="inputId"
                v-model="search"
                v-focus
                type="search"
                class="pl-9"
                placeholder="Buscar por nombre"
                autocomplete="off"
            />
        </div>

        <p class="text-xs text-muted-foreground">
            {{
                reachesEveryone
                    ? 'Puedes escribir a cualquier persona de la organización que use el chat interno.'
                    : 'Puedes escribir a las personas de tus departamentos que usan el chat interno.'
            }}
        </p>

        <div
            class="max-h-72 overflow-y-auto rounded-lg border"
            role="listbox"
            :aria-multiselectable="multiple"
            aria-label="Personas"
        >
            <div
                v-if="!loaded && !failed"
                class="flex items-center justify-center gap-2 p-6 text-sm text-muted-foreground"
            >
                <Spinner /> Cargando personas…
            </div>
            <p
                v-else-if="failed"
                class="p-6 text-center text-sm text-destructive"
            >
                No se pudo cargar la lista de personas. Inténtalo de nuevo.
            </p>
            <p
                v-else-if="contacts.length === 0"
                class="p-6 text-center text-sm text-muted-foreground"
            >
                {{
                    search.trim() === ''
                        ? 'No hay compañeros disponibles para chatear (solo puedes escribir a personas de tus departamentos).'
                        : 'Nadie coincide con la búsqueda.'
                }}
            </p>
            <button
                v-for="contact in contacts"
                :key="contact.id"
                type="button"
                role="option"
                :aria-selected="isSelected(contact)"
                :disabled="contact.already_participant"
                :class="
                    cn(
                        'flex w-full items-center gap-3 border-b px-3 py-2.5 text-left last:border-b-0 hover:bg-accent focus-visible:bg-accent focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60',
                        isSelected(contact) && 'bg-primary-soft/60',
                    )
                "
                @click="choose(contact)"
            >
                <TeamAvatar :name="contact.name" size="sm" />
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-medium">
                        {{ contact.name }}
                    </span>
                    <span
                        v-if="contact.already_participant"
                        class="block text-xs text-muted-foreground"
                    >
                        Ya participa
                    </span>
                    <span
                        v-else-if="contact.departments.length > 0"
                        class="mt-1 flex flex-wrap gap-1"
                    >
                        <DepartmentTag
                            v-for="department in contact.departments"
                            :key="department.id"
                            :name="department.name"
                            :color="department.color"
                        />
                    </span>
                </span>
                <Check
                    v-if="multiple && isSelected(contact)"
                    class="size-4 text-primary"
                    aria-hidden="true"
                />
            </button>
        </div>
    </div>
</template>
