<script setup lang="ts">
import type { PopoverContentEmits, PopoverContentProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import {
  PopoverContent,
  PopoverPortal,
  useForwardPropsEmits,
} from "reka-ui"
import { cn } from "@/lib/utils"

defineOptions({
  inheritAttrs: false,
})

const props = withDefaults(
  defineProps<PopoverContentProps & {
    class?: HTMLAttributes["class"]
    /** Caps the height to the space available in the viewport (scrolls inside). */
    fitViewport?: boolean
  }>(),
  {
    align: "center",
    sideOffset: 4,
    collisionPadding: 12,
    fitViewport: false,
  },
)
const emits = defineEmits<PopoverContentEmits>()

const delegatedProps = reactiveOmit(props, "class", "fitViewport")

const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <PopoverPortal>
    <PopoverContent
      data-slot="popover-content"
      v-bind="{ ...$attrs, ...forwarded }"
      :class="cn('bg-popover text-popover-foreground pop z-50 w-72 max-w-[calc(100vw-2rem)] origin-(--reka-popover-content-transform-origin) rounded-lg border p-4 shadow-popover outline-hidden', props.fitViewport && 'max-h-(--reka-popover-content-available-height) overflow-y-auto', props.class)"
    >
      <slot />
    </PopoverContent>
  </PopoverPortal>
</template>
