/**
 * Browser-level alerts of in-app notifications: system notifications (the
 * Notification API, only after the user granted permission with an explicit
 * click) and a short sound generated with Web Audio (no audio file).
 */

export type BrowserPermission = NotificationPermission | 'unsupported';

export function browserNotificationPermission(): BrowserPermission {
    return typeof window !== 'undefined' && 'Notification' in window
        ? Notification.permission
        : 'unsupported';
}

/**
 * Must only be called from a user gesture (a click on "Permitir").
 */
export async function requestBrowserNotificationPermission(): Promise<BrowserPermission> {
    if (browserNotificationPermission() === 'unsupported') {
        return 'unsupported';
    }

    try {
        return await Notification.requestPermission();
    } catch {
        return browserNotificationPermission();
    }
}

/**
 * Shows a system notification when allowed. Only the title and the safe
 * summary already rendered by the server are used; clicking it focuses the
 * tab and runs `onClick`.
 */
export function showBrowserNotification(
    id: string,
    title: string,
    body: string | null,
    onClick: () => void,
): void {
    if (browserNotificationPermission() !== 'granted') {
        return;
    }

    try {
        const notification = new Notification(title, {
            body: body ?? undefined,
            tag: `chat-manager-${id}`,
        });

        notification.onclick = () => {
            window.focus();
            onClick();
            notification.close();
        };
    } catch {
        // Some browsers only allow notifications from a service worker.
    }
}

let audioContext: AudioContext | null = null;

/**
 * A short two-tone chime. Browsers may block audio until the user has
 * interacted with the page; then it silently does nothing.
 */
export function playNotificationSound(urgent: boolean): void {
    try {
        audioContext ??= new AudioContext();

        const context = audioContext;
        const now = context.currentTime;
        const tones = urgent ? [880, 1175] : [660];

        tones.forEach((frequency, index) => {
            const oscillator = context.createOscillator();
            const gain = context.createGain();
            const start = now + index * 0.14;

            oscillator.type = 'sine';
            oscillator.frequency.value = frequency;
            gain.gain.setValueAtTime(0.0001, start);
            gain.gain.exponentialRampToValueAtTime(0.18, start + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.22);
            oscillator.connect(gain).connect(context.destination);
            oscillator.start(start);
            oscillator.stop(start + 0.25);
        });
    } catch {
        // Audio is not available in this browser.
    }
}
