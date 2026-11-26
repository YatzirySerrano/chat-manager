import { onBeforeUnmount, reactive } from 'vue';

/**
 * A file that is still on its way from the NAS (or a NAS answering slowly)
 * can fail to load once: the server answers 503 for a moment instead of
 * holding the request. Each media element asks again a few times, spaced
 * out, before it is shown as broken. The retry adds `r=N` to the address so
 * the browser does not reuse the failed answer.
 */
export const MEDIA_RETRY_DELAYS_MS = [4000, 10000, 20000, 40000];

export function useMediaRetry() {
    const attempts = reactive(new Map<string | number, number>());
    const timers = new Map<string | number, number>();

    function src(key: string | number, url: string | null | undefined): string {
        if (!url) {
            return '';
        }

        const attempt = attempts.get(key) ?? 0;

        if (attempt === 0) {
            return url;
        }

        const [base, hash] = url.split('#', 2);
        const separator = base.includes('?') ? '&' : '?';

        return `${base}${separator}r=${attempt}${hash !== undefined ? `#${hash}` : ''}`;
    }

    /**
     * Schedules another try and returns true, or returns false once every
     * try was used (the caller then shows the element as broken).
     */
    function retry(key: string | number): boolean {
        const attempt = attempts.get(key) ?? 0;

        if (attempt >= MEDIA_RETRY_DELAYS_MS.length) {
            return false;
        }

        window.clearTimeout(timers.get(key));
        timers.set(
            key,
            window.setTimeout(
                () => attempts.set(key, attempt + 1),
                MEDIA_RETRY_DELAYS_MS[attempt],
            ),
        );

        return true;
    }

    function reset(key: string | number): void {
        window.clearTimeout(timers.get(key));
        timers.delete(key);
        attempts.delete(key);
    }

    onBeforeUnmount(() => {
        timers.forEach((timer) => window.clearTimeout(timer));
    });

    return { src, retry, reset };
}
