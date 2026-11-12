<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import type { RejectedFile } from "."
import { FileText, UploadCloud, X } from "@lucide/vue"
import { computed, ref } from "vue"
import { formatFileSize } from "@/lib/format"
import { cn } from "@/lib/utils"

/**
 * File field of the platform; replaces <input type="file">. Files can be
 * dropped on it or chosen with the system dialog (click, Enter or Space).
 *
 *   <FileDropzone v-model="form.file" accept=".csv,.xlsx" :max-size-mb="5" />
 *   <FileDropzone v-model="form.files" multiple :max-files="4" />
 *
 * The model is a `File | null` (or `File[]` with `multiple`). Files of
 * another type or above `maxSizeMb` are refused with a message under the
 * zone and reported through `reject`; the server keeps validating as usual.
 */
const props = withDefaults(
  defineProps<{
    multiple?: boolean
    /** Same syntax as the native attribute: ".csv,.xlsx,image/*". */
    accept?: string
    maxSizeMb?: number
    maxFiles?: number
    title?: string
    /** Line under the title; defaults to the accepted types and size. */
    hint?: string
    disabled?: boolean
    invalid?: boolean
    id?: string
    name?: string
    /** Smaller zone for dialogs and narrow panels. */
    compact?: boolean
    class?: HTMLAttributes["class"]
  }>(),
  {
    multiple: false,
    accept: undefined,
    maxSizeMb: undefined,
    maxFiles: undefined,
    title: undefined,
    hint: undefined,
    disabled: false,
    invalid: false,
    id: undefined,
    name: undefined,
    compact: false,
    class: undefined,
  },
)

const model = defineModel<File | File[] | null | undefined>()

const emit = defineEmits<{
  reject: [files: RejectedFile[]]
  change: [files: File[]]
}>()

const input = ref<HTMLInputElement | null>(null)
const dragging = ref(false)
const problem = ref<string | null>(null)
let dragDepth = 0

const files = computed<File[]>(() =>
  Array.isArray(model.value) ? model.value : model.value ? [model.value] : [],
)

const rules = computed(() =>
  (props.accept ?? "")
    .split(",")
    .map((rule) => rule.trim().toLowerCase())
    .filter(Boolean),
)

function accepts(file: File): boolean {
  if (rules.value.length === 0) {
    return true
  }

  const name = file.name.toLowerCase()
  const type = file.type.toLowerCase()

  return rules.value.some((rule) =>
    rule.startsWith(".")
      ? name.endsWith(rule)
      : rule.endsWith("/*")
        ? type.startsWith(rule.slice(0, -1))
        : type === rule,
  )
}

const defaultHint = computed(() => {
  const parts: string[] = []

  if (rules.value.length > 0) {
    parts.push(
      rules.value
        .map((rule) => (rule.startsWith(".") ? rule.slice(1).toUpperCase() : rule))
        .join(", "),
    )
  }

  if (props.maxSizeMb) {
    parts.push(`hasta ${props.maxSizeMb} MB`)
  }

  return parts.join(", ")
})

function take(list: FileList | File[] | null | undefined): void {
  const incoming = Array.from(list ?? [])

  if (incoming.length === 0 || props.disabled) {
    return
  }

  const rejected: RejectedFile[] = []
  const accepted: File[] = []
  const limit = props.multiple ? (props.maxFiles ?? Infinity) : 1
  const room = props.multiple ? limit - files.value.length : 1

  for (const file of incoming) {
    if (!accepts(file)) {
      rejected.push({ file, reason: "type", message: `«${file.name}» no es un tipo de archivo permitido.` })
    }
    else if (props.maxSizeMb && file.size > props.maxSizeMb * 1024 * 1024) {
      rejected.push({ file, reason: "size", message: `«${file.name}» pesa ${formatFileSize(file.size)}; el máximo es ${props.maxSizeMb} MB.` })
    }
    else if (accepted.length >= room) {
      rejected.push({
        file,
        reason: "count",
        message: props.multiple
          ? `Solo se pueden agregar ${limit} archivos.`
          : "Solo se puede elegir un archivo.",
      })
    }
    else {
      accepted.push(file)
    }
  }

  problem.value = rejected[0]?.message ?? null

  if (rejected.length > 0) {
    emit("reject", rejected)
  }

  if (accepted.length > 0) {
    const next = props.multiple ? [...files.value, ...accepted] : accepted

    model.value = props.multiple ? next : (next[0] ?? null)
    emit("change", next)
  }
}

function remove(index: number): void {
  const next = files.value.filter((_, position) => position !== index)

  model.value = props.multiple ? next : null
  problem.value = null
  emit("change", next)
}

function onInput(event: Event): void {
  const element = event.target as HTMLInputElement

  take(element.files)
  // Let the same file be chosen again after removing it.
  element.value = ""
}

function onDrop(event: DragEvent): void {
  dragDepth = 0
  dragging.value = false
  take(event.dataTransfer?.files)
}

function onDragEnter(): void {
  if (!props.disabled) {
    dragDepth += 1
    dragging.value = true
  }
}

function onDragLeave(): void {
  dragDepth = Math.max(0, dragDepth - 1)
  dragging.value = dragDepth > 0
}

function open(): void {
  if (!props.disabled) {
    input.value?.click()
  }
}

function clear(): void {
  model.value = props.multiple ? [] : null
  problem.value = null
}

defineExpose({ open, clear })
</script>

<template>
  <div data-slot="file-dropzone" :class="cn('grid min-w-0 gap-2', props.class)">
    <div
      role="button"
      :tabindex="disabled ? -1 : 0"
      :aria-disabled="disabled || undefined"
      :aria-invalid="invalid || problem ? true : undefined"
      :aria-describedby="id ? `${id}-hint` : undefined"
      :data-dragging="dragging ? '' : undefined"
      :class="cn(
        'group/dropzone relative flex cursor-pointer items-center gap-4 rounded-xl border border-dashed border-border-strong bg-surface-sunken/70 text-left outline-none',
        'transition-[background-color,border-color,box-shadow,scale] duration-(--duration-base) ease-(--ease-out-quint)',
        'hover:border-primary/60 hover:bg-primary-soft/40',
        'focus-visible:border-primary focus-visible:ring-[3px] focus-visible:ring-primary/25',
        'data-[dragging]:scale-[1.01] data-[dragging]:border-solid data-[dragging]:border-primary data-[dragging]:bg-primary-soft data-[dragging]:shadow-hover',
        'aria-invalid:border-destructive aria-disabled:pointer-events-none aria-disabled:opacity-50',
        compact ? 'px-4 py-3.5' : 'px-5 py-6',
      )"
      @click="open"
      @keydown.enter.prevent="open"
      @keydown.space.prevent="open"
      @dragenter.prevent="onDragEnter"
      @dragover.prevent
      @dragleave.prevent="onDragLeave"
      @drop.prevent="onDrop"
    >
      <span
        class="grid shrink-0 place-items-center rounded-lg bg-card text-muted-foreground shadow-(--shadow-control) transition-[color,translate] duration-(--duration-base) ease-(--ease-out-quint) group-hover/dropzone:-translate-y-0.5 group-hover/dropzone:text-primary group-data-[dragging]/dropzone:-translate-y-1 group-data-[dragging]/dropzone:text-primary"
        :class="compact ? 'size-9' : 'size-11'"
      >
        <UploadCloud :class="compact ? 'size-4.5' : 'size-5'" aria-hidden="true" />
      </span>
      <span class="min-w-0 space-y-0.5">
        <span class="block text-sm font-semibold text-foreground">
          {{ dragging ? 'Suelta para agregar' : (title ?? (multiple ? 'Arrastra los archivos aquí o elígelos' : 'Arrastra el archivo aquí o elígelo')) }}
        </span>
        <span
          v-if="hint ?? defaultHint"
          :id="id ? `${id}-hint` : undefined"
          class="block text-xs text-pretty text-muted-foreground"
        >
          {{ hint ?? defaultHint }}
        </span>
      </span>
      <input
        :id="id"
        ref="input"
        type="file"
        class="sr-only"
        tabindex="-1"
        :name="name"
        :accept="accept"
        :multiple="multiple"
        :disabled="disabled"
        @click.stop
        @change="onInput"
      >
    </div>

    <p v-if="problem" role="alert" class="text-sm text-destructive">
      {{ problem }}
    </p>

    <ul v-if="files.length > 0" role="list" class="grid gap-1.5">
      <li
        v-for="(file, index) in files"
        :key="`${file.name}-${file.size}-${index}`"
        class="reveal flex min-w-0 items-center gap-3 rounded-lg border bg-card px-3 py-2"
      >
        <FileText class="size-4 shrink-0 text-primary" aria-hidden="true" />
        <span class="min-w-0 flex-1">
          <span class="block truncate text-sm font-medium">{{ file.name }}</span>
          <span class="block text-xs text-muted-foreground tabular-nums">{{ formatFileSize(file.size) }}</span>
        </span>
        <button
          type="button"
          class="grid size-7 shrink-0 place-items-center rounded-md text-muted-foreground outline-none hover:bg-destructive-soft hover:text-destructive-soft-foreground focus-visible:ring-2 focus-visible:ring-ring"
          :aria-label="`Quitar ${file.name}`"
          :disabled="disabled"
          @click="remove(index)"
        >
          <X class="size-4" aria-hidden="true" />
        </button>
      </li>
    </ul>
  </div>
</template>
