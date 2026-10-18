import { router } from '@inertiajs/vue3';

/**
 * Global rule for keeping visible data fresh after any operation that changes
 * information (create, edit, activate, assign, remove...):
 *
 * 1. Mutations redirect back, so Inertia reloads every prop of the current
 *    page (lists, counters, cards) keeping the query string: filters, search,
 *    sorting and pagination survive.
 * 2. Prefetched pages are cached for a while; after a mutation that cache is
 *    flushed, so navigating to another screen never shows outdated data.
 * 3. Returning to the tab reloads the shared auth props, so permissions that
 *    changed meanwhile are reflected in the navigation and actions.
 *
 * Inertia cancels a pending visit when a newer one starts, so a slow, older
 * response never overwrites a more recent result.
 */
export function initializeDataFreshness(): void {
    router.on('finish', (event) => {
        if (event.detail.visit.method !== 'get') {
            router.flushAll();
        }
    });

    let lastRefresh = Date.now();

    window.addEventListener('focus', () => {
        if (Date.now() - lastRefresh < 30_000 || !document.body) {
            return;
        }

        lastRefresh = Date.now();
        router.reload({ only: ['auth'] });
    });
}
