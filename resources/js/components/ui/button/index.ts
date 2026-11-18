import type { VariantProps } from "class-variance-authority"
import { cva } from "class-variance-authority"

export { default as Button } from "./Button.vue"

/**
 * Buttons of the platform. Flat fills, a hairline on the outline variant
 * and a neutral contact shadow; nothing glows. Every variant presses in a
 * touch (scale 0.97) and settles back on release, which is the only motion
 * a button has: icons stay still on hover.
 */
export const buttonVariants = cva(
  [
    "group/button relative inline-flex shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium outline-none select-none",
    // Colours ease; scale settles back after a press.
    "[transition:color_var(--duration-fast)_var(--ease-standard),background-color_var(--duration-fast)_var(--ease-standard),border-color_var(--duration-fast)_var(--ease-standard),box-shadow_var(--duration-base)_var(--ease-standard),opacity_var(--duration-fast)_var(--ease-standard),scale_200ms_var(--ease-spring)]",
    "active:not-disabled:scale-[0.97] active:not-disabled:[transition-duration:80ms] disabled:pointer-events-none disabled:opacity-50",
    "[&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4",
    "focus-visible:ring-[3px] focus-visible:ring-ring/45 focus-visible:ring-offset-1 focus-visible:ring-offset-background",
    "aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive",
    "data-[loading=true]:cursor-progress data-[loading=true]:[&>svg:not([data-slot=button-spinner])]:hidden",
  ],
  {
    variants: {
      variant: {
        default:
          "bg-primary text-primary-foreground shadow-(--shadow-control) hover:bg-[color-mix(in_oklch,var(--primary)_90%,black)] active:not-disabled:bg-[color-mix(in_oklch,var(--primary)_84%,black)]",
        destructive:
          "bg-destructive text-white shadow-(--shadow-control) hover:bg-[color-mix(in_oklch,var(--destructive)_90%,black)] active:not-disabled:bg-[color-mix(in_oklch,var(--destructive)_84%,black)] focus-visible:ring-destructive/35 dark:bg-destructive/70 dark:text-white",
        outline:
          "border border-input bg-card text-foreground shadow-(--shadow-control) hover:border-border-strong hover:bg-accent hover:text-accent-foreground dark:bg-input/25 dark:hover:bg-input/45",
        secondary:
          "bg-secondary text-secondary-foreground hover:bg-[color-mix(in_oklch,var(--secondary)_88%,var(--foreground))]",
        ghost:
          "text-foreground hover:bg-accent hover:text-accent-foreground dark:hover:bg-accent/60",
        link: "text-primary underline-offset-4 hover:underline active:not-disabled:scale-100",
      },
      size: {
        "default": "h-(--control-h) pointer-coarse:h-(--control-h-coarse) px-4 py-2 has-[>svg]:px-3",
        "sm": "h-8 gap-1.5 px-3 has-[>svg]:px-2.5",
        "lg": "h-10 px-6 text-base has-[>svg]:px-4",
        "icon": "size-9 pointer-coarse:size-10",
        "icon-sm": "size-8",
        "icon-lg": "size-10",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  },
)
export type ButtonVariants = VariantProps<typeof buttonVariants>
