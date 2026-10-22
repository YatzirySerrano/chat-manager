import type { Ref } from 'vue';
import { fallbackSeconds, realtimeState } from '@/lib/realtime';
import type { RealtimeState } from '@/lib/realtime';

/**
 * State of the real-time connection of this tab, plus how often the current
 * page refreshes itself while it is not available.
 */
export function useRealtimeStatus(): {
    state: Readonly<Ref<RealtimeState>>;
    fallbackSeconds: Readonly<Ref<number | null>>;
} {
    return { state: realtimeState, fallbackSeconds };
}
