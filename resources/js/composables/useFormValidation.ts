import type { InertiaForm } from '@inertiajs/vue3';
import { computed, reactive, ref, toValue, watch } from 'vue';
import type { MaybeRefOrGetter } from 'vue';
import { normalizeValue, valueAt } from '@/lib/validation';
import type { FieldRules, ValueNormalizer } from '@/lib/validation';

type FormValues = Record<string, unknown>;

export type FieldBindings = {
    'aria-invalid': boolean;
    'aria-describedby': string | undefined;
    onBlur: () => void;
};

export type FormValidationOptions = {
    /** Rules per field path; a getter lets them depend on the form state. */
    rules?: MaybeRefOrGetter<FieldRules>;
    /** Comparison form per top-level field (default: normalizeValue). */
    normalize?: Record<string, ValueNormalizer>;
    /** Top-level fields that never count as a change (helper flags). */
    ignore?: string[];
    /**
     * Whether submitting requires an effective change (edit forms). When
     * true, `canSubmit` stays false while the data matches the baseline.
     */
    requireChanges?: MaybeRefOrGetter<boolean>;
};

export type FormValidation = {
    /** Message to show for the field: the server error, or the live one once the field was touched. */
    error: (path: string) => string | undefined;
    /**
     * ARIA attributes and blur handler to spread on the control (`v-bind`).
     * `describedBy` adds ids of permanent help (hints, checklists).
     */
    field: (path: string, id?: string, describedBy?: string) => FieldBindings;
    /** Mark the field as touched so its live errors are shown. */
    touch: (path: string) => void;
    /** Load new values as the unchanged baseline (opening an edit dialog). */
    load: (values: FormValues) => void;
    /** Use the current values as the new baseline (after saving). */
    commit: () => void;
    /** Show every error; returns whether the form can be submitted. */
    validate: () => boolean;
    readonly hasChanges: boolean;
    readonly isValid: boolean;
    readonly requiresChanges: boolean;
    readonly canSubmit: boolean;
};

/**
 * Error keys that belong to a field: the field itself and its list items
 * ("department_ids" owns "department_ids.0").
 */
function belongsTo(key: string, path: string): boolean {
    return (
        key === path ||
        new RegExp(`^${path.replace(/\./g, '\\.')}\\.\\d+$`).test(key)
    );
}

/**
 * Path whose value an error key describes: "department_ids.2" is about the
 * whole list.
 */
function subjectOf(key: string): string {
    return key.replace(/\.\d+$/, '');
}

/**
 * Real-time validation and change tracking on top of an Inertia useForm.
 *
 * - No field turns red before the user interacts with it: text fields show
 *   their live errors once they were edited and left (blur), controls with
 *   discrete values (selects, checkboxes, lists) as soon as they change,
 *   and every field after a submit attempt. From then on errors update on
 *   every input.
 * - Server errors stay visible until the user changes that field.
 * - `hasChanges` compares normalized values (trimmed text, null vs "",
 *   sorted id lists, numbers vs numeric strings), so reverting a field
 *   returns the form to "no changes".
 * - The server keeps validating everything; these rules only give feedback.
 */
export function useFormValidation<TForm extends object>(
    form: InertiaForm<TForm>,
    options: FormValidationOptions = {},
): FormValidation {
    const touched = ref(new Set<string>());
    const submitted = ref(false);
    const fieldIds = new Map<string, string>();
    const serverSnapshots = new Map<string, string>();

    const data = (): FormValues => form.data() as FormValues;

    function comparable(values: FormValues): string {
        const ignored = new Set(options.ignore ?? []);

        return JSON.stringify(
            Object.keys(values)
                .filter((key) => !ignored.has(key))
                .sort()
                .map((key) => [
                    key,
                    (options.normalize?.[key] ?? normalizeValue)(values[key]),
                ]),
        );
    }

    const baseline = ref(comparable(data()));
    /** Fields the user edited at some point (blurring them shows errors). */
    const edited = new Set<string>();

    /** Exact value at a path: any edit, even whitespace, counts. */
    const serializedAt = (path: string): string =>
        JSON.stringify(valueAt(data(), path) ?? null);

    const snapshot = (): FormValues =>
        JSON.parse(JSON.stringify(data())) as FormValues;

    const rules = computed(() => toValue(options.rules) ?? {});

    const clientErrors = computed<Record<string, string>>(() => {
        const values = data();
        const messages: Record<string, string> = {};

        for (const [path, fieldRules] of Object.entries(rules.value)) {
            const value = valueAt(values, path);

            for (const rule of fieldRules) {
                const message = rule(value, values);

                if (message) {
                    messages[path] = message;
                    break;
                }
            }
        }

        return messages;
    });

    const serverErrors = computed(
        () => form.errors as Record<string, string | undefined>,
    );

    function serverError(path: string): string | undefined {
        const key = Object.keys(serverErrors.value).find((name) =>
            belongsTo(name, path),
        );

        return key ? serverErrors.value[key] : undefined;
    }

    function isShown(path: string): boolean {
        return submitted.value || touched.value.has(path);
    }

    function error(path: string): string | undefined {
        return (
            serverError(path) ??
            (isShown(path) ? clientErrors.value[path] : undefined)
        );
    }

    function touch(path: string): void {
        if (!touched.value.has(path)) {
            touched.value = new Set([...touched.value, path]);
        }
    }

    function field(
        path: string,
        id?: string,
        describedBy?: string,
    ): FieldBindings {
        if (id) {
            fieldIds.set(path, id);
        }

        const message = error(path);
        const ids = [message && id ? `${id}-error` : undefined, describedBy]
            .filter(Boolean)
            .join(' ');

        return {
            'aria-invalid': Boolean(message),
            'aria-describedby': ids === '' ? undefined : ids,
            // Leaving a field without editing it never turns it red.
            onBlur: () => {
                if (edited.has(path)) {
                    touch(path);
                }
            },
        };
    }

    // Server errors: remember the value each one described, and clear the
    // error as soon as the user changes that value.
    watch(
        serverErrors,
        (errors) => {
            for (const key of serverSnapshots.keys()) {
                if (!(key in errors)) {
                    serverSnapshots.delete(key);
                }
            }

            for (const key of Object.keys(errors)) {
                if (!serverSnapshots.has(key)) {
                    serverSnapshots.set(key, serializedAt(subjectOf(key)));
                }
            }
        },
        { immediate: true, deep: true },
    );

    // Discrete controls (selects, checkboxes, lists, numbers) count as
    // touched as soon as their value changes; text waits for the blur.
    let previous = snapshot();

    watch(
        () => JSON.stringify(data()),
        () => {
            const current = data();

            for (const path of Object.keys(rules.value)) {
                const after = valueAt(current, path);

                if (
                    JSON.stringify(after) ===
                    JSON.stringify(valueAt(previous, path))
                ) {
                    continue;
                }

                edited.add(path);

                if (typeof after !== 'string') {
                    touch(path);
                }
            }

            const stale = [...serverSnapshots.entries()]
                .filter(
                    ([key, snapshot]) =>
                        serializedAt(subjectOf(key)) !== snapshot,
                )
                .map(([key]) => key);

            if (stale.length > 0) {
                (form.clearErrors as (...fields: string[]) => void)(...stale);
            }

            previous = snapshot();
        },
    );

    // Inertia stores the submitted data as the new defaults on success; the
    // baseline follows it so the saved values count as "no changes".
    watch(
        () => form.wasSuccessful,
        (successful) => {
            if (successful) {
                commit();
            }
        },
    );

    function reset(): void {
        touched.value = new Set();
        submitted.value = false;
        edited.clear();
    }

    function load(values: FormValues): void {
        form.clearErrors();
        form.defaults(values as Partial<TForm>);
        form.reset();
        previous = snapshot();
        baseline.value = comparable(data());
        reset();
    }

    function commit(): void {
        baseline.value = comparable(data());
        reset();
    }

    const hasChanges = computed(() => comparable(data()) !== baseline.value);
    const isValid = computed(
        () => Object.keys(clientErrors.value).length === 0,
    );
    const requiresChanges = computed(() =>
        Boolean(toValue(options.requireChanges)),
    );
    const canSubmit = computed(
        () => isValid.value && (!requiresChanges.value || hasChanges.value),
    );

    function validate(): boolean {
        submitted.value = true;

        if (!isValid.value) {
            const firstInvalid = Object.keys(rules.value).find(
                (path) => clientErrors.value[path],
            );
            const id = firstInvalid ? fieldIds.get(firstInvalid) : undefined;

            if (id) {
                document.getElementById(id)?.focus();
            }

            return false;
        }

        return canSubmit.value;
    }

    return reactive({
        error,
        field,
        touch,
        load,
        commit,
        validate,
        hasChanges,
        isValid,
        requiresChanges,
        canSubmit,
    }) as FormValidation;
}
