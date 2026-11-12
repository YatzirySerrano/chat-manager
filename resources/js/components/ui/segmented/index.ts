import type { Component } from "vue"

export { default as Segmented } from "./Segmented.vue"

export interface SegmentedOption<T> {
  value: T
  label: string
  icon?: Component
  disabled?: boolean
}
