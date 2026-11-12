export { default as FileDropzone } from "./FileDropzone.vue"

export interface RejectedFile {
  file: File
  reason: "type" | "size" | "count"
  message: string
}
