<script setup lang="ts">
import { Tags } from '@lucide/vue';
import { ref } from 'vue';
import TagBadge from '@/components/tags/TagBadge.vue';
import TagPickerBody from '@/components/tags/TagPickerBody.vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import type { TagOption } from '@/types';

/**
 * Shows the tags of a contact and, when allowed, lets the user change them
 * through the `contacts.tags.sync` endpoint (or `syncUrl`, e.g. for a
 * conversation). The editor itself is TagPickerBody, shared with the inbox
 * row menu. Only active tags can be newly added; deleted tags the record
 * already has can be kept or removed. The backend enforces every rule again
 * (permission, contact scope, deletion).
 */
withDefaults(
    defineProps<{
        contactId: number;
        /** Tags the contact currently has (active or not). */
        selected: TagOption[];
        /** Active tags of the catalog that can be assigned. */
        available: TagOption[];
        /** Read only: the user cannot assign tags. */
        disabled?: boolean;
        /**
         * Endpoint that replaces the tags (PUT `tag_ids`), for records other
         * than contacts (e.g. a conversation). Defaults to the contact one.
         */
        syncUrl?: string | null;
        /** Accessible name of the editor. */
        label?: string;
    }>(),
    {
        disabled: false,
        syncUrl: null,
        label: 'Editar etiquetas del contacto',
    },
);

const emit = defineEmits<{
    /** Emitted after the server saved the change, with the new tags. */
    saved: [tags: TagOption[]];
}>();

const open = ref(false);

function onSaved(tags: TagOption[]): void {
    open.value = false;
    emit('saved', tags);
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-1.5">
        <TagBadge
            v-for="tag in selected"
            :key="tag.id"
            :name="tag.name"
            :color="tag.color"
            :deleted="!tag.is_active"
        />
        <span
            v-if="selected.length === 0"
            class="text-sm text-muted-foreground"
        >
            Sin etiquetas
        </span>

        <Popover v-if="!disabled" v-model:open="open">
            <PopoverTrigger as-child>
                <Button variant="outline" size="sm" class="h-7">
                    <Tags />
                    {{ selected.length === 0 ? 'Agregar' : 'Editar' }}
                </Button>
            </PopoverTrigger>
            <PopoverContent align="start" class="w-80 p-0" :aria-label="label">
                <TagPickerBody
                    :contact-id="contactId"
                    :selected="selected"
                    :available="available"
                    :sync-url="syncUrl"
                    @saved="onSaved"
                    @cancel="open = false"
                />
            </PopoverContent>
        </Popover>
    </div>
</template>
