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
/** Whether some component asked for the sound to be unlocked. */
let unlockWanted = false;
/** Whether the unlock listeners are attached right now. */
let unlockListening = false;

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

        // Suspended or interrupted later (Safari in the background, a
        // change of audio device...): the next interaction resumes it.
        if (soundState.value === 'locked' && unlockWanted) {
            installUnlockListeners();
        }
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

const UNLOCK_OPTIONS = { capture: true, passive: true } as const;

function removeUnlockListeners(): void {
    UNLOCK_EVENTS.forEach((type) =>
        window.removeEventListener(type, unlockFromGesture, UNLOCK_OPTIONS),
    );
    unlockListening = false;
}

function installUnlockListeners(): void {
    if (unlockListening || soundState.value === 'unsupported') {
        return;
    }

    unlockListening = true;
    UNLOCK_EVENTS.forEach((type) =>
        window.addEventListener(type, unlockFromGesture, UNLOCK_OPTIONS),
    );
}

function unlockFromGesture(): void {
    const context = contextAfterGesture();

    if (!context) {
        // No activation yet (a touch's pointerdown, Escape...): keep
        // listening. Only a missing Web Audio ends the attempt.
        if (soundState.value === 'unsupported') {
            removeUnlockListeners();
        }

        return;
    }

    if (context.state === 'running') {
        removeUnlockListeners();

        return;
    }

    context.resume().then(
        () => {
            syncSoundState();

            if (context.state === 'running') {
                removeUnlockListeners();
            }
        },
        () => {
            // Still blocked: the next interaction tries again.
        },
    );
}

/**
 * Unlocks the sound with the first interaction on the page that really
 * activates it: the audio context is created or resumed inside that
 * gesture, which is what browsers (Safari/iOS in particular) require.
 * The listeners stay until the context runs (or Web Audio turns out to be
 * unavailable), so an event without activation simply waits for the next
 * one, and they come back whenever the browser suspends the context again.
 */
export function unlockNotificationSound(): void {
    unlockWanted = true;
    installUnlockListeners();
}

/**
 * Prepares the audio without waiting for a new interaction when the
 * browser already allows it: a page that was clicked or typed into before
 * (sticky activation, e.g. the login form) can create a running context at
 * any time. Without that activation nothing is attempted (the browser
 * would refuse it); a context the browser suspended is asked to resume and
 * the result is reported once it settles (false: still blocked).
 */
export async function prepareNotificationSound(): Promise<boolean> {
    const context = contextAfterGesture();

    if (!context) {
        return false;
    }

    if (context.state !== 'running') {
        try {
            await withTimeout(context.resume(), RESUME_GRACE_MS);
        } catch {
            // Blocked or too slow: reported below.
        }

        syncSoundState();
    }

    return context.state === 'running';
}

function withTimeout<T>(promise: Promise<T>, ms: number): Promise<T> {
    return new Promise((resolve, reject) => {
        const timer = window.setTimeout(() => reject(new Error('timeout')), ms);
        promise.then(
            (value) => {
                window.clearTimeout(timer);
                resolve(value);
            },
            (error: unknown) => {
                window.clearTimeout(timer);
                reject(error);
            },
        );
    });
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

/**
 * Sounds of attention alerts (AttentionAlertSound), all synthesized and all
 * distinct from the chime of ordinary notifications, growing with the
 * priority they belong to: a short bright chime (~0.4 s), a two-tone bell
 * (~1.3 s), repeated pulses (~0.8 s) and an alternating tone of ~3 s for
 * urgent alerts. Peaks are balanced by waveform (a square wave sounds much
 * louder than a sine at the same level) so none of them is jarring.
 */
export type AttentionAlertTone = 'chime' | 'bell' | 'pulse' | 'siren' | 'none';

function alertNotes(tone: AttentionAlertTone): Note[] {
    switch (tone) {
        case 'chime':
            return [
                {
                    frequency: 988,
                    at: 0,
                    length: 0.18,
                    peak: 0.16,
                    wave: 'sine',
                },
                {
                    frequency: 1319,
                    at: 0.12,
                    length: 0.28,
                    peak: 0.14,
                    wave: 'sine',
                },
            ];
        case 'bell':
            return [0, 0.55].flatMap((start) => [
                {
                    frequency: 523,
                    at: start,
                    length: 0.5,
                    peak: 0.14,
                    wave: 'triangle' as OscillatorType,
                },
                {
                    frequency: 784,
                    at: start + 0.16,
                    length: 0.6,
                    peak: 0.12,
                    wave: 'triangle' as OscillatorType,
                },
            ]);
        case 'pulse':
            return [0, 0.22, 0.44, 0.66].map((at) => ({
                frequency: 740,
                at,
                length: 0.12,
                peak: 0.06,
                wave: 'square' as OscillatorType,
            }));
        case 'siren':
            // Twelve alternating notes: about three seconds.
            return Array.from({ length: 12 }, (_, index) => ({
                frequency: index % 2 === 0 ? 698 : 932,
                at: index * 0.25,
                length: 0.24,
                peak: 0.14,
                wave: 'triangle' as OscillatorType,
            }));
        default:
            return [];
    }
}

/** Duration of an alert sound, in seconds (0 for «Sin sonido»). */
export function attentionAlertSoundLength(tone: AttentionAlertTone): number {
    const notesToPlay = alertNotes(tone);

    return notesToPlay.length === 0
        ? 0
        : Math.max(...notesToPlay.map((note) => note.at + note.length));
}

/** The alert sound playing now: its own output, so it can be cut short. */
let currentAlert: {
    /** A composer preview, or a received alert. */
    kind: 'preview' | 'alert';
    output: GainNode;
    oscillators: OscillatorNode[];
    until: number;
} | null = null;

/** Whether a received alert is sounding in this tab right now. */
export function isAttentionAlertSounding(): boolean {
    return (
        currentAlert !== null &&
        currentAlert.kind === 'alert' &&
        Date.now() < currentAlert.until
    );
}

/** Cuts the composer's preview, leaving a received alert alone. */
export function stopAttentionAlertPreview(): void {
    if (currentAlert?.kind === 'preview') {
        stopAttentionAlertSound();
    }
}

/** Cuts the alert sound playing in this tab, if any. */
export function stopAttentionAlertSound(): void {
    if (!currentAlert || !audioContext) {
        currentAlert = null;

        return;
    }

    const { output, oscillators } = currentAlert;
    const now = audioContext.currentTime;

    currentAlert = null;

    try {
        // A short fade, so the cut does not click.
        output.gain.cancelScheduledValues(now);
        output.gain.setValueAtTime(output.gain.value, now);
        output.gain.linearRampToValueAtTime(0, now + 0.03);
    } catch {
        // Already finished.
    }

    oscillators.forEach((oscillator) => {
        try {
            oscillator.stop(now + 0.05);
        } catch {
            // This one already finished.
        }
    });
}

function startAlertSound(
    context: AudioContext,
    tone: AttentionAlertTone,
    kind: 'preview' | 'alert',
): void {
    // Never two at once: whatever was sounding stops first.
    stopAttentionAlertSound();

    const notesToPlay = alertNotes(tone);
    const now = context.currentTime;
    const output = context.createGain();

    output.connect(context.destination);

    const oscillators = notesToPlay.map((note) => {
        const oscillator = context.createOscillator();
        const gain = context.createGain();
        const start = now + note.at;

        oscillator.type = note.wave;
        oscillator.frequency.value = note.frequency;
        gain.gain.setValueAtTime(0.0001, start);
        gain.gain.exponentialRampToValueAtTime(note.peak, start + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, start + note.length);
        oscillator.connect(gain).connect(output);
        oscillator.start(start);
        oscillator.stop(start + note.length + 0.03);

        return oscillator;
    });

    currentAlert = {
        kind,
        output,
        oscillators,
        until: Date.now() + attentionAlertSoundLength(tone) * 1000 + 150,
    };
}

/**
 * Plays the sound of a received attention alert. Alerts never play on top
 * of each other: while one is still sounding, the next one stays silent.
 * The audio is prepared first (prepareNotificationSound), so a page the
 * person already interacted with sounds even if this tab never got a click
 * of its own. Resolves false when nothing was played (audio blocked,
 * unsupported, «Sin sonido», or another alert still sounding); the alert
 * stays on screen either way.
 */
export async function playAttentionAlertSound(
    tone: AttentionAlertTone,
): Promise<boolean> {
    if (alertNotes(tone).length === 0 || isAttentionAlertSounding()) {
        return false;
    }

    try {
        // Checked again after the wait: another alert may have started.
        if (
            !(await prepareNotificationSound()) ||
            !audioContext ||
            isAttentionAlertSounding()
        ) {
            return false;
        }

        // A received alert takes over a composer preview.
        startAlertSound(audioContext, tone, 'alert');

        return true;
    } catch {
        return false;
    }
}

/**
 * Plays a sound chosen in the alert composer, from that very selection
 * (an explicit gesture, which is what lets browsers start audio): whatever
 * alert sound is playing stops first, so choosing another one never
 * overlaps them; «Sin sonido» only stops. Nothing is sent or recorded.
 */
let previewChoice = 0;

export function previewAttentionAlertSound(tone: AttentionAlertTone): void {
    stopAttentionAlertSound();

    const choice = ++previewChoice;

    if (alertNotes(tone).length === 0) {
        return;
    }

    try {
        const context = contextAfterGesture();

        if (!context) {
            return;
        }

        if (context.state === 'running') {
            startAlertSound(context, tone, 'preview');

            return;
        }

        const requestedAt = Date.now();

        // Resumed inside the gesture; played only if it is still the
        // latest choice (another selection would have stopped it).
        context.resume().then(
            () => {
                syncSoundState();

                if (
                    context.state === 'running' &&
                    choice === previewChoice &&
                    Date.now() - requestedAt < RESUME_GRACE_MS
                ) {
                    startAlertSound(context, tone, 'preview');
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
