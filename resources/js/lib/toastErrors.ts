import { toast } from 'vue-sonner';

/**
 * Show the first validation error of a request that has no form to display
 * it (record actions such as deleting or restoring).
 */
export function toastFirstError(errors: Record<string, string>): void {
    const message = Object.values(errors)[0];

    if (message) {
        toast.error(message);
    }
}
