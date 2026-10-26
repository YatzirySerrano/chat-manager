<script lang="ts" setup>
import type { ToastClasses, ToasterProps } from "vue-sonner"
import { CircleCheckIcon, InfoIcon, Loader2Icon, OctagonXIcon, TriangleAlertIcon, XIcon } from "@lucide/vue"
import { computed, onBeforeUnmount, onMounted, ref } from "vue"
import { Toaster as Sonner } from "vue-sonner"
import { useAppearance } from "@/composables/useAppearance"
import { cn } from "@/lib/utils"

import 'vue-sonner/style.css';

const props = defineProps<ToasterProps>()

/**
 * vue-sonner defaults to its light theme, whose fixed grays (#3f3f3f
 * descriptions) were unreadable on the dark popover. The toaster follows
 * the theme the application really shows: the `.dark` class that
 * useAppearance toggles on <html> (it also covers "system" and changes made
 * in another tab or by the operating system while the page is open).
 */
const { resolvedAppearance } = useAppearance()
const theme = ref<"light" | "dark">(resolvedAppearance.value)
let observer: MutationObserver | null = null

function syncTheme(): void {
  theme.value = document.documentElement.classList.contains("dark") ? "dark" : "light"
}

onMounted(() => {
  syncTheme()
  observer = new MutationObserver(syncTheme)
  observer.observe(document.documentElement, { attributes: true, attributeFilter: ["class"] })
})

onBeforeUnmount(() => observer?.disconnect())

/**
 * Colors come from the design tokens, never from vue-sonner's grays. Its
 * stylesheet is not in a cascade layer, so these utilities are marked
 * important (`!`) to win over it. Title on popover ≥ 15:1, description
 * (muted-foreground) ≥ 5.5:1 and type icons ≥ 4.5:1 in light and dark.
 */
const tokenClasses: ToastClasses = {
  title: "text-popover-foreground!",
  description: "text-muted-foreground!",
  icon: "text-popover-foreground",
  success: "[&_[data-icon]]:text-success",
  info: "[&_[data-icon]]:text-info",
  warning: "[&_[data-icon]]:text-warning",
  error: "[&_[data-icon]]:text-destructive",
  closeButton:
    "border-border! bg-popover! text-popover-foreground! hover:border-border-strong! hover:bg-accent! focus-visible:outline-none! focus-visible:ring-2! focus-visible:ring-ring!",
  actionButton:
    "bg-primary! text-primary-foreground! transition-colors hover:bg-primary/90! focus-visible:outline-none! focus-visible:ring-2! focus-visible:ring-ring! focus-visible:ring-offset-2! focus-visible:ring-offset-popover!",
  cancelButton:
    "border! border-border! bg-transparent! text-popover-foreground! transition-colors hover:bg-accent! focus-visible:outline-none! focus-visible:ring-2! focus-visible:ring-ring!",
}

const toastOptions = computed(() => {
  const own = props.toastOptions?.classes ?? {}
  const keys = new Set([...Object.keys(tokenClasses), ...Object.keys(own)]) as Set<keyof ToastClasses>
  const classes: ToastClasses = {}

  for (const key of keys) {
    classes[key] = cn(tokenClasses[key], own[key])
  }

  return { ...props.toastOptions, classes }
})
</script>

<template>
  <Sonner
    :class="cn('toaster group', props.class)"
    :style="{
      '--normal-bg': 'var(--popover)',
      '--normal-text': 'var(--popover-foreground)',
      '--normal-border': 'var(--border)',
      '--normal-bg-hover': 'var(--accent)',
      '--normal-border-hover': 'var(--border-strong)',
      '--gray12': 'var(--popover-foreground)',
      '--gray4': 'var(--border)',
      '--gray5': 'var(--border-strong)',
      '--gray2': 'var(--accent)',
      '--border-radius': 'var(--radius)',
    }"
    v-bind="props"
    :theme="theme"
    :toast-options="toastOptions"
  >
    <template #success-icon>
      <CircleCheckIcon class="size-4" />
    </template>
    <template #info-icon>
      <InfoIcon class="size-4" />
    </template>
    <template #warning-icon>
      <TriangleAlertIcon class="size-4" />
    </template>
    <template #error-icon>
      <OctagonXIcon class="size-4" />
    </template>
    <template #loading-icon>
      <div>
        <Loader2Icon class="size-4 animate-spin" />
      </div>
    </template>
    <template #close-icon>
      <XIcon class="size-4 pointer-events-none" />
    </template>
  </Sonner>
</template>
