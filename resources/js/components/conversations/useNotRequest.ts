import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import {
    conversationConflictFromErrors,
    withoutConflictCode,
} from '@/lib/conversations/actionErrors';
import { store as markNotRequestRoute } from '@/routes/conversations/not-request';
import type { ConversationSummary } from '@/types';

/** Conversation being marked, shared by the list rows and the chat header. */
const markingId = ref<number | null>(null);

/**
 * «No es solicitud»: a «gracias», an emoji or an «ok» after a finished
 * request closes in one click, without accepting it first and without
 * sending anything to the customer; it goes to «Cerradas» and out of the
 * reports. There is no confirmation: the server's toast offers «Deshacer»
 * for a few minutes (UndoNotRequest), which puts it back where it was with
 * its place in the queue.
 *
 * Only one runs at a time, so a double click never sends two requests. A
 * conflict (a colleague took it, it changed) or a refusal comes back as a
 * toast with the server's explanation.
 */
export function useNotRequest() {
    function markNotRequest(conversation: ConversationSummary): void {
        if (markingId.value !== null) {
            return;
        }

        markingId.value = conversation.id;

        router.post(
            markNotRequestRoute.url(conversation.id),
            { version: conversation.version },
            {
                preserveScroll: true,
                preserveState: true,
                onError: (errors) => {
                    toast.error(
                        conversationConflictFromErrors(errors)?.message ??
                            errors.conversation ??
                            Object.values(withoutConflictCode(errors))[0] ??
                            'No se pudo marcar como «No es solicitud». Inténtalo de nuevo.',
                    );
                },
                onFinish: () => (markingId.value = null),
            },
        );
    }

    return { markingId, markNotRequest };
}
