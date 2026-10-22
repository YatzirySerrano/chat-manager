{{--
    Institutional mark. There is no configurable logo yet: when one exists,
    pass it as $logo (a data: URI, never a remote URL, so rendering stays
    offline) and it replaces the default MessagesSquare mark.
--}}
@if (filled($logo ?? null))
    <img class="brand-logo" src="{{ $logo }}" alt="">
@else
    <span class="brand-mark" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M16 10a2 2 0 0 1-2 2H6.828a2 2 0 0 0-1.414.586l-2.202 2.202A.71.71 0 0 1 2 14.286V4a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/>
            <path d="M20 9a2 2 0 0 1 2 2v10.286a.71.71 0 0 1-1.212.502l-2.202-2.202A2 2 0 0 0 17.172 19H10a2 2 0 0 1-2-2v-1"/>
        </svg>
    </span>
@endif
