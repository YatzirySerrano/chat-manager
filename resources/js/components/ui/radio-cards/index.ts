import type { Component } from "vue"

export { default as RadioCards } from "./RadioCards.vue"

export interface RadioCardOption<T> {
  value: T
  label: string
  description?: string
  icon?: Component
  disabled?: boolean
}
