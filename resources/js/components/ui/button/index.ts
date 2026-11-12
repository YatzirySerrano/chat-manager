import type { VariantProps } from "class-variance-authority"
import { cva } from "class-variance-authority"

export { default as Button } from "./Button.vue"

/**
 * Buttons of the platform. Solid variants carry a faint inner highlight and
 * a shadow tinted by their own color on hover; every variant presses in.
 * A trailing icon (last child) nudges forward on hover; the icon of an
 * icon-only button grows slightly.
 */
export const buttonVariants = cva(
  [
    "group/button relative inline-flex shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium outline-none select-none",
    "transition-[color,background-color,border-color,box-shadow,scale,opacity] duration-(--duration-fast) ease-(--ease-standard)",
    "active:not-disabled:scale-[0.97] disabled:pointer-events-none disabled:opacity-50",
    "[&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4",
    "[&_svg]:transition-transform [&_svg]:duration-(--duration-base) [&_svg]:ease-(--ease-out-quint)",
    "hover:[&>svg:last-child:not(:first-child)]:translate-x-0.5 hover:[&>svg:only-child]:scale-110",
    "focus-visible:ring-[3px] focus-visible:ring-ring/45 focus-visible:ring-offset-1 focus-visible:ring-offset-background",
    "aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 aria-invalid:border-destructive",
    "data-[loading=true]:cursor-progress data-[loading=true]:[&>svg:not([data-slot=button-spinner])]:hidden",
  ],
  {
    variants: {
      variant: {
        default:
          "bg-primary text-primary-foreground shadow-[inset_0_1px_0_rgb(255_255_255/0.2),inset_0_-1px_0_rgb(0_0_0/0.12),var(--shadow-control)] hover:bg-[color-mix(in_oklch,var(--primary)_90%,black)] hover:shadow-[inset_0_1px_0_rgb(255_255_255/0.24),inset_0_-1px_0_rgb(0_0_0/0.12),0_6px_16px_-6px_color-mix(in_oklch,var(--primary)_70%,transparent)] dark:hover:bg-[color-mix(in_oklch,var(--primary)_88%,white)]",
        destructive:
          "bg-destructive text-white shadow-[inset_0_1px_0_rgb(255_255_255/0.18),inset_0_-1px_0_rgb(0_0_0/0.12),var(--shadow-control)] hover:bg-[color-mix(in_oklch,var(--destructive)_90%,black)] hover:shadow-[inset_0_1px_0_rgb(255_255_255/0.2),0_6px_16px_-6px_color-mix(in_oklch,var(--destructive)_70%,transparent)] focus-visible:ring-destructive/35 dark:bg-destructive/70 dark:text-white",
        outline:
          "border border-input bg-card text-foreground shadow-(--shadow-control) hover:border-[color-mix(in_oklch,var(--primary)_40%,var(--border-strong))] hover:bg-accent hover:text-accent-foreground dark:bg-input/25 dark:hover:bg-input/45",
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
        "icon": "size-9",
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
