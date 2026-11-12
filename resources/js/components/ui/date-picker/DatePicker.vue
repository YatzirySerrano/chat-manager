<script setup lang="ts">
import type { DateValue } from "@internationalized/date"
import type { HTMLAttributes } from "vue"
import { CalendarDays, X } from "@lucide/vue"
import { computed, ref } from "vue"
import { Button } from "@/components/ui/button"
import { fieldControl } from "@/components/ui/input"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
import { cn } from "@/lib/utils"
import Calendar from "./Calendar.vue"
import { formatDate, toCalendarDate, toIsoDate, usePlatformToday } from "./dates"

/**
 * Date field of the platform; replaces <input type="date">.
 *
 *   <DatePicker v-model="form.due_date" />
 *
 * The model is the same "YYYY-MM-DD" string the native input produced
 * (`emptyValue`, '' by default, when cleared), so requests and validation
 * do not change. `min` / `max` are ISO dates too.
 */
const props = withDefaults(
  defineProps<{
    min?: string | null
    max?: string | null
    placeholder?: string
    /** Value of the model when the date is cleared. */
    emptyValue?: string | null
    /** Hides the clear button and the "Borrar" action. */
    required?: boolean
    disabled?: boolean
    invalid?: boolean
    id?: string
    /** Renders a hidden input so native form posts carry the value. */
    name?: string
    ariaLabel?: string
    align?: "start" | "center" | "end"
    size?: "sm" | "default"
    class?: HTMLAttributes["class"]
  }>(),
  {
    min: undefined,
    max: undefined,
    placeholder: "Elige una fecha",
    emptyValue: "",
    required: false,
    disabled: false,
    invalid: false,
    id: undefined,
    name: undefined,
    ariaLabel: undefined,
    align: "start",
    size: "default",
    class: undefined,
  },
)

const model = defineModel<string | null | undefined>()

const emit = defineEmits<{ change: [value: string | null] }>()

const open = ref(false)
const platformToday = usePlatformToday()

const minValue = computed(() => toCalendarDate(props.min))
const maxValue = computed(() => toCalendarDate(props.max))

const date = computed<DateValue | undefined>({
  get: () => toCalendarDate(model.value),
  set: (value) => commit(value ? toIsoDate(value) : props.emptyValue),
})

const label = computed(() => formatDate(model.value))

function commit(value: string | null): void {
  model.value = value
  emit("change", value)
  open.value = false
}

const todayAllowed = computed(() => {
  const day = platformToday()

  return (
    (!minValue.value || day.compare(minValue.value) >= 0) &&
    (!maxValue.value || day.compare(maxValue.value) <= 0)
  )
})

const canClear = computed(() => !props.required && !props.disabled && label.value !== "")
</script>

<template>
  <Popover v-model:open="open">
    <div class="relative w-full min-w-0" data-slot="date-picker">
      <input v-if="name" type="hidden" :name="name" :value="model ?? ''">
      <PopoverTrigger as-child>
        <button
          :id="id"
          type="button"
          :disabled="disabled"
          :aria-label="ariaLabel"
          :aria-invalid="invalid || undefined"
          :class="cn(
            fieldControl,
            'flex cursor-pointer items-center gap-2 px-3 text-left text-sm whitespace-nowrap',
            size === 'sm' ? 'h-8' : 'h-(--control-h)',
            canClear && 'pr-9',
            props.class,
          )"
        >
          <CalendarDays class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
          <span class="min-w-0 truncate tabular-nums" :class="!label && 'text-muted-foreground'">
            {{ label || placeholder }}
          </span>
        </button>
      </PopoverTrigger>
      <button
        v-if="canClear"
        type="button"
        class="absolute top-1/2 right-2 grid size-6 -translate-y-1/2 place-items-center rounded-sm text-muted-foreground hover:bg-accent hover:text-foreground"
        aria-label="Borrar fecha"
        @click.stop="commit(emptyValue)"
      >
        <X class="size-3.5" aria-hidden="true" />
      </button>
    </div>

    <PopoverContent :align="align" class="w-auto p-3">
      <Calendar v-model="date" :min-value="minValue" :max-value="maxValue" />
      <div class="mt-2 flex items-center justify-between gap-2 border-t pt-2">
        <Button
          type="button"
          variant="ghost"
          size="sm"
          :disabled="!todayAllowed"
          @click="commit(toIsoDate(platformToday()))"
        >
          Hoy
        </Button>
        <Button
          v-if="!required"
          type="button"
          variant="ghost"
          size="sm"
          class="text-muted-foreground"
          :disabled="!label"
          @click="commit(emptyValue)"
        >
          Borrar
        </Button>
      </div>
    </PopoverContent>
  </Popover>
</template>
