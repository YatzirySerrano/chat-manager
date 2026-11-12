import type { Component } from "vue"

export { default as AppSelect } from "./AppSelect.vue"

export type AppSelectValue = string | number | boolean | null

export interface AppSelectOption<V extends AppSelectValue = AppSelectValue> {
  value: V
  label: string
  /** Secondary line under the label (shown in the list only). */
  description?: string
  icon?: Component
  disabled?: boolean
  /** Options with the same group are listed together under this heading. */
  group?: string
}
