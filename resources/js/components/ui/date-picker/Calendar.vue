<script setup lang="ts">
import type { DateValue } from "@internationalized/date"
import type { HTMLAttributes, Ref } from "vue"
import { ChevronDown, ChevronLeft, ChevronRight } from "@lucide/vue"
import {
  CalendarCell,
  CalendarCellTrigger,
  CalendarGrid,
  CalendarGridBody,
  CalendarGridHead,
  CalendarGridRow,
  CalendarHeadCell,
  CalendarHeader,
  CalendarNext,
  CalendarPrev,
  CalendarRoot,
} from "reka-ui"
import { ref, watch } from "vue"
import { cn } from "@/lib/utils"
import { CALENDAR_LOCALE, monthHeading, usePlatformToday, WEEK_STARTS_ON } from "./dates"
import MonthYearJump from "./MonthYearJump.vue"

/**
 * Month calendar for one date (the panel of DatePicker; also usable inline).
 * Spanish, weeks start on Monday, full keyboard support from reka-ui
 * (arrows, PageUp/PageDown for months, Shift+PageUp/Down for years). The
 * month heading opens a quick jump to any month or year.
 */
const props = defineProps<{
  minValue?: DateValue
  maxValue?: DateValue
  class?: HTMLAttributes["class"]
}>()

const model = defineModel<DateValue | undefined>()

const platformToday = usePlatformToday()
// Cast: ref() would unwrap the private fields of the date classes.
const placeholder = ref(model.value ?? platformToday()) as Ref<DateValue>
const jumping = ref(false)

watch(model, (value) => {
  if (value) {
    placeholder.value = value
  }
})

function jumpTo(date: DateValue): void {
  placeholder.value = date
  jumping.value = false
}

const navButton =
  "grid size-8 place-items-center rounded-md text-muted-foreground outline-none transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-35"

const dayClass = [
  "relative grid size-9 place-items-center rounded-md text-sm tabular-nums outline-none select-none",
  "transition-[background-color,color,scale] duration-(--duration-fast) ease-(--ease-standard)",
  "hover:bg-primary-soft hover:text-primary-soft-foreground active:scale-90",
  "focus-visible:ring-2 focus-visible:ring-ring",
  "data-[outside-view]:text-muted-foreground/45",
  "data-[today]:font-semibold data-[today]:after:absolute data-[today]:after:bottom-1 data-[today]:after:size-1 data-[today]:after:rounded-full data-[today]:after:bg-primary",
  "data-[selected]:bg-primary data-[selected]:font-semibold data-[selected]:text-primary-foreground data-[selected]:hover:bg-primary data-[selected]:hover:text-primary-foreground data-[selected]:after:bg-primary-foreground",
  "data-[disabled]:pointer-events-none data-[disabled]:opacity-30 data-[unavailable]:pointer-events-none data-[unavailable]:line-through data-[unavailable]:opacity-40",
].join(" ")
</script>

<template>
  <CalendarRoot
    v-slot="{ grid, weekDays }"
    v-model="model"
    v-model:placeholder="placeholder"
    :locale="CALENDAR_LOCALE"
    :week-starts-on="WEEK_STARTS_ON"
    :min-value="minValue"
    :max-value="maxValue"
    weekday-format="short"
    fixed-weeks
    data-slot="calendar"
    :class="cn('w-[17.25rem] max-w-full', props.class)"
  >
    <MonthYearJump
      v-if="jumping"
      :current="placeholder"
      :min-value="minValue"
      :max-value="maxValue"
      @pick="jumpTo"
    />
    <template v-else>
      <CalendarHeader class="mb-2 flex items-center justify-between gap-1">
        <CalendarPrev :class="navButton" aria-label="Mes anterior">
          <ChevronLeft class="size-4" aria-hidden="true" />
        </CalendarPrev>
        <button
          type="button"
          class="flex h-8 items-center gap-1 rounded-md px-2.5 font-display text-sm font-semibold outline-none hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring"
          aria-label="Elegir mes y año"
          @click="jumping = true"
        >
          {{ monthHeading(placeholder) }}
          <ChevronDown class="size-3.5 text-muted-foreground" aria-hidden="true" />
        </button>
        <CalendarNext :class="navButton" aria-label="Mes siguiente">
          <ChevronRight class="size-4" aria-hidden="true" />
        </CalendarNext>
      </CalendarHeader>

      <CalendarGrid
        v-for="month in grid"
        :key="month.value.toString()"
        class="w-full border-collapse select-none"
      >
        <CalendarGridHead>
          <CalendarGridRow class="grid grid-cols-7">
            <CalendarHeadCell
              v-for="day in weekDays"
              :key="day"
              class="grid h-8 place-items-center text-xs font-medium text-muted-foreground capitalize"
            >
              {{ day.replace('.', '') }}
            </CalendarHeadCell>
          </CalendarGridRow>
        </CalendarGridHead>
        <CalendarGridBody>
          <CalendarGridRow
            v-for="(week, index) in month.rows"
            :key="index"
            class="grid grid-cols-7"
          >
            <CalendarCell
              v-for="date in week"
              :key="date.toString()"
              :date="date"
              class="grid place-items-center p-px"
            >
              <CalendarCellTrigger :day="date" :month="month.value" :class="dayClass" />
            </CalendarCell>
          </CalendarGridRow>
        </CalendarGridBody>
      </CalendarGrid>
    </template>
  </CalendarRoot>
</template>
