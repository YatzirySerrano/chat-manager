<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import TagBadge from '@/components/tags/TagBadge.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { sync } from '@/routes/contacts/tags';
import type { TagOption } from '@/types';

/**
 * The tag editor itself (search, checkboxes, save), without its container:
 * TagPicker shows it in a popover, the inbox row menu in a dialog. It saves
 * through `syncUrl` (PUT `tag_ids`) or, by default, the contact endpoint.
 * Only active tags can be newly added; deleted tags already carried can be
 * kept or removed. The backend enforces every rule again (permission, reach,
 * deletion, locking and audit).
 */
const props = withDefaults(
    defineProps<{
        contactId?: number | null;
        /** Tags the record currently has (active or not). */
        selected: TagOption[];
        /** Active tags of the catalog that can be assigned. */
        available: TagOption[];
        /** Endpoint that replaces the tags (PUT `tag_ids`). */
        syncUrl?: string | null;
        /** Props reloaded after saving (all of them when omitted). */
        only?: string[];
    }>(),
    {
        contactId: null,
        syncUrl: null,
        only: undefined,
    },
);

const emit = defineEmits<{
    /** Emitted after the server saved the change, with the new tags. */
    saved: [tags: TagOption[]];
    cancel: [];
}>();

const search = ref('');
const draft = ref<number[]>(props.selected.map((tag) => tag.id));
const processing = ref(false);
const error = ref<string | undefined>(undefined);

/*
 * The draft starts from the record's tags when the editor mounts (its
 * container mounts it on open), so live reloads of the page while it is
 * open never discard the person's choice; reset() starts over explicitly.
 */
function reset(): void {
    draft.value = props.selected.map((tag) => tag.id);
    search.value = '';
    error.value = undefined;
}

defineExpose({ reset });

/** Active catalog plus the deleted tags the record already carries. */
const options = computed<TagOption[]>(() => {
    const byId = new Map<number, TagOption>();

    for (const tag of props.available) {
        byId.set(tag.id, tag);
    }

    for (const tag of props.selected) {
        if (!byId.has(tag.id)) {
            byId.set(tag.id, tag);
        }
    }

    return [...byId.values()].sort((a, b) =>
        a.name.localeCompare(b.name, 'es'),
    );
});

const filteredOptions = computed(() => {
    const term = search.value.trim().toLocaleLowerCase('es');

    return term === ''
        ? options.value
        : options.value.filter((tag) =>
              tag.name.toLocaleLowerCase('es').includes(term),
          );
});

const currentIds = computed(() => props.selected.map((tag) => tag.id));

const isDirty = computed(() => {
    const current = [...currentIds.value].sort();
    const next = [...draft.value].sort();

    return (
        current.length !== next.length ||
        current.some((id, index) => id !== next[index])
    );
});

function canToggle(tag: TagOption): boolean {
    return tag.is_active || currentIds.value.includes(tag.id);
}

function toggle(tag: TagOption, checked: boolean): void {
    if (checked && !canToggle(tag)) {
        return;
    }

    draft.value = checked
        ? [...new Set([...draft.value, tag.id])]
        : draft.value.filter((id) => id !== tag.id);
}

function save(): void {
    const tagIds = [...draft.value];

    router.visit(
        props.syncUrl
            ? { url: props.syncUrl, method: 'put' }
            : sync(props.contactId ?? 0),
        {
            data: { tag_ids: tagIds },
            preserveScroll: true,
            preserveState: true,
            ...(props.only ? { only: props.only } : {}),
            onStart: () => {
                processing.value = true;
                error.value = undefined;
            },
            onFinish: () => (processing.value = false),
            onSuccess: () => {
                emit(
                    'saved',
                    options.value.filter((tag) => tagIds.includes(tag.id)),
                );
            },
            onError: (errors: Record<string, string>) => {
                error.value =
                    errors.tag_ids ??
                    Object.values(errors)[0] ??
                    'No se pudieron guardar las etiquetas.';
            },
        },
    );
}
</script>

<template>
    <div class="grid">
        <slot name="header" />

        <div class="border-b p-2">
            <div class="relative">
                <Search
                    class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                    aria-hidden="true"
                />
                <Input
                    v-model="search"
                    type="search"
                    placeholder="Buscar etiqueta"
                    class="h-9 pl-8"
                    aria-label="Buscar etiqueta"
                />
            </div>
        </div>

        <div class="max-h-64 overflow-y-auto p-1">
            <p
                v-if="filteredOptions.length === 0"
                class="px-2 py-6 text-center text-sm text-muted-foreground"
            >
                {{
                    options.length === 0
                        ? 'No hay etiquetas activas en el catálogo.'
                        : 'Ninguna etiqueta coincide.'
                }}
            </p>
            <Label
                v-for="tag in filteredOptions"
                :key="tag.id"
                class="flex cursor-pointer items-center gap-3 rounded-md px-2 py-1.5 font-normal hover:bg-accent pointer-coarse:min-h-10"
                :class="!canToggle(tag) && 'cursor-not-allowed opacity-60'"
            >
                <Checkbox
                    :model-value="draft.includes(tag.id)"
                    :disabled="!canToggle(tag) && !draft.includes(tag.id)"
                    @update:model-value="
                        (checked) => toggle(tag, checked === true)
                    "
                />
                <TagBadge
                    :name="tag.name"
                    :color="tag.color"
                    :deleted="!tag.is_active"
                />
            </Label>
        </div>

        <div class="grid gap-2 border-t p-2">
            <p
                v-if="options.some((tag) => !tag.is_active)"
                class="text-xs text-muted-foreground"
            >
                Las etiquetas eliminadas solo pueden conservarse o quitarse.
            </p>
            <InputError :message="error" />
            <div class="flex justify-end gap-2">
                <Button
                    variant="ghost"
                    size="sm"
                    :disabled="processing"
                    @click="emit('cancel')"
                >
                    Cancelar
                </Button>
                <Button
                    size="sm"
                    :disabled="processing || !isDirty"
                    @click="save"
                >
                    <Spinner v-if="processing" />
                    Guardar
                </Button>
            </div>
        </div>
    </div>
</template>
