<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <thead data-slot="table-header" :class="cn('bg-surface-sunken/70 [&_tr]:border-b [&_tr]:border-border-strong/70 [&_tr]:hover:bg-transparent [&_tr>:first-child]:before:hidden', props.class)">
    <slot />
  </thead>
</template>
