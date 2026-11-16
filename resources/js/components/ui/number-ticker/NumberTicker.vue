<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from "vue"

/**
 * A figure that counts to its value: from zero on first render and from the
 * previous value when it changes. With reduced motion it simply shows the
 * value. The text is formatted for es-MX (`decimals`, `prefix`, `suffix`).
 *
 * The DOM holds the figure once: the rolling layer is aria-hidden and cannot
 * be selected, and the final value is the only real text (sr-only), so
 * screen readers and copy-paste both get «2», never «22».
 *
 *   <NumberTicker :value="128" />
 *   <NumberTicker :value="87.5" :decimals="1" suffix=" %" />
 */
const props = withDefaults(
  defineProps<{
    value: number
    decimals?: number
    prefix?: string
    suffix?: string
    /** Milliseconds of the count. */
    duration?: number
  }>(),
  {
    decimals: 0,
    prefix: "",
    suffix: "",
    duration: 700,
  },
)

const formatter = () =>
  new Intl.NumberFormat("es-MX", {
    minimumFractionDigits: props.decimals,
    maximumFractionDigits: props.decimals,
  })

const format = (value: number): string =>
  `${props.prefix}${formatter().format(value)}${props.suffix}`

const shown = ref(format(0))
let frame = 0
let displayed = 0

function reducedMotion(): boolean {
  return (
    typeof window === "undefined" ||
    window.matchMedia("(prefers-reduced-motion: reduce)").matches
  )
}

function animate(to: number): void {
  cancelAnimationFrame(frame)

  if (reducedMotion() || !Number.isFinite(to)) {
    displayed = to
    shown.value = format(to)

    return
  }

  const from = displayed
  const start = performance.now()

  const tick = (now: number): void => {
    const progress = Math.min(1, (now - start) / props.duration)
    // Ease out: fast at first, settling on the final figure.
    const eased = 1 - (1 - progress) ** 4

    displayed = from + (to - from) * eased
    shown.value = format(displayed)

    if (progress < 1) {
      frame = requestAnimationFrame(tick)
    }
    else {
      displayed = to
      shown.value = format(to)
    }
  }

  frame = requestAnimationFrame(tick)
}

onMounted(() => animate(props.value))
watch(() => props.value, animate)
onBeforeUnmount(() => cancelAnimationFrame(frame))
</script>

<template>
  <span data-slot="number-ticker" class="tabular-nums">
    <span aria-hidden="true" class="select-none">{{ shown }}</span>
    <span class="sr-only">{{ format(value) }}</span>
  </span>
</template>
