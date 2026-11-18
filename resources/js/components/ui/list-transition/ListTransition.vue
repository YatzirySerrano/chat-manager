<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

/**
 * Animated list: wraps <TransitionGroup name="list"> (styles in app.css).
 * New items fade in, removed items fade out and the rest slide into place;
 * everything is instant under prefers-reduced-motion. Children need a
 * stable `:key`. The container is `relative` so leaving items can be taken
 * out of the flow while the others move.
 */
const props = withDefaults(
  defineProps<{
    tag?: string
    class?: HTMLAttributes["class"]
  }>(),
  {
    tag: "div",
    class: undefined,
  },
)
</script>

<template>
  <TransitionGroup name="list" :tag="props.tag" :class="cn('relative', props.class)">
    <slot />
  </TransitionGroup>
</template>
