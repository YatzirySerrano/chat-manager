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
            'chat-bubble rounded-[1.25rem]',
            joinedAbove ? 'rounded-tr-[0.45rem]' : '',
            joinedBelow ? 'rounded-br-[0.45rem]' : 'rounded-br-[0.25rem]',
        ];
    }

    return [
        'chat-bubble rounded-[1.25rem]',
        joinedAbove ? 'rounded-tl-[0.45rem]' : '',
        joinedBelow ? 'rounded-bl-[0.45rem]' : 'rounded-bl-[0.25rem]',
    ];
}

/**
 * Theme colors of a bubble (not used with the viewer's personal colors,
 * which bring their own classes). Received bubbles are paper resting on the
 * canvas; own bubbles carry the palette as a deep gradient with white ink,
 * and re-declare the theme tokens for their content (chat.css), so quotes,
 * links, ticks and file cards inside read light on color.
 */
export function bubbleTone(side: BubbleSide): string {
    return side === 'out'
        ? 'chat-bubble-out'
        : 'chat-bubble-in bg-bubble-in text-bubble-in-foreground';
}

/** A deleted message: a quiet tombstone, the same on both sides. */
export const BUBBLE_TOMBSTONE =
    'bg-muted/80 text-muted-foreground ring-1 ring-border ring-inset';

/** Entrance of a message that arrived while the chat was open. */
export function arriveClass(side: BubbleSide): string {
    return side === 'out' ? 'chat-arrive-out' : 'chat-arrive-in';
}

/** Hover lift of a bubble: lives in chat.css (`.chat-bubble`). */
export const BUBBLE_HOVER = '';

/**
 * A quoted message inside a bubble: a strip with the author on top. With
 * personal colors it only uses the bubble's own text color. Inside an own
 * bubble the theme tokens are already light-on-color.
 */
export function quoteClasses(custom: boolean): string {
    return custom
        ? 'border border-current/30 border-l-[3px] border-l-current'
        : 'border-l-[3px] border-primary/80 bg-background/55 text-muted-foreground hover:bg-background/80';
}

export const QUOTE_BASE =
    'mb-1 block w-full min-w-40 rounded-[0.8rem] px-2.5 py-1.5 text-left text-sm transition-colors duration-(--duration-fast) outline-none focus-visible:ring-2 focus-visible:ring-ring md:text-xs';

/** The time and delivery line in the bottom corner of a bubble. */
export const BUBBLE_META =
    'flex items-center justify-end gap-1 text-[0.6875rem] leading-4 tabular-nums';

/** One reaction under a bubble (pops in, grows under the pointer). */
export function reactionChip(mine: boolean): string {
    return [
        'chat-reaction inline-flex h-6 items-center gap-1 rounded-full border px-1.5 text-xs leading-none text-foreground shadow-(--shadow-control) outline-none focus-visible:ring-2 focus-visible:ring-ring',
        mine
            ? 'border-primary/45 bg-primary-soft text-primary-soft-foreground'
            : 'border-border bg-card',
    ].join(' ');
}

/** The reactions row overlaps the bottom edge of its bubble. */
export const REACTIONS_ROW = 'relative z-[1] -mt-1.5 flex flex-wrap gap-1 px-2';

/**
 * Quick actions beside a bubble: one floating glass pill of icon buttons.
 * Hidden until hover or focus where a fine pointer exists (chat.css),
 * always visible on touch screens.
 */
export const ROW_ACTIONS =
    'chat-row-actions flex shrink-0 items-center gap-0.5 rounded-full border border-border/70 glass p-0.5 text-muted-foreground shadow-popover';

export const ROW_ACTION_BUTTON =
    'grid size-7 shrink-0 place-items-center rounded-full outline-none transition-[color,background-color,scale] duration-(--duration-fast) hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring active:scale-90 data-[state=open]:bg-accent data-[state=open]:text-foreground pointer-coarse:size-9';

/*
 * Pane chrome shared by both chats: the frosted header that floats over
 * the history, the avatar of the person on the other side, and the
 * composer dock with its floating card.
 */

/** The frosted bar that floats over the top of the history. */
export const CHAT_TOPBAR = 'chat-topbar glass-bar';

/** The history scroller under that bar (room for it, sticky labels below it). */
export const CHAT_UNDER_HEADER = 'chat-under-header';

/** The avatar of the other side in a chat header. */
export const CHAT_HEADER_AVATAR =
    'grid size-full place-items-center rounded-full accent-gradient font-display text-sm font-semibold shadow-[inset_0_1px_0_rgb(255_255_255/0.25),var(--shadow-control)] ring-2 ring-background';

/** The dock that holds the composer: transparent, so the canvas shows. */
export const COMPOSER_DOCK =
    'chat-dock relative shrink-0 px-2 pt-1.5 pb-2.5 sm:px-5 sm:pb-4';

/** The floating composer card (chat.css `.chat-composer`). */
export const COMPOSER_CARD =
    'chat-composer mx-auto max-w-[60rem] rounded-[1.4rem]';

/** A round icon tool of the composer toolbar. */
export const COMPOSER_TOOL =
    'size-9 shrink-0 rounded-full text-muted-foreground hover:bg-accent hover:text-foreground pointer-coarse:size-10';
