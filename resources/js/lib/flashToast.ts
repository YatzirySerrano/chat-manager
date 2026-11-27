import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import type { FlashToast } from '@/types/ui';

/** How long a toast that offers an operation (e.g. «Deshacer») stays visible. */
const OPERATION_TOAST_DURATION = 10000;

export function initializeFlashToast(): void {
    router.on('flash', (event) => {
        const flash = (event as CustomEvent).detail?.flash;
        const data = flash?.toast as FlashToast | undefined;

        if (!data) {
            return;
        }

        const action = data.action;
        // A link is visited; an operation is sent with its method and data.
        const operation = !!action?.method && action.method !== 'get';

        toast[data.type](
            data.message,
            action
                ? {
                      ...(operation
                          ? { duration: OPERATION_TOAST_DURATION }
                          : {}),
                      action: {
                          label: action.label,
                          onClick: () =>
                              operation
                                  ? router.visit(action.href, {
                                        method: action.method,
                                        data: action.data ?? {},
                                        preserveScroll: true,
                                    })
                                  : router.visit(action.href),
                      },
                  }
                : undefined,
        );
    });
}
