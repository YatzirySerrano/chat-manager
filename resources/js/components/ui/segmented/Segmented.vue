<script setup lang="ts" generic="V extends string | number | boolean | null">
import type { HTMLAttributes } from "vue"
import type { SegmentedOption } from "."
import { RadioGroupItem, RadioGroupRoot } from "reka-ui"
import { computed, ref, watch } from "vue"
import { useSlidingIndicator } from "@/composables/useSlidingIndicator"
import { cn } from "@/lib/utils"

/**
 * Segmented control: a few exclusive options side by side, with a thumb
 * that slides to the chosen one. Replaces short native radio groups and
 * two-or-three-way toggles. It is a radio group for keyboards and screen
 * readers (arrow keys move the choice).
 *
 *   <Segmented v-model="mode" :options="[{ value: 'table', label: 'Tabla' }, …]" />
 */
const props = withDefaults(
  defineProps<{
    options: SegmentedOption<V>[]
    ariaLabel?: string
    /** Only the icons are visible; the labels stay for screen readers. */
    iconOnly?: boolean
    size?: "sm" | "default"
    /** Stretch to the container, every option the same width. */
    block?: boolean
    disabled?: boolean
    class?: HTMLAttributes["class"]
  }>(),
  {
    ariaLabel: undefined,
    iconOnly: false,
    size: "default",
    block: false,
    disabled: false,
    class: undefined,
  },
)

const model = defineModel<V | undefined>()

const emit = defineEmits<{ change: [value: V] }>()

const keyOf = (index: number): string => `s${index}`
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

const group = ref<{ $el?: HTMLElement } | null>(null)
const root = computed(() => group.value?.$el ?? null)
const { style, animated, update } = useSlidingIndicator(root, "[data-state=checked]")

watch([selectedKey, () => props.options.length], update, { flush: "post" })
</script>

<template>
  <RadioGroupRoot
    ref="group"
    :model-value="selectedKey"
    :disabled="disabled"
    orientation="horizontal"
    :aria-label="ariaLabel"
    data-slot="segmented"
    :class="cn(
      'relative isolate inline-flex max-w-full items-center rounded-lg border bg-surface-sunken p-0.5',
      'shadow-[inset_0_1px_1.5px_color-mix(in_oklch,var(--shadow-ink)_7%,transparent)]',
      block && 'flex w-full',
      props.class,
    )"
    @update:model-value="pick"
  >
      <span
        aria-hidden="true"
        class="pointer-events-none absolute top-0 left-0 -z-10 rounded-md border border-border-strong/60 bg-card shadow-(--shadow-control)"
        :class="animated && 'transition-[transform,width,height,opacity] duration-(--duration-panel) ease-(--ease-out-quint)'"
        :style="style"
      />
      <RadioGroupItem
        v-for="(option, index) in options"
        :key="index"
        :value="keyOf(index)"
        :disabled="option.disabled"
        :aria-label="iconOnly ? option.label : undefined"
        :title="iconOnly ? option.label : undefined"
        :class="cn(
          'inline-flex shrink-0 cursor-pointer items-center justify-center gap-1.5 rounded-md text-sm font-medium whitespace-nowrap text-muted-foreground outline-none select-none',
          'transition-colors duration-(--duration-fast) hover:text-foreground data-[state=checked]:text-foreground',
          'focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-45',
          '[&_svg]:size-4 [&_svg]:shrink-0',
          size === 'sm' ? 'h-7 px-2.5' : 'h-8 px-3 pointer-coarse:h-9',
          iconOnly && (size === 'sm' ? 'w-7 px-0' : 'w-8 px-0 pointer-coarse:w-9'),
          block && 'min-w-0 flex-1',
        )"
      >
        <component :is="option.icon" v-if="option.icon" aria-hidden="true" />
        <span v-if="!iconOnly" class="truncate">{{ option.label }}</span>
      </RadioGroupItem>
  </RadioGroupRoot>
</template>
