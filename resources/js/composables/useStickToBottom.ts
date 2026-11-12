import { onBeforeUnmount, watch } from 'vue';
import type { Ref } from 'vue';

/**
 * Keeps a chat history at its end while late content grows it.
 *
 * Images and videos only get their height once they load, after the list was
 * already scrolled to the end, which left the newest message partly hidden
 * behind the composer. While the reader is following the end (they have not
 * scrolled up), every piece of media that finishes loading scrolls the list
 * to its end again. Someone reading older messages is never moved.
 */
export function useStickToBottom(
    scroller: Ref<HTMLElement | null>,
    threshold = 96,
): { follow: (value?: boolean) => void } {
    let following = true;
    let lastTop = 0;

    function onScroll(): void {
        const element = scroller.value;

        if (!element) {
            return;
        }

        const gap =
            element.scrollHeight - element.scrollTop - element.clientHeight;

        if (gap < threshold) {
            following = true;
        } else if (element.scrollTop < lastTop - 2) {
            // Only a move towards older messages stops following: content
            // growing below does not fire a scroll event.
            following = false;
        }

        lastTop = element.scrollTop;
    }

    function onMediaReady(): void {
        const element = scroller.value;

        if (element && following) {
            element.scrollTop = element.scrollHeight;
        }
    }

    function detach(element: HTMLElement): void {
        element.removeEventListener('scroll', onScroll);
        // "load" and "loadedmetadata" do not bubble: listen while capturing.
        element.removeEventListener('load', onMediaReady, true);
        element.removeEventListener('loadedmetadata', onMediaReady, true);
    }

    watch(
        scroller,
        (element, previous) => {
            if (previous) {
                detach(previous);
            }

            if (element) {
                element.addEventListener('scroll', onScroll, { passive: true });
                element.addEventListener('load', onMediaReady, true);
                element.addEventListener('loadedmetadata', onMediaReady, true);
            }
        },
        { immediate: true, flush: 'post' },
    );

    onBeforeUnmount(() => {
        if (scroller.value) {
            detach(scroller.value);
        }
    });

    return {
        /** Called when the list is sent to its end (true) or to a given message (false). */
        follow: (value = true) => {
            following = value;
        },
    };
}
