<script setup lang="ts">
import type { TabsTriggerProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { TabsTrigger, useForwardProps } from "reka-ui"
import { cn } from "@/lib/utils"

const props = defineProps<TabsTriggerProps & { class?: HTMLAttributes["class"] }>()

const delegatedProps = reactiveOmit(props, "class")
const forwardedProps = useForwardProps(delegatedProps)
</script>

<template>
  <TabsTrigger
    data-slot="tabs-trigger"
    v-bind="forwardedProps"
    :class="cn(
      'inline-flex h-10 shrink-0 cursor-pointer items-center gap-2 rounded-t-md px-3 text-sm font-medium whitespace-nowrap text-muted-foreground outline-none select-none',
      'transition-colors duration-(--duration-fast) hover:bg-accent/60 hover:text-foreground',
      'data-[state=active]:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset',
      'disabled:pointer-events-none disabled:opacity-45 [&_svg]:size-4 [&_svg]:shrink-0',
      props.class,
    )"
  >
    <slot />
  </TabsTrigger>
</template>
