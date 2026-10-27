import { readonly, ref } from 'vue';
import type { Ref } from 'vue';

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

/**
 * Whether this tab can play the notification sound: `locked` until the
 * user interacts with the page (browsers block audio started without a
 * gesture after opening or reloading it), `ready` once the audio context
 * runs, `unsupported` without Web Audio.
 */
export type NotificationSoundState = 'locked' | 'ready' | 'unsupported';

const soundState = ref<NotificationSoundState>(
    audioContextConstructor() ? 'locked' : 'unsupported',
);

export const notificationSoundState: Readonly<Ref<NotificationSoundState>> =
    readonly(soundState);

let audioContext: AudioContext | null = null;
let unlockInstalled = false;

/** A resume() that settles later than this is not worth a stale chime. */
const RESUME_GRACE_MS = 1_000;

function audioContextConstructor(): typeof AudioContext | null {
    return typeof window !== 'undefined' && 'AudioContext' in window
        ? window.AudioContext
        : null;
}

/**
 * Whether the page already received a user gesture. Without one, creating
 * or resuming an audio context only produces a console warning, so it is
 * not attempted. Browsers without the User Activation API are tried.
 */
function hasUserActivation(): boolean {
    return typeof navigator !== 'undefined' && 'userActivation' in navigator
        ? navigator.userActivation.hasBeenActive
        : true;
}

function syncSoundState(): void {
    if (audioContext) {
        soundState.value =
            audioContext.state === 'running' ? 'ready' : 'locked';
    }
}

/** The shared audio context, created only after a user gesture. */
function contextAfterGesture(): AudioContext | null {
    if (audioContext || !hasUserActivation()) {
        return audioContext;
    }

    const AudioContextClass = audioContextConstructor();

    if (!AudioContextClass) {
        return null;
    }

    try {
        audioContext = new AudioContextClass();
        audioContext.addEventListener('statechange', syncSoundState);
        syncSoundState();
    } catch {
        soundState.value = 'unsupported';
    }

    return audioContext;
}

/**
 * Events that can carry the user activation browsers require to start
 * audio. `pointerdown` only activates the page for a mouse; a touch
 * activates it on `pointerup`/`touchend` (and `click`), and some keys
 * (Escape, shortcuts) never do, so every candidate is listened to.
 */
const UNLOCK_EVENTS = [
    'pointerdown',
    'pointerup',
    'touchend',
    'click',
    'keydown',
] as const;

/**
 * Unlocks the sound with the first interaction on the page that really
 * activates it: the audio context is created or resumed inside that
 * gesture, which is what browsers (Safari/iOS in particular) require.
 * Installed once per tab; the listeners stay until the context runs (or
 * Web Audio turns out to be unavailable), so an event without activation
 * simply waits for the next one.
 */
export function unlockNotificationSound(): void {
    if (unlockInstalled || soundState.value === 'unsupported') {
        return;
    }

    unlockInstalled = true;

    const options = { capture: true, passive: true } as const;

    const remove = (): void => {
        UNLOCK_EVENTS.forEach((type) =>
            window.removeEventListener(type, unlock, options),
        );
    };

    function unlock(): void {
        const context = contextAfterGesture();

        if (!context) {
            // No activation yet (a touch's pointerdown, Escape...): keep
            // listening. Only a missing Web Audio ends the attempt.
            if (soundState.value === 'unsupported') {
                remove();
            }

            return;
        }

        if (context.state === 'running') {
            remove();

            return;
        }

        context.resume().then(
            () => {
                syncSoundState();

                if (context.state === 'running') {
                    remove();
                }
            },
            () => {
                // Still blocked: the next interaction tries again.
            },
        );
    }

    UNLOCK_EVENTS.forEach((type) =>
        window.addEventListener(type, unlock, options),
    );
}

/**
 * `default`: the chime of every notification (two tones when urgent);
 * `team-chat`: a softer, lower two-note "pop" for internal chat messages,
 * distinct from customer notifications without being louder.
 */
export type NotificationTone = 'default' | 'team-chat';

type Note = {
    frequency: number;
    at: number;
    length: number;
    peak: number;
    wave: OscillatorType;
};

function notes(urgent: boolean, tone: NotificationTone): Note[] {
    if (tone === 'team-chat') {
        return [
            {
                frequency: 587,
                at: 0,
                length: 0.12,
                peak: 0.12,
                wave: 'triangle',
            },
            {
                frequency: 784,
                at: 0.09,
                length: 0.16,
                peak: 0.1,
                wave: 'triangle',
            },
        ];
    }

    return (urgent ? [880, 1175] : [660]).map((frequency, index) => ({
        frequency,
        at: index * 0.14,
        length: 0.22,
        peak: 0.18,
        wave: 'sine',
    }));
}

function chime(
    context: AudioContext,
    urgent: boolean,
    tone: NotificationTone,
): void {
    const now = context.currentTime;

    notes(urgent, tone).forEach((note) => {
        const oscillator = context.createOscillator();
        const gain = context.createGain();
        const start = now + note.at;

        oscillator.type = note.wave;
        oscillator.frequency.value = note.frequency;
        gain.gain.setValueAtTime(0.0001, start);
        gain.gain.exponentialRampToValueAtTime(note.peak, start + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, start + note.length);
        oscillator.connect(gain).connect(context.destination);
        oscillator.start(start);
        oscillator.stop(start + note.length + 0.03);
    });
}

/**
 * A short chime (two tones when urgent, a softer one for the internal
 * chat). While the browser keeps audio blocked (no interaction with the
 * page yet) nothing is attempted and nothing fails; a context suspended by
 * the browser is resumed first.
 */
export function playNotificationSound(
    urgent: boolean,
    tone: NotificationTone = 'default',
): void {
    try {
        const context = contextAfterGesture();

        if (!context) {
            return;
        }

        if (context.state === 'running') {
            chime(context, urgent, tone);

            return;
        }

        const requestedAt = Date.now();

        context.resume().then(
            () => {
                syncSoundState();

                if (
                    context.state === 'running' &&
                    Date.now() - requestedAt < RESUME_GRACE_MS
                ) {
                    chime(context, urgent, tone);
                }
            },
            () => {
                // Blocked by the browser: stay silent.
            },
        );
    } catch {
        // Audio is not available in this browser.
    }
}
