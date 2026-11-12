<script setup lang="ts">
import type { TimeValue } from "reka-ui"
import type { HTMLAttributes } from "vue"
import { Time } from "@internationalized/date"
import { Clock } from "@lucide/vue"
import { TimeFieldInput, TimeFieldRoot } from "reka-ui"
import { computed, nextTick, ref, watch } from "vue"
import { fieldControl } from "@/components/ui/input"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
import { cn } from "@/lib/utils"

/**
 * Time field of the platform (24 h); replaces <input type="time">.
 *
 *   <TimePicker v-model="form.opens_at" />
 *
 * The model is the same "HH:mm" string the native input produced
 * (`emptyValue`, '' by default, when empty). The hour and the minutes can
 * be typed or stepped with the arrow keys; the clock button opens two
 * columns to choose them with the pointer (`minuteStep`, 5 by default).
 */
const props = withDefaults(
  defineProps<{
    minuteStep?: number
    emptyValue?: string | null
    required?: boolean
    disabled?: boolean
    invalid?: boolean
    id?: string
    name?: string
    ariaLabel?: string
    size?: "sm" | "default"
    class?: HTMLAttributes["class"]
  }>(),
  {
    minuteStep: 5,
    emptyValue: "",
    required: false,
    disabled: false,
    invalid: false,
    id: undefined,
    name: undefined,
    ariaLabel: "Hora",
    size: "default",
    class: undefined,
  },
)

const model = defineModel<string | null | undefined>()

const emit = defineEmits<{ change: [value: string | null] }>()

const pad = (value: number): string => String(value).padStart(2, "0")

function parse(value: string | null | undefined): Time | undefined {
  const match = /^(\d{1,2}):(\d{2})/.exec(value ?? "")

  if (!match) {
    return undefined
  }

  const hour = Number(match[1])
  const minute = Number(match[2])

  return hour < 24 && minute < 60 ? new Time(hour, minute) : undefined
}

const time = computed<TimeValue | undefined>({
  get: () => parse(model.value),
  set: (value) => commit(value ? `${pad(value.hour)}:${pad(value.minute)}` : props.emptyValue),
})

function commit(value: string | null): void {
  if (value === model.value) {
    return
  }

  model.value = value
  emit("change", value)
}

const open = ref(false)
const hours = Array.from({ length: 24 }, (_, hour) => hour)
const minutes = computed(() => {
  const step = Math.min(30, Math.max(1, Math.round(props.minuteStep)))

  return Array.from({ length: Math.ceil(60 / step) }, (_, index) => index * step)
})

const current = computed(() => parse(model.value))

function pickHour(hour: number): void {
  commit(`${pad(hour)}:${pad(current.value?.minute ?? 0)}`)
}

function pickMinute(minute: number): void {
  commit(`${pad(current.value?.hour ?? 0)}:${pad(minute)}`)
}

const columns = ref<HTMLElement | null>(null)

watch(open, async (isOpen) => {
  if (!isOpen) {
    return
  }

  await nextTick()
  columns.value
    ?.querySelectorAll<HTMLElement>("[aria-selected=true]")
    .forEach((element) => element.scrollIntoView({ block: "center" }))
})

const optionClass =
  "grid h-8 w-full place-items-center rounded-md text-sm tabular-nums outline-none transition-colors duration-100 hover:bg-primary-soft hover:text-primary-soft-foreground focus-visible:ring-2 focus-visible:ring-ring aria-selected:bg-primary aria-selected:font-semibold aria-selected:text-primary-foreground"
</script>

<template>
  <div
    data-slot="time-picker"
    :data-disabled="disabled ? '' : undefined"
    :aria-invalid="invalid || undefined"
    :class="cn(
      fieldControl,
      'flex items-center gap-2 pr-1 pl-3 text-sm',
      'focus-within:border-primary focus-within:bg-card focus-within:shadow-none focus-within:ring-[3px] focus-within:ring-primary/18',
      size === 'sm' ? 'h-8' : 'h-(--control-h)',
      props.class,
    )"
  >
    <input v-if="name" type="hidden" :name="name" :value="model ?? ''">
    <TimeFieldRoot
      :id="id"
      v-slot="{ segments }"
      v-model="time"
      locale="es-MX"
      :hour-cycle="24"
      granularity="minute"
      :disabled="disabled"
      :required="required"
      :aria-label="ariaLabel"
      class="flex min-w-0 flex-1 items-center tabular-nums"
    >
      <template v-for="segment in segments" :key="segment.part">
        <TimeFieldInput
          v-if="segment.part === 'literal'"
          :part="segment.part"
          class="px-px text-muted-foreground"
        >
          {{ segment.value }}
        </TimeFieldInput>
        <TimeFieldInput
          v-else
          :part="segment.part"
          class="rounded-sm px-0.5 outline-none focus:bg-primary focus:text-primary-foreground data-[placeholder]:text-muted-foreground data-[placeholder]:focus:text-primary-foreground"
        >
          {{ segment.value }}
        </TimeFieldInput>
      </template>
    </TimeFieldRoot>

    <Popover v-model:open="open">
      <PopoverTrigger as-child>
        <button
          type="button"
          :disabled="disabled"
          class="grid size-7 shrink-0 place-items-center rounded-sm text-muted-foreground outline-none transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring data-[state=open]:bg-primary-soft data-[state=open]:text-primary-soft-foreground"
          aria-label="Elegir la hora de una lista"
        >
          <Clock class="size-4" aria-hidden="true" />
        </button>
      </PopoverTrigger>
      <PopoverContent align="end" class="w-auto p-2">
        <div ref="columns" class="flex gap-1">
          <div class="w-14">
            <p class="pb-1 text-center text-xs font-medium text-muted-foreground">Hora</p>
            <ul role="listbox" aria-label="Hora" class="h-56 space-y-px overflow-y-auto pr-1">
              <li v-for="hour in hours" :key="hour" role="presentation">
                <button
                  type="button"
                  role="option"
                  :aria-selected="current?.hour === hour"
                  :class="optionClass"
                  @click="pickHour(hour)"
                >
                  {{ pad(hour) }}
                </button>
              </li>
            </ul>
          </div>
          <div class="w-14 border-l pl-1">
            <p class="pb-1 text-center text-xs font-medium text-muted-foreground">Min</p>
            <ul role="listbox" aria-label="Minutos" class="h-56 space-y-px overflow-y-auto pr-1">
              <li v-for="minute in minutes" :key="minute" role="presentation">
                <button
                  type="button"
                  role="option"
                  :aria-selected="current?.minute === minute"
                  :class="optionClass"
                  @click="pickMinute(minute)"
                >
                  {{ pad(minute) }}
                </button>
              </li>
            </ul>
          </div>
        </div>
        <button
          v-if="!required && current"
          type="button"
          class="mt-2 h-8 w-full rounded-md border-t text-sm text-muted-foreground hover:bg-accent hover:text-foreground"
          @click="commit(emptyValue); open = false"
        >
          Borrar
        </button>
      </PopoverContent>
    </Popover>
  </div>
</template>
