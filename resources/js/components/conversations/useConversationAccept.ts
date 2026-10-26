import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import {
    conversationConflictFromErrors,
    withoutConflictCode,
} from '@/lib/conversations/actionErrors';
import { accept as acceptRoute } from '@/routes/conversations';

/**
 * Accepting a pending conversation from the inbox list, through the same
 * route (and transactional action) the chat header uses. Only one accept
 * runs at a time, so a double click never sends two requests.
 *
 * The server answers with the conversation open (the list, counters and
 * chat come in the same visit) or, when a colleague was faster or it was
 * closed, with the fresh list and the conflict (409: `errors.conversation`
 * and its code, actionErrors.ts); without the permission, with the 403
 * explanation as a toast.
 * Accepted from the general inbox, the row stays in its place with its
 * new status; from "Pendientes", the server opens the general inbox, so
 * the row never vanishes. The list is never patched or merged here: it is
 * always the server's.
 */
export function useConversationAccept() {
    /** Conversation being accepted, if any. */
    const acceptingId = ref<number | null>(null);

    function accept(
        id: number,
        options: { onSuccess?: () => void } = {},
    ): void {
        if (acceptingId.value !== null) {
            return;
        }

        acceptingId.value = id;

        router.post(
            acceptRoute.url(id),
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => options.onSuccess?.(),
                onError: (errors) => {
                    toast.error(
                        conversationConflictFromErrors(errors)?.message ??
                            errors.conversation ??
                            Object.values(withoutConflictCode(errors))[0] ??
                            'No se pudo aceptar la conversación. Inténtalo de nuevo.',
                    );
                },
                onFinish: () => (acceptingId.value = null),
            },
        );
    }

    return { acceptingId, accept };
}
