# UI tokens: "Mostrador" layer (third pass)

Everything lives in `resources/css/app.css`. Every utility reads `--primary` on the element where you use it, so palettes, dark mode, the sidebar rail and nested `[data-palette]` previews work without anything extra. All motion turns off under `prefers-reduced-motion`.

"Mostrador" keeps every class name of the earlier "Pulso" layer but removes its decoration: no gradients, no coloured halos, no blur, no pointer-following light, no hover lifts. Colour is spent on actions and state only. The default palette (`emerald`) is a deep pine green.

## Tokens

| Kind              | Name                                                                                                  | Use                                                    |
| ----------------- | ----------------------------------------------------------------------------------------------------- | ------------------------------------------------------ |
| Easing            | `ease-spring` (0.3,1.25,0.5,1)                                                                        | a hint of rebound: press release, badges, thumbs       |
| Easing            | `ease-spring-soft` (0.22,1,0.36,1)                                                                    | no overshoot (kept as a name for larger surfaces)      |
| Easing            | `ease-out-quint`, `ease-standard`, `ease-exit`                                                        | entrances / colour / exits                             |
| Duration          | `--duration-fast` 140, `--duration-base` 180, `--duration-panel` 240, `--duration-slow` 320 ms        | `--stagger` 22 ms                                      |
| Shadow            | `shadow-float`                                                                                        | resting panel: paper edge (light top lip in dark mode) |
| Shadow            | `shadow-lift`                                                                                         | hovered card: short contact shadow                     |
| Shadow            | `shadow-glow`                                                                                         | neutral control shadow (no halo, name kept)            |
| Shadow            | `shadow-glow-ring`                                                                                    | 3 px focus ring in the palette colour                  |
| Shadow (existing) | `shadow-card`, `shadow-popover`, `shadow-elevated`, `shadow-hover`, `shadow-control`                  |                                                        |
| Radius            | `--radius` is 8 px: `rounded-sm` 4, `rounded-md` 6, `rounded-lg` 8, `rounded-xl` 10, `rounded-2xl` 14 | chat bubbles keep `rounded-bubble`                     |

## Utilities

| Class                  | What it does                                                                         |
| ---------------------- | ------------------------------------------------------------------------------------ |
| `canvas-ambient`       | plain page canvas (the glow is gone)                                                 |
| `surface-float`        | panel: card bg, hairline, `rounded-xl`, `shadow-float`                               |
| `glass`                | solid popover bg (menus, popovers, floating toolbars)                                |
| `glass-bar`            | sticky header/toolbar: solid canvas + bottom hairline                                |
| `glass-card`           | solid card bg                                                                        |
| `accent-gradient`      | flat primary fill with `primary-foreground` text (logo mark, avatars, active marker) |
| `accent-gradient-soft` | flat primary tint over the card (selected cards, highlighted notices)                |
| `text-gradient`        | plain foreground text                                                                |
| `glow`                 | applies `shadow-glow` (neutral)                                                      |
| `focus-glow`           | on focus-visible / focus-within: primary border + `shadow-glow-ring`                 |
| `hover-lift`           | `shadow-lift` on hover, no movement                                                  |
| `press-spring`         | scale 0.97 while pressed, settles back on release                                    |
| `shimmer`              | neutral skeleton sweep for any element (`<Skeleton>` already has it)                 |
| `enter-spring`         | one-shot 6 px rise on mount; stagger with `style="--i: n"`                           |
| `enter-fade`           | one-shot fade on mount; stagger with `--i`                                           |
| `enter-slide-right`    | one-shot slide in from the left; stagger with `--i`                                  |
| `bump`                 | quick scale bump; re-trigger by changing the element `:key` when a value changes     |
| `indicator-motion`     | transition for transform/left/top/width/height (sliding underlines, pills)           |
| `live-dot`             | breathing ring around a status dot (colour from `currentColor`)                      |
| `scroll-fade-y`        | fades the top and bottom 12 px of a scroll area                                      |
| `border-gradient`      | border tinted with the primary (flat)                                                |
| `spotlight`            | hairline darkens on hover (indicator cards)                                          |

Existing ones you can still use: `surface-panel`, `surface-raised`, `interactive-surface`, `row-accent`, `reveal`, `reveal-stagger`, `pop`, `dialog-motion`, `press`, `section-title`, `font-display`, `chat-canvas`, `media-scrim`, `message-flash`, `drop-veil`, `pane-progress`.

## Vue transitions

| Name / component                             | Use                                                                          |
| -------------------------------------------- | ---------------------------------------------------------------------------- |
| `<ListTransition>` (`components/ui`)         | `<TransitionGroup name="list">` in a `relative` container; items need a key  |
| `<Transition name="fade">` / `"fade-up"`     | swapping one block for another                                               |
| `vAutoAnimate` (`@formkit/auto-animate/vue`) | grids or lists that reflow when filtered; import it in the page that uses it |

## Notes

- The ui kit (`components/ui/*`) already follows this: buttons only press in, popovers and menus grow 3 % out of their trigger in 160 ms, dialogs rise 8 px in 220 ms, overlays are a plain scrim.
- Page changes fade the content and raise it 6 px in 220 ms (view transitions); the shell stays still.
