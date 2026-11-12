<script setup lang="ts" generic="V extends string | number | boolean | null">
import type { HTMLAttributes } from "vue"
import type { RadioCardOption } from "."
import { RadioGroupItem, RadioGroupRoot } from "reka-ui"
import { computed } from "vue"
import { cn } from "@/lib/utils"

/**
 * Exclusive choice presented as cards (title, optional description and
 * icon). Replaces native radio buttons when each option needs a line of
 * explanation.
 *
 *   <RadioCards v-model="form.mode" :options="modes" aria-label="Modo" />
 *
 * The cards flow in as many columns as fit (`--col-min`, 14rem by default;
 * pass `class="grid-cols-1"` to stack them).
 */
const props = withDefaults(
  defineProps<{
    options: RadioCardOption<V>[]
    ariaLabel?: string
    /** Renders a hidden input so native form posts carry the value. */
    name?: string
    disabled?: boolean
    invalid?: boolean
    class?: HTMLAttributes["class"]
  }>(),
  {
    ariaLabel: undefined,
    name: undefined,
    disabled: false,
    invalid: false,
    class: undefined,
  },
)

const model = defineModel<V | undefined>()

const emit = defineEmits<{ change: [value: V] }>()

const keyOf = (index: number): string => `r${index}`
const selectedKey = computed(() => {
  const index = props.options.findIndex((option) => option.value === model.value)

  return index >= 0 ? keyOf(index) : undefined
})

function pick(key: unknown): void {
  const option = props.options[typeof key === "string" ? Number(key.slice(1)) : -1]

  if (option) {
    model.value = option.value
    emit("change", option.value)
  }
}
</script>

<template>
  <RadioGroupRoot
    :model-value="selectedKey"
    :disabled="disabled"
    :aria-label="ariaLabel"
    :aria-invalid="invalid || undefined"
    data-slot="radio-cards"
    :class="cn('auto-grid [--col-min:14rem] [--grid-gap:0.625rem]', props.class)"
    @update:model-value="pick"
  >
    <input
      v-if="name"
      type="hidden"
      :name="name"
      :value="model === null || model === undefined ? '' : String(model)"
    >
    <RadioGroupItem
      v-for="(option, index) in options"
      :key="index"
      :value="keyOf(index)"
      :disabled="option.disabled"
      :class="cn(
        'group/radio-card relative flex min-w-0 cursor-pointer items-start gap-3 rounded-lg border bg-card p-3.5 text-left outline-none',
        'transition-[background-color,border-color,box-shadow,translate] duration-(--duration-base) ease-(--ease-out-quint)',
        'hover:border-[color-mix(in_oklch,var(--primary)_45%,var(--border-strong))] hover:-translate-y-px hover:shadow-hover',
        'focus-visible:ring-[3px] focus-visible:ring-primary/25',
        'data-[state=checked]:border-primary data-[state=checked]:bg-primary-soft/55 data-[state=checked]:shadow-[inset_0_0_0_1px_var(--primary)]',
        'disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:translate-y-0 disabled:hover:shadow-none',
      )"
    >
      <span
        v-if="option.icon"
        class="grid size-9 shrink-0 place-items-center rounded-md bg-surface-sunken text-muted-foreground transition-colors duration-(--duration-base) group-data-[state=checked]/radio-card:bg-primary group-data-[state=checked]/radio-card:text-primary-foreground"
      >
        <component :is="option.icon" class="size-4.5" aria-hidden="true" />
      </span>
      <span class="min-w-0 flex-1 space-y-0.5">
        <span class="block text-sm font-semibold text-foreground">{{ option.label }}</span>
        <span
          v-if="option.description"
          class="block text-xs text-pretty text-muted-foreground"
        >{{ option.description }}</span>
      </span>
      <span
        aria-hidden="true"
        class="mt-0.5 grid size-4.5 shrink-0 place-items-center rounded-full border border-border-strong bg-surface-sunken transition-colors duration-(--duration-fast) group-data-[state=checked]/radio-card:border-primary group-data-[state=checked]/radio-card:bg-primary"
      >
        <span
          class="size-1.5 scale-0 rounded-full bg-primary-foreground transition-transform duration-(--duration-base) ease-(--ease-spring) group-data-[state=checked]/radio-card:scale-100"
        />
      </span>
    </RadioGroupItem>
  </RadioGroupRoot>
</template>
