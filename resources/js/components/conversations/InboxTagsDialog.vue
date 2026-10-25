<script setup lang="ts">
import { computed } from 'vue';
import TagPickerBody from '@/components/tags/TagPickerBody.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { update as updateTags } from '@/routes/conversations/tags';
import type { ConversationSummary, TagOption } from '@/types';

/**
 * "Administrar etiquetas" from an inbox row: the shared tag editor for the
 * CONVERSATION's tags (not the contact's, which are managed from the
 * contact). It saves through the same endpoint as the details panel
 * (PUT conversations/{id}/tags: lock, permission, audit, real-time); the
 * page reloads afterwards, so the row, the counters and the tag filter
 * follow.
 */
const props = defineProps<{
    conversation: ConversationSummary | null;
    /** Active tag catalog. */
    available: TagOption[];
}>();

const open = defineModel<boolean>('open', { required: true });

const contactName = computed(() => {
    const contact = props.conversation?.contact as
        | (ConversationSummary['contact'] & { display_name?: string | null })
        | undefined;

    return contact?.display_name ?? contact?.name ?? '';
});
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="gap-0 p-0 sm:max-w-md">
            <DialogHeader class="border-b p-4 text-left">
                <DialogTitle class="text-base">
                    Etiquetas de la conversación
                </DialogTitle>
                <DialogDescription class="text-sm">
                    Estas etiquetas se aplican a esta conversación con
                    {{ contactName }}. Las etiquetas del contacto se administran
                    desde su ficha de contacto.
                </DialogDescription>
            </DialogHeader>
            <TagPickerBody
                v-if="conversation"
                :key="conversation.id"
                :selected="conversation.tags ?? []"
                :available="available"
                :sync-url="updateTags.url(conversation.id)"
                @saved="open = false"
                @cancel="open = false"
            />
        </DialogContent>
    </Dialog>
</template>
