import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import {
    flattenServiceRules,
    ownServiceRules,
    serviceRuleErrors,
    writeServiceRule,
} from '@/lib/serviceRules';
import type { RouteDefinition } from '@/wayfinder';
import type {
    ServiceRuleGroups,
    ServiceRuleKey,
    ServiceRuleLevel,
    ServiceRuleSet,
    ServiceRuleValue,
} from '@/types';

/**
 * Form of the service rules a department or a category overrides. It holds
 * the level's own values grouped like the settings (`rules.sla.…`), null
 * where the level inherits, and sends them as they are: the server replaces
 * the whole set, so a rule left as null goes back to inheriting.
 */
export function useServiceRulesForm(
    rules: () => ServiceRuleSet,
    level: ServiceRuleLevel,
) {
    const form = useForm<{ rules: ServiceRuleGroups }>({
        rules: ownServiceRules(rules(), level),
    });

    /** Bumped whenever the form is reloaded, to remount the card. */
    const revision = ref(0);

    /** Shown only after trying to save, like the other forms. */
    const attempted = ref(false);

    const values = computed(() => flattenServiceRules(rules(), form.rules));

    const clientErrors = computed(() =>
        serviceRuleErrors(rules(), values.value, level),
    );

    const errors = computed(() => {
        const merged: Partial<Record<ServiceRuleKey, string>> = attempted.value
            ? { ...clientErrors.value }
            : {};
        const serverErrors = form.errors as Record<string, string | undefined>;

        for (const key of Object.keys(rules()) as ServiceRuleKey[]) {
            const message = serverErrors[`rules.${key}`];

            if (message) {
                merged[key] = message;
            }
        }

        return merged;
    });

    /** An error that belongs to no single rule (e.g. a rule the level may not set). */
    const generalError = computed(() => {
        const serverErrors = form.errors as Record<string, string | undefined>;

        return (
            serverErrors.rules ??
            serverErrors['rules.sla'] ??
            serverErrors['rules.intake'] ??
            serverErrors['rules.queue']
        );
    });

    function update(key: ServiceRuleKey, value: ServiceRuleValue): void {
        writeServiceRule(form.rules, key, value);
    }

    function load(): void {
        form.defaults({ rules: ownServiceRules(rules(), level) });
        form.reset();
        form.clearErrors();
        attempted.value = false;
        revision.value++;
    }

    // The page reloaded with other rules (saved here or elsewhere): follow
    // them unless the person is in the middle of an edit.
    watch(rules, () => {
        if (!form.isDirty) {
            load();
        }
    });

    function submit(
        action: RouteDefinition<'put'>,
        onSuccess?: () => void,
    ): void {
        attempted.value = true;

        if (Object.keys(clientErrors.value).length > 0) {
            return;
        }

        form.submit(action, {
            preserveScroll: true,
            onSuccess: () => {
                load();
                onSuccess?.();
            },
        });
    }

    return {
        form,
        values,
        errors,
        generalError,
        revision,
        update,
        load,
        submit,
    };
}
