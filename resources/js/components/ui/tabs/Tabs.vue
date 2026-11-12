<script setup lang="ts">
import type { TabsRootEmits, TabsRootProps } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { reactiveOmit } from "@vueuse/core"
import { TabsRoot, useForwardPropsEmits } from "reka-ui"
import { cn } from "@/lib/utils"

/**
 * Tabs with an underline that slides to the active one.
 *
 *   <Tabs v-model="tab">
 *     <TabsList>
 *       <TabsTrigger value="general">General</TabsTrigger>
 *       <TabsTrigger value="sla">SLA</TabsTrigger>
 *     </TabsList>
 *     <TabsContent value="general">…</TabsContent>
 *   </Tabs>
 */
const props = defineProps<TabsRootProps & { class?: HTMLAttributes["class"] }>()
const emits = defineEmits<TabsRootEmits>()

const delegatedProps = reactiveOmit(props, "class")
const forwarded = useForwardPropsEmits(delegatedProps, emits)
</script>

<template>
  <TabsRoot
    data-slot="tabs"
    v-bind="forwarded"
    :class="cn('flex min-w-0 flex-col gap-4', props.class)"
  >
    <slot />
  </TabsRoot>
</template>
