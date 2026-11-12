<script setup lang="ts">
import type { DateValue } from "@internationalized/date"
import { ChevronLeft, ChevronRight } from "@lucide/vue"
import { computed, ref } from "vue"
import { cn } from "@/lib/utils"
import { monthName } from "./dates"

/**
 * Quick jump of a calendar: twelve months of a year, and a grid of twelve
 * years to change the year. Choosing a month returns to the days.
 */
const props = defineProps<{
  /** Month currently shown by the calendar. */
  current: DateValue
  minValue?: DateValue
  maxValue?: DateValue
}>()

const emit = defineEmits<{ pick: [date: DateValue] }>()

const year = ref(props.current.year)
const mode = ref<"months" | "years">("months")
const firstYear = computed(() => year.value - (year.value % 12))

function monthDisabled(month: number): boolean {
  const { minValue, maxValue } = props

  return (
    (minValue !== undefined && (year.value < minValue.year || (year.value === minValue.year && month < minValue.month))) ||
    (maxValue !== undefined && (year.value > maxValue.year || (year.value === maxValue.year && month > maxValue.month)))
  )
}

function yearDisabled(candidate: number): boolean {
  return (
    (props.minValue !== undefined && candidate < props.minValue.year) ||
    (props.maxValue !== undefined && candidate > props.maxValue.year)
  )
}

function step(direction: -1 | 1): void {
  year.value += mode.value === "months" ? direction : direction * 12
}

function pickMonth(month: number): void {
  emit("pick", props.current.set({ year: year.value, month, day: 1 }))
}

function pickYear(candidate: number): void {
  year.value = candidate
  mode.value = "months"
}

const cellClass =
  "h-10 rounded-md text-sm capitalize outline-none transition-[background-color,color,scale] duration-(--duration-fast) hover:bg-primary-soft hover:text-primary-soft-foreground focus-visible:ring-2 focus-visible:ring-ring active:scale-95 disabled:pointer-events-none disabled:opacity-35"
</script>

<template>
  <div class="w-full" data-slot="calendar-jump">
    <div class="mb-2 flex items-center justify-between gap-1">
      <button
        type="button"
        class="grid size-8 place-items-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
        :aria-label="mode === 'months' ? 'Año anterior' : 'Años anteriores'"
        @click="step(-1)"
      >
        <ChevronLeft class="size-4" aria-hidden="true" />
      </button>
      <button
        type="button"
        class="h-8 rounded-md px-2.5 font-display text-sm font-semibold tabular-nums hover:bg-accent"
        :aria-label="mode === 'months' ? `Año ${year}: elegir otro año` : 'Volver a los meses'"
        @click="mode = mode === 'months' ? 'years' : 'months'"
      >
        {{ mode === "months" ? year : `${firstYear} – ${firstYear + 11}` }}
      </button>
      <button
        type="button"
        class="grid size-8 place-items-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
        :aria-label="mode === 'months' ? 'Año siguiente' : 'Años siguientes'"
        @click="step(1)"
      >
        <ChevronRight class="size-4" aria-hidden="true" />
      </button>
    </div>

    <div v-if="mode === 'months'" class="grid grid-cols-3 gap-1">
      <button
        v-for="month in 12"
        :key="month"
        type="button"
        :disabled="monthDisabled(month)"
        :aria-pressed="month === current.month && year === current.year"
        :class="cn(cellClass, month === current.month && year === current.year && 'bg-primary text-primary-foreground hover:bg-primary hover:text-primary-foreground')"
        @click="pickMonth(month)"
      >
        {{ monthName(month, "short") }}
      </button>
    </div>
    <div v-else class="grid grid-cols-3 gap-1">
      <button
        v-for="offset in 12"
        :key="offset"
        type="button"
        :disabled="yearDisabled(firstYear + offset - 1)"
        :aria-pressed="firstYear + offset - 1 === year"
        :class="cn(cellClass, 'tabular-nums', firstYear + offset - 1 === year && 'bg-primary text-primary-foreground hover:bg-primary hover:text-primary-foreground')"
        @click="pickYear(firstYear + offset - 1)"
      >
        {{ firstYear + offset - 1 }}
      </button>
    </div>
  </div>
</template>
