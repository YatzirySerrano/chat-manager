<script setup lang="ts">
import {
    ClipboardCheck,
    CornerDownRight,
    ListOrdered,
    Timer,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import { computed, reactive } from 'vue';
import FieldHelp from '@/components/FieldHelp.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import {
    formatServiceRuleValue,
    serviceRuleBlocks,
    serviceRuleCopy,
    serviceRuleLabel,
    serviceRuleSourceLabel,
} from '@/lib/serviceRules';
import type { ServiceRuleBlockId } from '@/lib/serviceRules';
import type {
    ServiceRuleKey,
    ServiceRuleLevel,
    ServiceRuleSet,
    ServiceRuleValue,
    ServiceRuleValues,
} from '@/types';

/**
 * The service rules of one level (platform, department or category), always
 * in the same three blocks so nobody confuses a maximum response time with
 * the confirmation of a complete request or with the minimum wait.
 *
 * It only edits values: the parent owns the form and how it is saved.
 * - Platform: `values` holds every rule (an empty objective = none).
 * - Department / category: `values` holds the level's own value of each
 *   rule, null where it inherits. Each rule shows «Usa el valor general: X»
 *   or «Valor propio» and a control to switch between both.
 *
 * Remount it (change its `key`) when the parent discards or reloads the
 * form, so the rows that were being customized follow the new values.
 */
const props = withDefaults(
    defineProps<{
        level: ServiceRuleLevel;
        rules: ServiceRuleSet;
        values: ServiceRuleValues;
        errors?: Partial<Record<ServiceRuleKey, string>>;
        /** Read-only presentation (the person cannot change the rules). */
        readonly?: boolean;
        /** Prefix of the element ids, unique per card on the page. */
        idPrefix?: string;
    }>(),
    {
        errors: () => ({}),
        readonly: false,
        idPrefix: 'service-rule',
    },
);

const emit = defineEmits<{
    update: [key: ServiceRuleKey, value: ServiceRuleValue];
}>();

const blockIcons: Record<ServiceRuleBlockId, LucideIcon> = {
    sla: Timer,
    intake: ClipboardCheck,
    queue: ListOrdered,
};

const isPlatform = computed(() => props.level === 'platform');

/*
 * Only the rules the server sent: while phase two is off the platform
 * level receives just the two report objectives.
 */
const blocks = computed(() =>
    serviceRuleBlocks
        .map((block) => ({
            ...block,
            keys: block.keys.filter((key) => key in props.rules),
        }))
        .filter((block) => block.keys.length > 0),
);

/**
 * Rules the level is customizing. Kept apart from the value so that
 * emptying a number while typing does not flip the row back to "inherits".
 */
const customized = reactive(
    new Set<ServiceRuleKey>(
        (Object.keys(props.rules) as ServiceRuleKey[]).filter(
            (key) =>
                props.values[key] !== null && props.values[key] !== undefined,
        ),
    ),
);

function elementId(key: ServiceRuleKey): string {
    return `${props.idPrefix}-${key.replace(/[._]/g, '-')}`;
}

function inheritedText(key: ServiceRuleKey): string {
    const rule = props.rules[key];

    return formatServiceRuleValue(key, rule, rule.inherited_value);
}

function currentText(key: ServiceRuleKey): string {
    const rule = props.rules[key];
    const own = props.values[key];

    return formatServiceRuleValue(
        key,
        rule,
        isPlatform.value || (own !== null && own !== undefined)
            ? (own ?? null)
            : rule.inherited_value,
    );
}

function customize(key: ServiceRuleKey): void {
    const rule = props.rules[key];

    customized.add(key);
    emit(
        'update',
        key,
        rule.inherited_value ??
            (rule.type === 'boolean'
                ? false
                : (serviceRuleCopy[key].suggested ?? rule.min ?? 0)),
    );
}

function inherit(key: ServiceRuleKey): void {
    customized.delete(key);
    emit('update', key, null);
}

function setNumber(key: ServiceRuleKey, raw: string | number): void {
    if (raw === '' || raw === null) {
        emit('update', key, null);

        return;
    }

    const parsed = Number(raw);

    emit('update', key, Number.isFinite(parsed) ? parsed : null);
}

function numberValue(key: ServiceRuleKey): string | number {
    const value = props.values[key];

    return typeof value === 'number' ? value : '';
}

function emptyHint(key: ServiceRuleKey): string | null {
    const rule = props.rules[key];

    if (isPlatform.value) {
        return rule.nullable ? 'Déjalo vacío para no fijar un objetivo.' : null;
    }

    return `Si lo dejas vacío se usa ${serviceRuleSourceLabel(rule.inherited_source)}.`;
}
</script>

<template>
    <div class="grid gap-4" data-service-rules :data-level="level">
        <section
            v-for="block in blocks"
            :key="block.id"
            class="overflow-hidden rounded-lg border"
            :aria-labelledby="`${idPrefix}-block-${block.id}`"
            :data-service-rules-block="block.id"
        >
            <header class="flex items-start gap-3 border-b bg-muted/40 p-4">
                <span
                    class="flex size-8 shrink-0 items-center justify-center rounded-md bg-primary-soft text-primary"
                >
                    <component
                        :is="blockIcons[block.id]"
                        class="size-4"
                        aria-hidden="true"
                    />
                </span>
                <div class="min-w-0 space-y-1">
                    <h3
                        :id="`${idPrefix}-block-${block.id}`"
                        class="text-sm font-semibold"
                    >
                        {{ block.title }}
                    </h3>
                    <p class="text-sm text-pretty text-muted-foreground">
                        {{ block.description }}
                    </p>
                    <p class="text-xs text-pretty text-muted-foreground">
                        <span class="font-medium text-foreground"
                            >Cuándo aplica un cambio:</span
                        >
                        {{ block.effect }}
                    </p>
                </div>
            </header>

            <ul class="divide-y">
                <li
                    v-for="key in block.keys"
                    :key="key"
                    class="grid gap-2 p-4"
                    :data-service-rule="key"
                >
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <div class="flex min-w-0 items-center gap-1.5">
                            <Label
                                v-if="
                                    rules[key].type === 'integer' &&
                                    !readonly &&
                                    rules[key].overridable &&
                                    (isPlatform || customized.has(key))
                                "
                                :for="elementId(key)"
                            >
                                {{ serviceRuleLabel(rules[key]) }}
                            </Label>
                            <span
                                v-else
                                :id="`${elementId(key)}-label`"
                                class="text-sm leading-none font-medium"
                            >
                                {{ serviceRuleLabel(rules[key]) }}
                            </span>
                            <FieldHelp
                                :title="rules[key].label"
                                :help="serviceRuleCopy[key].help"
                            />
                        </div>
                        <span
                            v-if="
                                !isPlatform &&
                                rules[key].overridable &&
                                customized.has(key)
                            "
                            class="rounded-full border border-primary/40 bg-primary-soft px-2 py-0.5 text-xs font-medium text-primary"
                            data-service-rule-state="own"
                        >
                            Valor propio
                        </span>
                    </div>

                    <!-- Read-only, or a rule this level cannot set. -->
                    <p
                        v-if="readonly || !rules[key].overridable"
                        class="text-sm text-muted-foreground"
                    >
                        <template v-if="!rules[key].overridable">
                            {{
                                level === 'category'
                                    ? 'Lo define el departamento de cada solicitud o la configuración general, no la categoría. Valor general:'
                                    : 'Se define en la configuración general:'
                            }}
                        </template>
                        <template
                            v-else-if="!isPlatform && !customized.has(key)"
                        >
                            Usa
                            {{
                                serviceRuleSourceLabel(
                                    rules[key].inherited_source,
                                )
                            }}:
                        </template>
                        <span class="font-medium text-foreground">{{
                            currentText(key)
                        }}</span>
                    </p>

                    <!-- Inheriting: what applies and how to customize it. -->
                    <div
                        v-else-if="!isPlatform && !customized.has(key)"
                        class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-dashed px-3 py-2"
                        data-service-rule-state="inherited"
                    >
                        <p
                            class="flex min-w-0 items-start gap-1.5 text-sm text-muted-foreground"
                        >
                            <CornerDownRight
                                class="mt-0.5 size-4 shrink-0"
                                aria-hidden="true"
                            />
                            <span>
                                Usa
                                {{
                                    serviceRuleSourceLabel(
                                        rules[key].inherited_source,
                                    )
                                }}:
                                <span class="font-medium text-foreground">{{
                                    inheritedText(key)
                                }}</span>
                            </span>
                        </p>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            :aria-label="`Personalizar: ${rules[key].label}`"
                            @click="customize(key)"
                        >
                            Personalizar
                        </Button>
                    </div>

                    <!-- Editing the value (platform, or the level's own). -->
                    <template v-else>
                        <div
                            class="flex flex-wrap items-center justify-between gap-2"
                        >
                            <label
                                v-if="rules[key].type === 'boolean'"
                                class="flex min-w-0 flex-1 items-center justify-between gap-3 rounded-lg border px-3 py-2 text-sm"
                            >
                                <span class="text-muted-foreground">{{
                                    currentText(key)
                                }}</span>
                                <Switch
                                    :model-value="values[key] === true"
                                    :aria-labelledby="`${elementId(key)}-label`"
                                    @update:model-value="
                                        (value: boolean) =>
                                            emit('update', key, value)
                                    "
                                />
                            </label>
                            <Input
                                v-else
                                :id="elementId(key)"
                                :model-value="numberValue(key)"
                                type="number"
                                inputmode="numeric"
                                :min="rules[key].min ?? undefined"
                                :max="rules[key].max ?? undefined"
                                step="1"
                                class="w-full sm:max-w-40"
                                :aria-invalid="errors[key] ? true : undefined"
                                :aria-describedby="
                                    errors[key]
                                        ? `${elementId(key)}-error`
                                        : `${elementId(key)}-hint`
                                "
                                @update:model-value="
                                    (value) => setNumber(key, value)
                                "
                            />
                            <Button
                                v-if="!isPlatform"
                                type="button"
                                variant="ghost"
                                size="sm"
                                :aria-label="`Usar ${serviceRuleSourceLabel(rules[key].inherited_source)}: ${rules[key].label}`"
                                @click="inherit(key)"
                            >
                                Usar
                                {{
                                    serviceRuleSourceLabel(
                                        rules[key].inherited_source,
                                    )
                                }}
                            </Button>
                        </div>
                        <p
                            v-if="
                                !errors[key] &&
                                (rules[key].type === 'integer' || !isPlatform)
                            "
                            :id="`${elementId(key)}-hint`"
                            class="text-xs text-muted-foreground"
                        >
                            <template v-if="rules[key].type === 'integer'">
                                De {{ rules[key].min }} a {{ rules[key].max }}.
                                {{ emptyHint(key) }}
                            </template>
                            <template v-if="!isPlatform">
                                Reemplaza a
                                {{
                                    serviceRuleSourceLabel(
                                        rules[key].inherited_source,
                                    )
                                }}: {{ inheritedText(key) }}.
                            </template>
                        </p>
                    </template>

                    <InputError
                        :id="`${elementId(key)}-error`"
                        :message="errors[key]"
                    />
                </li>
            </ul>
        </section>
    </div>
</template>
