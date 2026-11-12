<script setup lang="ts">
import type { DateValue } from "@internationalized/date"
import type { DateRange } from "reka-ui"
import type { HTMLAttributes, Ref } from "vue"
import type { DateRangePreset } from "./dates"
import { CalendarRange, ChevronDown, ChevronLeft, ChevronRight, X } from "@lucide/vue"
import { useMediaQuery } from "@vueuse/core"
import {
  RangeCalendarCell,
  RangeCalendarCellTrigger,
  RangeCalendarGrid,
  RangeCalendarGridBody,
  RangeCalendarGridHead,
  RangeCalendarGridRow,
  RangeCalendarHeadCell,
  RangeCalendarHeader,
  RangeCalendarNext,
  RangeCalendarPrev,
  RangeCalendarRoot,
} from "reka-ui"
import { computed, ref, watch } from "vue"
import { fieldControl } from "@/components/ui/input"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
import { cn } from "@/lib/utils"
import {
  CALENDAR_LOCALE,
  DEFAULT_RANGE_PRESETS,
  formatDate,
  monthHeading,
  toCalendarDate,
  toIsoDate,
  usePlatformToday,
  WEEK_STARTS_ON,
} from "./dates"
import MonthYearJump from "./MonthYearJump.vue"

/**
 * Period field: one control for a "from – to" pair of dates, with
 * shortcuts (Hoy, Ayer, Últimos 7 días, Este mes, Mes pasado…).
 *
 *   <DateRangePicker v-model:from="filters.from" v-model:to="filters.to" />
 *
 * Both models are "YYYY-MM-DD" strings (`emptyValue` when cleared), exactly
 * what two <input type="date"> produced. `change` fires once per completed
 * range, so a list can reload on it.
 */
const props = withDefaults(
  defineProps<{
    min?: string | null
    max?: string | null
    placeholder?: string
    presets?: DateRangePreset[] | false
    emptyValue?: string | null
    required?: boolean
    disabled?: boolean
    invalid?: boolean
    id?: string
    ariaLabel?: string
    align?: "start" | "center" | "end"
    size?: "sm" | "default"
    class?: HTMLAttributes["class"]
  }>(),
  {
    min: undefined,
    max: undefined,
    placeholder: "Elige un periodo",
    presets: () => DEFAULT_RANGE_PRESETS,
    emptyValue: "",
    required: false,
    disabled: false,
    invalid: false,
    id: undefined,
    ariaLabel: undefined,
    align: "start",
    size: "default",
    class: undefined,
  },
)

const from = defineModel<string | null | undefined>("from")
const to = defineModel<string | null | undefined>("to")

const emit = defineEmits<{
  change: [range: { from: string | null, to: string | null }]
}>()

const open = ref(false)
const jumping = ref(false)
const twoMonths = useMediaQuery("(min-width: 48rem)")
const platformToday = usePlatformToday()

const minValue = computed(() => toCalendarDate(props.min))
const maxValue = computed(() => toCalendarDate(props.max))

/** Selection in progress inside the popover (committed when complete). */
// Casts: ref() would unwrap the private fields of the date classes.
const draft = ref({ start: undefined, end: undefined }) as Ref<DateRange>
const shownMonth = ref(platformToday()) as Ref<DateValue>

watch(open, (isOpen) => {
  if (!isOpen) {
    jumping.value = false

    return
  }

  const start = toCalendarDate(from.value)
  const end = toCalendarDate(to.value)

  draft.value = { start, end }
  shownMonth.value = start ?? platformToday()
})

function commit(start: DateValue | undefined, end: DateValue | undefined): void {
  const range = {
    from: start ? toIsoDate(start) : props.emptyValue,
    to: end ? toIsoDate(end) : props.emptyValue,
  }

  from.value = range.from
  to.value = range.to
  emit("change", range)
  open.value = false
}

function onDraft(range: DateRange): void {
  draft.value = range

  if (range.start && range.end) {
    commit(range.start, range.end)
  }
}

function clamp(date: DateValue): DateValue {
  if (minValue.value && date.compare(minValue.value) < 0) {
    return minValue.value
  }

  if (maxValue.value && date.compare(maxValue.value) > 0) {
    return maxValue.value
  }

  return date
}

function applyPreset(preset: DateRangePreset): void {
  const range = preset.range(platformToday())

  commit(clamp(range.from), clamp(range.to))
}

function isActivePreset(preset: DateRangePreset): boolean {
  const range = preset.range(platformToday())

  return toIsoDate(range.from) === from.value && toIsoDate(range.to) === to.value
}

function jumpTo(date: DateValue): void {
  shownMonth.value = date
  jumping.value = false
}

const label = computed(() => {
  const start = formatDate(from.value)
  const end = formatDate(to.value)

  if (start && end) {
    return start === end ? start : `${start} – ${end}`
  }

  return start ? `Desde ${start}` : end ? `Hasta ${end}` : ""
})

const canClear = computed(() => !props.required && !props.disabled && label.value !== "")

const navButton =
  "grid size-8 place-items-center rounded-md text-muted-foreground outline-none transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-35"

const dayClass = [
  "relative grid h-9 w-full place-items-center rounded-md text-sm tabular-nums outline-none select-none",
  "transition-[background-color,color] duration-(--duration-fast) ease-(--ease-standard)",
  "hover:bg-primary-soft hover:text-primary-soft-foreground",
  "focus-visible:ring-2 focus-visible:ring-ring",
  "data-[outside-view]:text-muted-foreground/45",
  "data-[today]:font-semibold data-[today]:after:absolute data-[today]:after:bottom-1 data-[today]:after:size-1 data-[today]:after:rounded-full data-[today]:after:bg-primary",
  "data-[highlighted]:rounded-none data-[highlighted]:bg-primary-soft/70",
  "data-[selected]:rounded-none data-[selected]:bg-primary-soft data-[selected]:text-primary-soft-foreground",
  "data-[selection-start]:rounded-l-md data-[selection-start]:bg-primary data-[selection-start]:font-semibold data-[selection-start]:text-primary-foreground data-[selection-start]:after:bg-primary-foreground",
  "data-[selection-end]:rounded-r-md data-[selection-end]:bg-primary data-[selection-end]:font-semibold data-[selection-end]:text-primary-foreground data-[selection-end]:after:bg-primary-foreground",
  // Days of the neighbouring month never look selected: each day is marked once.
  "data-[outside-view]:bg-transparent! data-[outside-view]:font-normal! data-[outside-view]:text-muted-foreground/45!",
  "data-[disabled]:pointer-events-none data-[disabled]:opacity-30 data-[unavailable]:pointer-events-none data-[unavailable]:line-through data-[unavailable]:opacity-40",
].join(" ")
</script>

<template>
  <Popover v-model:open="open">
    <div class="relative w-full min-w-0" data-slot="date-range-picker">
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
          <CalendarRange class="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
          <span class="min-w-0 truncate tabular-nums" :class="!label && 'text-muted-foreground'">
            {{ label || placeholder }}
          </span>
        </button>
      </PopoverTrigger>
      <button
        v-if="canClear"
        type="button"
        class="absolute top-1/2 right-2 grid size-6 -translate-y-1/2 place-items-center rounded-sm text-muted-foreground hover:bg-accent hover:text-foreground"
        aria-label="Borrar periodo"
        @click.stop="commit(undefined, undefined)"
      >
        <X class="size-3.5" aria-hidden="true" />
      </button>
    </div>

    <PopoverContent :align="align" class="flex w-auto flex-col gap-3 p-3 sm:flex-row" fit-viewport>
      <ul
        v-if="presets && presets.length"
        role="list"
        class="flex shrink-0 gap-1 overflow-x-auto sm:w-40 sm:flex-col sm:border-r sm:pr-3"
        aria-label="Atajos de periodo"
      >
        <li v-for="preset in presets" :key="preset.label" class="shrink-0">
          <button
            type="button"
            :aria-pressed="isActivePreset(preset)"
            class="h-8 w-full rounded-md px-2.5 text-left text-sm whitespace-nowrap outline-none transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring aria-pressed:bg-primary-soft aria-pressed:font-medium aria-pressed:text-primary-soft-foreground"
            @click="applyPreset(preset)"
          >
            {{ preset.label }}
          </button>
        </li>
      </ul>

      <RangeCalendarRoot
        v-slot="{ grid, weekDays }"
        :model-value="draft"
        v-model:placeholder="shownMonth"
        :locale="CALENDAR_LOCALE"
        :week-starts-on="WEEK_STARTS_ON"
        :number-of-months="twoMonths ? 2 : 1"
        :min-value="minValue"
        :max-value="maxValue"
        weekday-format="short"
        fixed-weeks
        data-slot="range-calendar"
        @update:model-value="onDraft"
      >
        <MonthYearJump
          v-if="jumping"
          class="w-[17.25rem]"
          :current="shownMonth"
          :min-value="minValue"
          :max-value="maxValue"
          @pick="jumpTo"
        />
        <template v-else>
          <div class="relative flex gap-5">
            <RangeCalendarHeader class="pointer-events-none absolute inset-x-0 top-0 flex justify-between *:pointer-events-auto">
              <RangeCalendarPrev :class="navButton" aria-label="Mes anterior">
                <ChevronLeft class="size-4" aria-hidden="true" />
              </RangeCalendarPrev>
              <RangeCalendarNext :class="navButton" aria-label="Mes siguiente">
                <ChevronRight class="size-4" aria-hidden="true" />
              </RangeCalendarNext>
            </RangeCalendarHeader>
            <div
              v-for="(month, monthIndex) in grid"
              :key="month.value.toString()"
              class="w-[17.25rem] max-w-full"
            >
              <div class="mb-2 grid h-8 place-items-center">
                <button
                  v-if="monthIndex === 0"
                  type="button"
                  class="flex h-8 items-center gap-1 rounded-md px-2.5 font-display text-sm font-semibold outline-none hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring"
                  aria-label="Elegir mes y año"
                  @click="jumping = true"
                >
                  {{ monthHeading(month.value) }}
                  <ChevronDown class="size-3.5 text-muted-foreground" aria-hidden="true" />
                </button>
                <p v-else class="font-display text-sm font-semibold">
                  {{ monthHeading(month.value) }}
                </p>
              </div>
              <RangeCalendarGrid class="w-full border-collapse select-none">
                <RangeCalendarGridHead>
                  <RangeCalendarGridRow class="grid grid-cols-7">
                    <RangeCalendarHeadCell
                      v-for="day in weekDays"
                      :key="day"
                      class="grid h-8 place-items-center text-xs font-medium text-muted-foreground capitalize"
                    >
                      {{ day.replace('.', '') }}
                    </RangeCalendarHeadCell>
                  </RangeCalendarGridRow>
                </RangeCalendarGridHead>
                <RangeCalendarGridBody>
                  <RangeCalendarGridRow
                    v-for="(week, index) in month.rows"
                    :key="index"
                    class="grid grid-cols-7"
                  >
                    <RangeCalendarCell
                      v-for="date in week"
                      :key="date.toString()"
                      :date="date"
                      class="grid py-px"
                    >
                      <RangeCalendarCellTrigger :day="date" :month="month.value" :class="dayClass" />
                    </RangeCalendarCell>
                  </RangeCalendarGridRow>
                </RangeCalendarGridBody>
              </RangeCalendarGrid>
            </div>
          </div>
          <p class="mt-2 border-t pt-2 text-xs text-muted-foreground">
            {{ draft.start && !draft.end ? 'Elige el último día del periodo.' : 'Elige el primer día y después el último.' }}
          </p>
        </template>
      </RangeCalendarRoot>
    </PopoverContent>
  </Popover>
</template>
