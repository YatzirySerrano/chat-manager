import { useHttp } from '@inertiajs/vue3';
import { onBeforeUnmount, ref, watch } from 'vue';
import { EMAIL_PATTERN, toText } from '@/lib/validation';
import type { FieldRule } from '@/lib/validation';
import { emailAvailability } from '@/routes/admin/users';

const DEBOUNCE_MS = 450;

/**
 * Debounced check that an email is not used by another account, for the
 * user form. Only well-formed addresses different from the account's
 * current one are checked; a failed or throttled check shows nothing
 * because the unique rule of the server is authoritative.
 */
export function useEmailAvailability(options: {
    email: () => string;
    /** Account being edited (excluded from the check). */
    userId: () => number | null;
    /** Address the account already has (never checked). */
    currentEmail: () => string | null;
    enabled: () => boolean;
}) {
    const http = useHttp<
        { email: string; user_id: number | null },
        { available: boolean }
    >({ email: '', user_id: null });
    const takenEmail = ref<string | null>(null);
    const checking = ref(false);
    let timer: ReturnType<typeof setTimeout> | undefined;

    const normalized = (value: string): string => value.trim().toLowerCase();

    async function check(email: string): Promise<void> {
        http.email = email;
        http.user_id = options.userId();
        checking.value = true;

        try {
            const result = await http.post(emailAvailability.url());

            if (normalized(options.email()) === email) {
                takenEmail.value = result.available ? null : email;
            }
        } catch {
            // The server validates the address again when saving.
        } finally {
            checking.value = false;
        }
    }

    watch(
        () => [options.email(), options.enabled()] as const,
        ([value, enabled]) => {
            clearTimeout(timer);
            const email = normalized(value);

            if (
                !enabled ||
                !EMAIL_PATTERN.test(email) ||
                email === normalized(options.currentEmail() ?? '')
            ) {
                checking.value = false;

                return;
            }

            if (takenEmail.value === email) {
                return;
            }

            timer = setTimeout(() => void check(email), DEBOUNCE_MS);
        },
    );

    onBeforeUnmount(() => clearTimeout(timer));

    /** Rule that reports the conflict for the address that was checked. */
    const rule: FieldRule = (value) =>
        takenEmail.value !== null &&
        normalized(toText(value)) === takenEmail.value
            ? 'Ya existe una cuenta con este correo electrónico.'
            : null;

    return { rule, checking };
}
