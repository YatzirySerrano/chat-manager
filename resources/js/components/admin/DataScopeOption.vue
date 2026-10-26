<script setup lang="ts">
import { CircleCheck } from '@lucide/vue';
import { computed, useId } from 'vue';
import { scopeIcon } from '@/lib/roles';
import { cn } from '@/lib/utils';
import type { ScopeOption } from '@/types';

/**
 * One data scope («Toda la organización», «Sus departamentos», «Solo lo
 * asignado») with its icon, name, what it reaches and an example.
 *
 * As a choice it is a `role="radio"` button meant to live inside a
 * `role="radiogroup"` that manages the arrow keys (roving tabindex: only the
 * `tabbable` option is in the tab order). Its accessible name is only the
 * scope's label; the description, the example and the reason it cannot be
 * chosen are its description, so arrow keys read a short name first. A
 * disabled option dims only its icon and title: the reason stays at full
 * contrast. With `readonly` it is a plain description of the scope a role
 * has.
 */
const props = defineProps<{
    option: ScopeOption;
    selected?: boolean;
    disabled?: boolean;
    /** Visible reason shown when the option cannot be chosen. */
    disabledReason?: string;
    readonly?: boolean;
    /** Whether the option is the one reachable with Tab inside its group. */
    tabbable?: boolean;
}>();

const emit = defineEmits<{ select: [] }>();

const icon = computed(() => scopeIcon(props.option.value));

const baseId = useId();
const labelId = `${baseId}-label`;
const descriptionId = `${baseId}-description`;
const exampleId = `${baseId}-example`;
const reasonId = `${baseId}-reason`;
const describedBy = computed(() =>
    [
        descriptionId,
        exampleId,
        props.disabled && props.disabledReason ? reasonId : null,
    ]
        .filter(Boolean)
        .join(' '),
);
</script>

<template>
    <div
        v-if="readonly"
        class="flex min-w-0 items-start gap-3 rounded-lg bg-surface-sunken p-3"
    >
        <span
            class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary-soft text-primary-soft-foreground"
            aria-hidden="true"
        >
            <component :is="icon" class="size-5" />
        </span>
        <div class="min-w-0 space-y-0.5">
            <p class="text-sm font-semibold text-foreground">
                <span class="sr-only">Alcance: </span>{{ option.label }}
            </p>
            <p class="text-sm text-pretty text-muted-foreground">
                {{ option.description }}
            </p>
        </div>
    </div>

    <button
        v-else
        type="button"
        role="radio"
        :aria-checked="selected ? 'true' : 'false'"
        :aria-disabled="disabled ? 'true' : undefined"
        :aria-labelledby="labelId"
        :aria-describedby="describedBy"
        :tabindex="tabbable ? 0 : -1"
        :data-scope="option.value"
        :class="
            cn(
                'relative flex h-full min-w-0 flex-col gap-2 rounded-xl border bg-card p-3.5 text-left transition-[background-color,border-color,box-shadow] duration-(--duration-fast) ease-(--ease-standard) outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                selected
                    ? 'border-primary bg-primary-soft/50 ring-1 ring-primary'
                    : !disabled && 'interactive-surface',
                disabled && 'cursor-not-allowed border-dashed',
            )
        "
        @click="!disabled && emit('select')"
    >
        <span class="flex items-start gap-3 pr-6">
            <span
                class="flex size-9 shrink-0 items-center justify-center rounded-lg"
                :class="[
                    selected
                        ? 'bg-primary text-primary-foreground'
                        : 'bg-muted text-muted-foreground',
                    disabled && 'opacity-60',
                ]"
                aria-hidden="true"
            >
                <component :is="icon" class="size-5" />
            </span>
            <span
                :id="labelId"
                class="min-w-0 pt-1.5 text-sm font-semibold text-foreground"
                :class="disabled && 'opacity-60'"
            >
                {{ option.label }}
            </span>
        </span>
        <CircleCheck
            v-if="selected"
            class="absolute top-3 right-3 size-5 text-primary"
            aria-hidden="true"
        />
        <span
            :id="descriptionId"
            class="text-sm text-pretty text-muted-foreground"
        >
            {{ option.description }}
        </span>
        <span
            :id="exampleId"
            class="mt-auto rounded-md bg-surface-sunken px-2.5 py-2 text-xs text-pretty text-muted-foreground"
        >
            <span class="font-medium text-foreground">Ejemplo:</span>
            {{ option.example }}
        </span>
        <span
            v-if="disabled && disabledReason"
            :id="reasonId"
            class="text-xs font-medium text-foreground"
        >
            {{ disabledReason }}
        </span>
    </button>
</template>
