<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PeoplePicker from '@/components/team-chat/PeoplePicker.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { store as storeDirect } from '@/routes/team-chat/direct';
import type { TeamContact } from '@/types/team-chat';

/**
 * Choose a person to open (or start) the private chat with them. There is a
 * single private chat per pair of people.
 */
const open = defineModel<boolean>('open', { required: true });

const processing = ref(false);
const error = ref<string | undefined>();

function pick(contact: TeamContact): void {
    if (processing.value) {
        return;
    }

    error.value = undefined;
    router.post(
        storeDirect.url(),
        { user_id: contact.id },
        {
            onStart: () => (processing.value = true),
            onFinish: () => (processing.value = false),
            onSuccess: () => (open.value = false),
            onError: (errors) => (error.value = errors.user_id),
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Nuevo chat privado</DialogTitle>
                <DialogDescription>
                    Elige a la persona. Si ya tienen un chat, se abrirá el
                    existente.
                </DialogDescription>
            </DialogHeader>
            <PeoplePicker v-if="open" @pick="pick" />
            <InputError :message="error" />
        </DialogContent>
    </Dialog>
</template>
