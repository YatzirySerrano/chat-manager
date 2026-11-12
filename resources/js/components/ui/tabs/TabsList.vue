<script setup lang="ts">
import type { TabsListProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { TabsIndicator, TabsList } from "reka-ui"
import { cn } from "@/lib/utils"

const props = defineProps<TabsListProps & { class?: HTMLAttributes["class"] }>()

const delegatedProps = reactiveOmit(props, "class")
</script>

<template>
  <TabsList
    data-slot="tabs-list"
    v-bind="delegatedProps"
    :class="cn('relative flex max-w-full items-center gap-1 overflow-x-auto border-b', props.class)"
  >
    <slot />
    <!-- reka-ui measures the active tab into these two variables. -->
    <TabsIndicator
      class="absolute bottom-0 left-0 h-0.5 w-(--reka-tabs-indicator-size) translate-x-(--reka-tabs-indicator-position) rounded-full bg-primary transition-[width,translate] duration-(--duration-panel) ease-(--ease-out-quint)"
    />
  </TabsList>
</template>
