<script setup lang="ts">
import type { CheckboxRootEmits, CheckboxRootProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { CheckboxIndicator, CheckboxRoot, useForwardPropsEmits } from "reka-ui"
import { cn } from "@/lib/utils"

const props = defineProps<CheckboxRootProps & { class?: HTMLAttributes["class"] }>()
const emits = defineEmits<CheckboxRootEmits>()

const delegatedProps = reactiveOmit(props, "class")

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <CheckboxRoot
    v-slot="slotProps"
    data-slot="checkbox"
    v-bind="forwarded"
    :class="
      cn('peer size-4.5 shrink-0 rounded-[5px] border border-border-strong bg-surface-sunken outline-none',
         'shadow-[inset_0_1px_1.5px_color-mix(in_oklch,var(--shadow-ink)_8%,transparent)]',
         'transition-[background-color,border-color,box-shadow,scale] duration-(--duration-fast) ease-(--ease-standard)',
         'hover:border-primary/70 active:scale-90',
         'data-[state=checked]:border-primary data-[state=checked]:bg-primary data-[state=checked]:text-primary-foreground data-[state=checked]:shadow-none',
         'data-[state=indeterminate]:border-primary data-[state=indeterminate]:bg-primary data-[state=indeterminate]:text-primary-foreground',
         'focus-visible:border-primary focus-visible:ring-[3px] focus-visible:ring-primary/25',
         'aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40',
         'disabled:cursor-not-allowed disabled:opacity-50',
         props.class)"
  >
    <CheckboxIndicator
      data-slot="checkbox-indicator"
      class="grid place-content-center text-current"
    >
      <slot v-bind="slotProps">
        <!-- The mark is drawn, not swapped in: stroke-dashoffset 1 → 0. -->
        <svg viewBox="0 0 16 16" fill="none" class="size-3.5" aria-hidden="true">
          <path
            :d="slotProps.state === 'indeterminate' ? 'M4 8h8' : 'M3.6 8.4l2.9 2.9 5.9-6.4'"
            pathLength="1"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-dasharray="1"
            class="animate-check"
          />
        </svg>
      </slot>
    </CheckboxIndicator>
  </CheckboxRoot>
</template>
