import { computed } from 'vue';
import type { ComputedRef, InjectionKey } from 'vue';
import type { AcceptBlock } from '@/types';

/**
 * The viewer's control of new conversations (PendingAttention), provided by
 * the inbox to every «Aceptar» button (list rows and the chat header), so
 * all of them are disabled for the same reason at the same time. The server
 * enforces it again on every accept.
 */
export const ACCEPT_BLOCK: InjectionKey<ComputedRef<AcceptBlock | null>> =
    Symbol('accept-block');

export const noAcceptBlock = computed<AcceptBlock | null>(() => null);
