export { default as Input } from "./Input.vue"

/**
 * Look shared by every text-like control (Input, Textarea and the triggers
 * of AppSelect, DatePicker and TimePicker): a sunken fill on a hairline, a
 * border that darkens on hover and turns to the palette on focus with a
 * soft halo. Heights and paddings are added by each control.
 */
export const fieldControl = [
  "w-full min-w-0 rounded-md border border-input bg-surface-sunken text-base text-foreground md:text-sm",
  "shadow-[inset_0_1px_1.5px_color-mix(in_oklch,var(--shadow-ink)_7%,transparent)]",
  "placeholder:text-muted-foreground/80 selection:bg-primary selection:text-primary-foreground",
  "transition-[color,background-color,border-color,box-shadow] duration-(--duration-fast) ease-(--ease-standard) outline-none",
  "hover:border-border-strong hover:bg-[color-mix(in_oklch,var(--surface-sunken)_55%,var(--card))]",
  "focus-visible:border-primary focus-visible:bg-card focus-visible:ring-[3px] focus-visible:ring-primary/18 focus-visible:shadow-none",
  "data-[state=open]:border-primary data-[state=open]:bg-card data-[state=open]:ring-[3px] data-[state=open]:ring-primary/18",
  "aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40",
  "disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 data-[disabled]:cursor-not-allowed data-[disabled]:opacity-50",
].join(" ")
