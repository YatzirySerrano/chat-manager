# UI tokens: "Pulso" layer (second redesign)

Everything lives in `resources/css/app.css`. Every utility reads `--primary` on the element where you use it, so palettes, dark mode, the sidebar rail and nested `[data-palette]` previews work without anything extra. All motion turns off under `prefers-reduced-motion`.

## Tokens

| Kind              | Name                                                                                           | Use                                                       |
| ----------------- | ---------------------------------------------------------------------------------------------- | --------------------------------------------------------- |
| Easing            | `ease-spring` (0.34,1.56,0.64,1)                                                               | small things: press release, badges, dots                 |
| Easing            | `ease-spring-soft` (0.22,1.25,0.36,1)                                                          | larger things: popovers, dialogs, indicators              |
| Easing            | `ease-out-quint`, `ease-standard`, `ease-exit`                                                 | entrances / colour / exits                                |
| Duration          | `--duration-fast` 150, `--duration-base` 200, `--duration-panel` 280, `--duration-slow` 420 ms |                                                           |
| Shadow            | `shadow-float`                                                                                 | resting floating panel (has a light top lip in dark mode) |
| Shadow            | `shadow-lift`                                                                                  | hovered card / raised state                               |
| Shadow            | `shadow-glow`                                                                                  | halo in the palette colour (primary button, active pill)  |
| Shadow            | `shadow-glow-ring`                                                                             | soft 4 px focus ring in the palette colour                |
| Shadow (existing) | `shadow-card`, `shadow-popover`, `shadow-elevated`, `shadow-hover`, `shadow-control`           |                                                           |
| Radius            | `--radius` is now 10 px: `rounded-md` 8, `rounded-lg` 10, `rounded-xl` 14, `rounded-2xl` 18    |                                                           |

## Utilities

| Class                  | What it does                                                                                                 |
| ---------------------- | ------------------------------------------------------------------------------------------------------------ |
| `canvas-ambient`       | page canvas with a faint palette glow at the top (use on large backgrounds)                                  |
| `surface-float`        | floating panel: card bg, hairline, `rounded-xl`, `shadow-float`                                              |
| `glass`                | translucent popover bg + backdrop blur (menus, popovers, floating toolbars)                                  |
| `glass-bar`            | sticky header/toolbar: translucent canvas + blur + bottom hairline                                           |
| `glass-card`           | translucent card bg + blur                                                                                   |
| `accent-gradient`      | gradient fill from the primary, with `primary-foreground` text (avatars, primary buttons, active indicators) |
| `accent-gradient-soft` | faint primary wash over the card (hero areas, selected cards)                                                |
| `text-gradient`        | foreground-to-primary gradient text (use only for big titles and figures)                                    |
| `glow`                 | applies `shadow-glow`                                                                                        |
| `focus-glow`           | on focus-visible / focus-within: primary border + soft ring (composers, search boxes)                        |
| `hover-lift`           | 1 px lift + `shadow-lift` on hover, spring easing                                                            |
| `press-spring`         | scale 0.96 on press, springs back on release                                                                 |
| `shimmer`              | skeleton shimmer for any element (`<Skeleton>` already has it)                                               |
| `enter-spring`         | one-shot springy rise on mount; stagger with `style="--i: n"`                                                |
| `enter-fade`           | one-shot fade on mount; stagger with `--i`                                                                   |
| `enter-slide-right`    | one-shot slide in from the left; stagger with `--i`                                                          |
| `bump`                 | quick scale bump; re-trigger by changing the element `:key` when a value changes                             |
| `indicator-motion`     | spring transition for transform/left/top/width/height (sliding tab underlines, selection pills)              |
| `live-dot`             | breathing ring around a status dot (colour comes from `currentColor`, e.g. `text-success bg-current`)        |
| `scroll-fade-y`        | fades the top and bottom 12 px of a scroll area                                                              |
| `border-gradient`      | gradient hairline border via `::before` (element needs a radius; do not combine with `spotlight`)            |

Existing ones you can still use: `surface-panel`, `surface-raised`, `interactive-surface`, `spotlight`, `row-accent`, `reveal`, `reveal-stagger`, `pop`, `dialog-motion`, `press`, `section-title`, `font-display`, `chat-canvas`, `media-scrim`, `message-flash`, `drop-veil`, `pane-progress`.

## Notes

- Use `accent-gradient` and `glow` only on one or two hero elements per view.
- The ui kit (`components/ui/*`) already uses these: buttons have `press-spring`, inputs have the focus glow, popovers, menus and dialogs open with a spring.
