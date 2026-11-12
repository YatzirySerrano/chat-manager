<script setup lang="ts">
import type { SwitchRootEmits, SwitchRootProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import {
  SwitchRoot,
  SwitchThumb,
  useForwardPropsEmits,
} from "reka-ui"
import { cn } from "@/lib/utils"

const props = defineProps<SwitchRootProps & { class?: HTMLAttributes["class"] }>()

const emits = defineEmits<SwitchRootEmits>()

const delegatedProps = reactiveOmit(props, "class")

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <SwitchRoot
    v-slot="slotProps"
    data-slot="switch"
    v-bind="forwarded"
    :class="cn(
      'group/switch peer inline-flex h-5.5 w-10 shrink-0 items-center rounded-full border border-transparent p-0.5 outline-none',
      'bg-border-strong shadow-[inset_0_1px_2px_color-mix(in_oklch,var(--shadow-ink)_14%,transparent)]',
      'transition-[background-color,box-shadow] duration-(--duration-base) ease-(--ease-standard)',
      'hover:bg-[color-mix(in_oklch,var(--border-strong)_80%,var(--foreground))]',
      'data-[state=checked]:bg-primary data-[state=checked]:hover:bg-[color-mix(in_oklch,var(--primary)_90%,black)]',
      'focus-visible:ring-[3px] focus-visible:ring-primary/30 focus-visible:ring-offset-1 focus-visible:ring-offset-background',
      'disabled:cursor-not-allowed disabled:opacity-50',
      props.class,
    )"
  >
    <!-- The thumb overshoots slightly (spring) and stretches while pressed. -->
    <SwitchThumb
      data-slot="switch-thumb"
      :class="cn(
        'pointer-events-none block h-4 w-4 rounded-full bg-white shadow-[0_1px_2px_rgb(0_0_0/0.25)]',
        'transition-[translate,width] duration-(--duration-panel) ease-(--ease-spring)',
        'data-[state=unchecked]:translate-x-0 data-[state=checked]:translate-x-4.5',
        'group-active/switch:w-5 group-active/switch:data-[state=checked]:translate-x-3.5',
      )"
    >
      <slot name="thumb" v-bind="slotProps" />
    </SwitchThumb>
  </SwitchRoot>
</template>
