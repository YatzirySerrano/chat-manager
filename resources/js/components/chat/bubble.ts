import './chat.css';

/**
 * The look of a message bubble, shared by the customer conversations and
 * the internal chat so both read as the same product. Only classes: each
 * module keeps its own content renderer.
 *
 * Shape: consecutive messages of one author form a stack. Inside a stack
 * the corners that face the author's side tighten, and only the last bubble
 * keeps the tail (the sharpest corner, at the bottom).
 */
export type BubbleSide = 'in' | 'out';

export type BubbleShape = {
    side: BubbleSide;
    /** The message before / after belongs to the same stack. */
    joinedAbove: boolean;
    joinedBelow: boolean;
};

export function bubbleShape({
    side,
    joinedAbove,
    joinedBelow,
}: BubbleShape): string[] {
    if (side === 'out') {
        return [
            'rounded-bubble',
            joinedAbove ? 'rounded-tr-[0.4rem]' : '',
            joinedBelow ? 'rounded-br-[0.4rem]' : 'rounded-br-[0.2rem]',
        ];
    }

    return [
        'rounded-bubble',
        joinedAbove ? 'rounded-tl-[0.4rem]' : '',
        joinedBelow ? 'rounded-bl-[0.4rem]' : 'rounded-bl-[0.2rem]',
    ];
}

/**
 * Theme colors of a bubble (not used with the viewer's personal colors,
 * which bring their own classes). Received bubbles are paper resting on the
 * canvas: a hairline and a contact shadow. Own bubbles take the palette's
 * soft tone, quiet enough to read all day.
 */
export function bubbleTone(side: BubbleSide): string {
    return side === 'out'
        ? 'bg-bubble-out text-bubble-out-foreground shadow-[0_1px_1px_color-mix(in_oklch,var(--shadow-ink)_7%,transparent)]'
        : 'bg-bubble-in text-bubble-in-foreground ring-1 ring-border-strong/55 ring-inset shadow-[0_1px_1px_color-mix(in_oklch,var(--shadow-ink)_6%,transparent)]';
}

/** Entrance of a message that arrived while the chat was open. */
export function arriveClass(side: BubbleSide): string {
    return side === 'out' ? 'chat-arrive-out' : 'chat-arrive-in';
}

/** Hover lift of a bubble (only where a pointer can hover). */
export const BUBBLE_HOVER =
    'transition-shadow duration-(--duration-base) ease-(--ease-out-quint) pointer-fine:group-hover:shadow-hover';

/**
 * A quoted message inside a bubble: a strip with the author on top. With
 * personal colors it only uses the bubble's own text color.
 */
export function quoteClasses(custom: boolean): string {
    return custom
        ? 'border border-current/30 border-l-[3px] border-l-current'
        : 'border-l-[3px] border-primary/70 bg-background/60 text-muted-foreground hover:bg-background/85';
}

export const QUOTE_BASE =
    'mb-1 block w-full min-w-40 rounded-[0.7rem] px-2.5 py-1.5 text-left text-sm transition-colors duration-(--duration-fast) outline-none focus-visible:ring-2 focus-visible:ring-ring md:text-xs';

/** The time and delivery line in the bottom corner of a bubble. */
export const BUBBLE_META =
    'flex items-center justify-end gap-1 text-[0.6875rem] leading-4 tabular-nums';

/** One reaction under a bubble. */
export function reactionChip(mine: boolean): string {
    return [
        'inline-flex h-6 items-center gap-1 rounded-full border px-1.5 text-xs leading-none text-foreground shadow-(--shadow-control) transition-[background-color,border-color,scale] duration-(--duration-fast) outline-none focus-visible:ring-2 focus-visible:ring-ring',
        mine
            ? 'border-primary/50 bg-primary-soft text-primary-soft-foreground'
            : 'border-border bg-background',
    ].join(' ');
}

/** The reactions row overlaps the bottom edge of its bubble. */
export const REACTIONS_ROW =
    'relative z-[1] -mt-1.5 flex flex-wrap gap-1 px-2 motion-safe:animate-in motion-safe:fade-in motion-safe:zoom-in-90';

/**
 * Quick actions beside a bubble: one floating pill of icon buttons. Hidden
 * until hover or focus where a fine pointer exists (chat.css), always
 * visible on touch screens.
 */
export const ROW_ACTIONS =
    'chat-row-actions flex shrink-0 items-center gap-0.5 rounded-full border bg-popover/95 p-0.5 text-muted-foreground shadow-popover backdrop-blur-sm';

export const ROW_ACTION_BUTTON =
    'grid size-7 shrink-0 place-items-center rounded-full outline-none transition-[color,background-color,scale] duration-(--duration-fast) hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring active:scale-90 data-[state=open]:bg-accent data-[state=open]:text-foreground pointer-coarse:size-9';
