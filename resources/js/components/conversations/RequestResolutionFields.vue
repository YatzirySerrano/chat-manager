<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { BadgeDollarSign } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { RESOLUTION_FIELDS } from '@/components/conversations/requestFields';
import { Input } from '@/components/ui/input';
import { formatMoney, normalizeAmount } from '@/lib/outcomes';
import { update as updateRoute } from '@/routes/conversations/request-fields';
import type { ConversationSummary } from '@/types';

/**
 * «Resolución» inside «Datos de la solicitud»: «Monto autorizado»,
 * «Desembolso» and «Cantidad a pagar» as small money inputs typed once,
 * right in the panel. Each one saves on its own when it loses focus or on
 * Enter (Esc undoes the edit); emptying it removes the field. They are
 * also taken from the authorization the team sends («Se autoriza …») and
 * the quick replies fill their «Monto autorizado: $» lines from them.
 * Read-only for whoever may not keep request data (can.categorize).
 */
const props = defineProps<{
    conversation: ConversationSummary;
}>();

type Key = (typeof RESOLUTION_FIELDS)[number]['key'];

const canEdit = computed(() => props.conversation.can.categorize === true);
const stored = computed(() => props.conversation.request_fields ?? []);

function storedValue(key: Key): string {
    return stored.value.find((field) => field.key === key)?.value ?? '';
}

const drafts = reactive<Record<Key, string>>({
    monto_autorizado: '',
    desembolso: '',
    cantidad_a_pagar: '',
});
const errors = reactive<Partial<Record<Key, string>>>({});
const focused = ref<Key | null>(null);

/* The panel refreshes (another viewer, an authorization sent): the fields not being typed follow it. */
watch(
    stored,
    () => {
        for (const { key } of RESOLUTION_FIELDS) {
            if (focused.value !== key) {
                drafts[key] = storedValue(key);
            }
        }
    },
    { immediate: true, deep: true },
);

const hasAny = computed(() =>
    RESOLUTION_FIELDS.some(({ key }) => storedValue(key) !== ''),
);

/** The amount as people read it («$3,000»), '' when empty, null when invalid. */
function money(input: string): string | null {
    const normalized = normalizeAmount(input);

    if (normalized === null || normalized === '') {
        return normalized;
    }

    return formatMoney(normalized);
}

function save(key: Key, label: string): void {
    focused.value = null;

    const next = money(drafts[key]);
    const current = storedValue(key);

    if (next === null) {
        errors[key] = 'Solo números, por ejemplo 3000 o 3,000.50.';

        return;
    }

    errors[key] = undefined;
    drafts[key] = next;

    if (next === current || (next !== '' && money(current) === next)) {
        return;
    }

    if (next === '' && current === '') {
        return;
    }

    // Emptied: the whole list without it; otherwise only this field is merged.
    const payload =
        next === ''
            ? {
                  mode: 'replace',
                  fields: stored.value
                      .filter((field) => field.key !== key)
                      .map((field) => ({
                          label: field.label,
                          value: field.value,
                          message_id: field.message_id,
                      })),
              }
            : { mode: 'merge', fields: [{ label, value: next }] };

    router.put(updateRoute.url(props.conversation.id), payload, {
        preserveScroll: true,
        preserveState: true,
        onError: (failure) => {
            drafts[key] = current;
            toast.error(
                Object.values(failure)[0] ??
                    'No se pudo guardar. Inténtalo de nuevo.',
            );
        },
    });
}

function undo(key: Key, event: KeyboardEvent): void {
    drafts[key] = storedValue(key);
    errors[key] = undefined;
    focused.value = null;
    (event.target as HTMLInputElement | null)?.blur();
}
</script>

<template>
    <div
        v-if="canEdit || hasAny"
        class="grid gap-1.5 rounded-lg border border-dashed px-3 py-2.5"
        data-request-resolution
    >
        <h4
            class="flex items-center gap-1.5 text-xs font-medium text-muted-foreground"
        >
            <BadgeDollarSign class="size-3.5" aria-hidden="true" />
            Resolución
        </h4>
        <div
            class="grid grid-cols-[auto_minmax(0,1fr)] items-center gap-x-3 gap-y-1"
        >
            <template v-for="field in RESOLUTION_FIELDS" :key="field.key">
                <label
                    :for="`resolution-${conversation.id}-${field.key}`"
                    class="text-xs text-muted-foreground"
                    >{{ field.label }}</label
                >
                <div v-if="canEdit" class="relative min-w-0">
                    <span
                        class="pointer-events-none absolute inset-y-0 left-2 grid place-items-center text-xs text-muted-foreground"
                        aria-hidden="true"
                        >$</span
                    >
                    <Input
                        :id="`resolution-${conversation.id}-${field.key}`"
                        :model-value="drafts[field.key].replace(/^\$/, '')"
                        inputmode="decimal"
                        autocomplete="off"
                        maxlength="16"
                        placeholder="—"
                        class="h-7 pl-5 text-sm font-medium"
                        :aria-invalid="Boolean(errors[field.key])"
                        :title="errors[field.key]"
                        :data-resolution-input="field.key"
                        @update:model-value="
                            (value) => (drafts[field.key] = String(value))
                        "
                        @focus="focused = field.key"
                        @blur="save(field.key, field.label)"
                        @keydown.enter.prevent="
                            ($event.target as HTMLInputElement).blur()
                        "
                        @keydown.esc.stop.prevent="undo(field.key, $event)"
                    />
                </div>
                <span
                    v-else
                    :id="`resolution-${conversation.id}-${field.key}`"
                    class="min-w-0 font-medium break-words"
                    >{{ storedValue(field.key) || '—' }}</span
                >
            </template>
        </div>
        <p
            v-for="field in RESOLUTION_FIELDS.filter(
                (item) => errors[item.key],
            )"
            :key="`error-${field.key}`"
            class="text-xs text-destructive"
            role="alert"
        >
            {{ field.label }}: {{ errors[field.key] }}
        </p>
    </div>
</template>
