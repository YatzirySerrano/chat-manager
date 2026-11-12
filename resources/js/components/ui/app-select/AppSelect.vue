<script setup lang="ts" generic="V extends AppSelectValue">
import type { HTMLAttributes } from "vue"
import type { AppSelectOption, AppSelectValue } from "."
import { Check, ChevronDown, Search, X } from "@lucide/vue"
import {
  ComboboxAnchor,
  ComboboxContent,
  ComboboxEmpty,
  ComboboxGroup,
  ComboboxInput,
  ComboboxItem,
  ComboboxItemIndicator,
  ComboboxLabel,
  ComboboxPortal,
  ComboboxRoot,
  ComboboxTrigger,
  ComboboxViewport,
} from "reka-ui"
import { computed, ref } from "vue"
import { fieldControl } from "@/components/ui/input"
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectTrigger,
} from "@/components/ui/select"
import { cn } from "@/lib/utils"

/**
 * The platform's select. One simple API replaces a native <select>:
 *
 *   <AppSelect v-model="form.status" :options="statusOptions" />
 *
 * `options` are `{ value, label, description?, icon?, disabled?, group? }`;
 * any value type works ('' and null included, e.g. a "Todos" option), so
 * the model keeps exactly what the native select produced. With more than
 * nine options (or `searchable`) the list gets a search box. `clearable`
 * adds a button that sets the model to `clearValue` (null by default).
 */
const props = withDefaults(
  defineProps<{
    options: AppSelectOption<V>[]
    placeholder?: string
    /** Search box in the list; automatic above nine options. */
    searchable?: boolean
    searchPlaceholder?: string
    emptyText?: string
    clearable?: boolean
    clearValue?: V | null
    disabled?: boolean
    invalid?: boolean
    id?: string
    /** Renders a hidden input so native form posts carry the value. */
    name?: string
    size?: "sm" | "default"
    align?: "start" | "center" | "end"
    ariaLabel?: string
    /** Classes of the trigger (width, etc.). It is full width by default. */
    class?: HTMLAttributes["class"]
    contentClass?: HTMLAttributes["class"]
  }>(),
  {
    placeholder: "Selecciona una opción",
    searchable: undefined,
    searchPlaceholder: "Buscar…",
    emptyText: "Sin resultados",
    clearable: false,
    clearValue: null,
    disabled: false,
    invalid: false,
    id: undefined,
    name: undefined,
    size: "default",
    align: "start",
    ariaLabel: undefined,
    class: undefined,
    contentClass: undefined,
  },
)

const model = defineModel<V | null | undefined>()

const emit = defineEmits<{ change: [value: V | null | undefined] }>()

/** reka-ui rejects '' and null as item values: options travel by index. */
const keyOf = (index: number): string => `o${index}`

const selectedIndex = computed(() =>
  props.options.findIndex((option) => option.value === model.value),
)
const selected = computed(() => props.options[selectedIndex.value])
const selectedKey = computed(() =>
  selectedIndex.value >= 0 ? keyOf(selectedIndex.value) : undefined,
)

const withSearch = computed(
  () => props.searchable ?? props.options.length > 9,
)

const groups = computed(() => {
  const result: { label: string | null, items: { option: AppSelectOption<V>, key: string }[] }[] = []

  props.options.forEach((option, index) => {
    const label = option.group ?? null
    let group = result.find((candidate) => candidate.label === label)

    if (!group) {
      group = { label, items: [] }
      result.push(group)
    }

    group.items.push({ option, key: keyOf(index) })
  })

  return result
})

function pick(key: unknown): void {
  const index = typeof key === "string" ? Number(key.slice(1)) : -1
  const option = props.options[index]

  if (!option) {
    return
  }

  model.value = option.value
  emit("change", option.value)
}

function clear(): void {
  model.value = props.clearValue
  emit("change", props.clearValue)
}

const showClear = computed(
  () =>
    props.clearable &&
    !props.disabled &&
    selected.value !== undefined &&
    model.value !== props.clearValue,
)

const open = ref(false)
const search = ref("")

function onOpenChange(value: boolean): void {
  open.value = value

  if (!value) {
    search.value = ""
  }
}

const triggerClass = computed(() =>
  cn(
    fieldControl,
    "group/select-trigger flex w-full cursor-pointer items-center justify-between gap-2 px-3 py-2 text-left text-sm whitespace-nowrap",
    props.size === "sm" ? "h-8" : "h-(--control-h)",
    showClear.value && "pr-9",
    props.class,
  ),
)

const itemClass =
  "relative flex w-full cursor-default items-start gap-2 rounded-[6px] py-1.5 pr-8 pl-2 text-sm outline-hidden select-none transition-colors duration-100 data-[highlighted]:bg-primary-soft data-[highlighted]:text-primary-soft-foreground data-[state=checked]:font-medium data-[disabled]:pointer-events-none data-[disabled]:opacity-50 [&_svg]:pointer-events-none [&_svg]:shrink-0"
</script>

<template>
  <div class="relative w-full min-w-0" data-slot="app-select">
    <input
      v-if="name"
      type="hidden"
      :name="name"
      :value="model === null || model === undefined ? '' : String(model)"
    >

    <!-- Plain list: the reka-ui Select (typeahead, native-like keyboard). -->
    <Select
      v-if="!withSearch"
      :model-value="selectedKey"
      :disabled="disabled"
      @update:model-value="pick"
    >
      <SelectTrigger
        :id="id"
        :size="size"
        :aria-label="ariaLabel"
        :aria-invalid="invalid || undefined"
        :class="cn('w-full', showClear && 'pr-9', props.class)"
      >
        <span
          data-slot="select-value"
          class="min-w-0 truncate"
          :class="!selected && 'text-muted-foreground'"
        >
          <component :is="selected.icon" v-if="selected?.icon" class="size-4" aria-hidden="true" />
          <span class="truncate">{{ selected?.label ?? placeholder }}</span>
        </span>
      </SelectTrigger>
      <SelectContent :align="align" :class="contentClass">
        <SelectGroup v-for="(group, groupIndex) in groups" :key="groupIndex">
          <SelectLabel v-if="group.label">{{ group.label }}</SelectLabel>
          <SelectItem
            v-for="item in group.items"
            :key="item.key"
            :value="item.key"
            :disabled="item.option.disabled"
          >
            <component :is="item.option.icon" v-if="item.option.icon" class="size-4" aria-hidden="true" />
            <span class="min-w-0">
              <span class="block truncate">{{ item.option.label }}</span>
              <span
                v-if="item.option.description"
                class="block text-xs font-normal text-pretty whitespace-normal text-muted-foreground"
              >{{ item.option.description }}</span>
            </span>
          </SelectItem>
        </SelectGroup>
      </SelectContent>
    </Select>

    <!-- Long list: a combobox with the search box inside the list. -->
    <ComboboxRoot
      v-else
      :model-value="selectedKey"
      :open="open"
      :disabled="disabled"
      :reset-search-term-on-blur="true"
      @update:model-value="pick"
      @update:open="onOpenChange"
    >
      <ComboboxAnchor as-child>
        <ComboboxTrigger
          :id="id"
          :aria-label="ariaLabel"
          :aria-invalid="invalid || undefined"
          :data-state="open ? 'open' : 'closed'"
          :class="triggerClass"
        >
          <span
            class="flex min-w-0 items-center gap-2"
            :class="!selected && 'text-muted-foreground'"
          >
            <component :is="selected.icon" v-if="selected?.icon" class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
            <span class="truncate">{{ selected?.label ?? placeholder }}</span>
          </span>
          <ChevronDown
            class="size-4 shrink-0 text-muted-foreground opacity-60 transition-transform duration-(--duration-base) ease-(--ease-out-quint) group-data-[state=open]/select-trigger:rotate-180"
            aria-hidden="true"
          />
        </ComboboxTrigger>
      </ComboboxAnchor>

      <ComboboxPortal>
        <ComboboxContent
          position="popper"
          :align="align"
          :side-offset="4"
          :class="cn(
            'pop z-50 w-(--reka-combobox-trigger-width) min-w-56 origin-(--reka-combobox-content-transform-origin) overflow-hidden rounded-lg border bg-popover text-popover-foreground shadow-popover',
            contentClass,
          )"
        >
          <div class="flex items-center gap-2 border-b px-3">
            <Search class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
            <ComboboxInput
              v-model="search"
              :placeholder="searchPlaceholder"
              auto-focus
              class="h-10 w-full min-w-0 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
            />
          </div>
          <ComboboxViewport class="max-h-72 overflow-y-auto p-1">
            <ComboboxEmpty class="px-2 py-6 text-sm text-muted-foreground">
              {{ emptyText }}
            </ComboboxEmpty>
            <ComboboxGroup v-for="(group, groupIndex) in groups" :key="groupIndex">
              <ComboboxLabel
                v-if="group.label"
                class="px-2 py-1.5 text-xs font-semibold text-muted-foreground"
              >
                {{ group.label }}
              </ComboboxLabel>
              <ComboboxItem
                v-for="item in group.items"
                :key="item.key"
                :value="item.key"
                :text-value="item.option.label"
                :disabled="item.option.disabled"
                :class="itemClass"
              >
                <component :is="item.option.icon" v-if="item.option.icon" class="mt-0.5 size-4 text-muted-foreground" aria-hidden="true" />
                <span class="min-w-0">
                  <span class="block truncate">{{ item.option.label }}</span>
                  <span
                    v-if="item.option.description"
                    class="block text-xs font-normal text-pretty text-muted-foreground"
                  >{{ item.option.description }}</span>
                </span>
                <ComboboxItemIndicator class="absolute right-2 flex size-4 items-center justify-center">
                  <Check class="size-4 text-primary animate-in zoom-in-50 duration-150" aria-hidden="true" />
                </ComboboxItemIndicator>
              </ComboboxItem>
            </ComboboxGroup>
          </ComboboxViewport>
        </ComboboxContent>
      </ComboboxPortal>
    </ComboboxRoot>

    <button
      v-if="showClear"
      type="button"
      class="absolute top-1/2 right-8 grid size-6 -translate-y-1/2 place-items-center rounded-sm text-muted-foreground hover:bg-accent hover:text-foreground"
      aria-label="Quitar selección"
      @click.stop="clear"
    >
      <X class="size-3.5" aria-hidden="true" />
    </button>
  </div>
</template>
