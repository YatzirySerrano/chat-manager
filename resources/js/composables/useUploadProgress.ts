import { computed } from 'vue';
import type { ComputedRef } from 'vue';

type ProgressingRequest = {
    progress: { percentage?: number } | null;
    cancel: () => void;
};

/**
 * Progress and cancellation of a file upload sent with useHttp, shared by
 * the customer reply composer and the team chat composer. `active` says
 * whether files are being uploaded right now. The upload can be cancelled
 * while its bytes are still travelling; once they all arrived the server
 * may already have stored the message. `wasCancelled()` tells (once) that
 * the failure that follows was the user's own cancellation.
 */
export function useUploadProgress(
    http: ProgressingRequest,
    active: () => boolean,
): {
    progress: ComputedRef<number | null>;
    canCancel: ComputedRef<boolean>;
    cancel: () => void;
    wasCancelled: () => boolean;
} {
    let cancelled = false;

    const progress = computed(() => {
        const percentage = http.progress?.percentage;

        return active() && percentage !== undefined
            ? Math.round(percentage)
            : null;
    });

    const canCancel = computed(() => active() && (progress.value ?? 0) < 100);

    function cancel(): void {
        if (!canCancel.value) {
            return;
        }

        cancelled = true;
        http.cancel();
    }

    function wasCancelled(): boolean {
        const was = cancelled;
        cancelled = false;

        return was;
    }

    return { progress, canCancel, cancel, wasCancelled };
}
