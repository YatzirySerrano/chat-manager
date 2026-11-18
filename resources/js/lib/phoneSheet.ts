/**
 * Classes for a DialogContent that becomes a bottom sheet on phones: it
 * spans the width, sits on the bottom edge (above the on-screen keyboard
 * when the chat screens follow the visible area, `--app-height`), scrolls
 * inside and keeps its buttons clear of the home indicator. From the small
 * breakpoint up the dialog stays centered as before.
 */
export const PHONE_SHEET_DIALOG = [
    'max-sm:top-auto max-sm:bottom-[calc(100dvh-var(--app-height,100dvh))] max-sm:translate-y-0',
    'max-sm:max-w-full max-sm:rounded-b-none max-sm:border-x-0 max-sm:border-b-0',
    'max-sm:max-h-[calc(var(--app-height,100dvh)-1.5rem)]',
    'max-sm:pb-[max(1.5rem,env(safe-area-inset-bottom))]',
].join(' ');
