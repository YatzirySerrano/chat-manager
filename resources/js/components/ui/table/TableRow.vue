<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <tr data-slot="table-row" :class="cn('row-accent hover:bg-primary-soft/45 data-[state=selected]:bg-primary-soft/70 border-b transition-colors duration-(--duration-fast)', props.class)">
    <slot />
  </tr>
</template>
