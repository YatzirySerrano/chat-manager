import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import type { ChannelAuthorizationCallback } from 'pusher-js';
import { readonly, ref } from 'vue';
import type {
    RealtimeConfig,
    PresenceListener,
    RealtimeHandler,
    RealtimePayload,
    RealtimeState,
} from './types';

/**
 * Single real-time connection of the browser tab (Laravel Echo over the
 * Pusher protocol, served by Laravel Reverb).
 *
 * Channels are reference counted: several components may listen to the
 * same channel and it is left when the last one stops. Every subscription
 * is authorized by the server (POST /broadcasting/auth) with the session
 * cookie, so a channel the user may no longer see is simply refused.
 *
 * Signals are invalidations, never data: handlers ask the server again, so
 * a lost signal is recovered by the reload done on reconnection and by the
 * periodic refresh that pages keep while the connection is down.
 */

const AUTH_ENDPOINT = '/broadcasting/auth';

type ChannelAuthorization = NonNullable<
    Parameters<ChannelAuthorizationCallback>[1]
>;

type Subscription = {
    name: string;
    handlers: Map<string, Set<RealtimeHandler>>;
    attached: Set<string>;
    denied: boolean;
};

/** A presence channel joined by this tab and the member ids it reports. */
type Presence = {
    name: string;
    listeners: Set<PresenceListener>;
    members: Set<number>;
    joined: boolean;
};

const state = ref<RealtimeState>('disabled');
const subscriptions = new Map<string, Subscription>();
const presences = new Map<string, Presence>();
const reconnectListeners = new Set<() => void>();

let echo: Echo<'reverb'> | null = null;
let client: Pusher | null = null;
let currentKey: string | null = null;
let hasConnected = false;

/** Reactive state of the connection (read only). */
export const realtimeState = readonly(state);

function eventName(event: string): string {
    return event.startsWith('.') ? event.slice(1) : event;
}

function xsrfToken(): string | null {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : null;
}

/** Pause before each new attempt of a channel authorization (ms). */
const AUTH_RETRY_DELAYS_MS = [500, 1500, 4000];

/** Longest pause honoured from a Retry-After header (ms). */
const AUTH_RETRY_AFTER_MAX_MS = 10_000;

/** Authorization requests in flight at once; the rest wait their turn. */
const AUTH_CONCURRENCY = 3;

let authInFlight = 0;
const authQueue: Array<() => void> = [];

/**
 * Runs the task when fewer than AUTH_CONCURRENCY authorizations are in
 * flight. Connecting (or an access change) authorizes every channel at once;
 * spreading them avoids bursts on the same rate limit row of the server.
 */
function withAuthSlot<T>(task: () => Promise<T>): Promise<T> {
    return new Promise<T>((resolve, reject) => {
        const run = (): void => {
            authInFlight++;
            task()
                .then(resolve, reject)
                .finally(() => {
                    authInFlight--;
                    authQueue.shift()?.();
                });
        };

        if (authInFlight < AUTH_CONCURRENCY) {
            run();
        } else {
            authQueue.push(run);
        }
    });
}

function isRetryableStatus(status: number): boolean {
    return status === 429 || status >= 500;
}

/** Pause before the attempt after `attempt` (0 based), with ±25 % jitter. */
function retryDelay(attempt: number, response: Response | null): number {
    const retryAfter = Number(response?.headers.get('Retry-After'));

    if (Number.isFinite(retryAfter) && retryAfter > 0) {
        return Math.min(retryAfter * 1000, AUTH_RETRY_AFTER_MAX_MS);
    }

    return AUTH_RETRY_DELAYS_MS[attempt] * (0.75 + Math.random() * 0.5);
}

function channelKey(channelName: string): string {
    return channelName.replace(/^(private|presence)-/, '');
}

/**
 * Whether an answer for this socket and channel is still useful: the socket
 * was not replaced (pusher-js authorizes every channel again on a new one)
 * and some component still listens to the channel.
 */
function isStillWanted(socketId: string, channelName: string): boolean {
    const key = channelKey(channelName);

    return (
        client?.connection.socket_id === socketId &&
        (subscriptions.has(key) || presences.has(key))
    );
}

function authRequest(socketId: string, channelName: string): Promise<Response> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };
    const token = xsrfToken();

    if (token) {
        headers['X-XSRF-TOKEN'] = token;
    }

    return withAuthSlot(() =>
        fetch(AUTH_ENDPOINT, {
            method: 'POST',
            credentials: 'same-origin',
            headers,
            body: JSON.stringify({
                socket_id: socketId,
                channel_name: channelName,
            }),
        }),
    );
}

/**
 * Authorizes a private channel with the session cookie. A refusal (403)
 * marks the subscription as denied: the channel stays silent and the page
 * keeps its periodic refresh, which re-checks permissions on the server.
 *
 * A temporary failure (429, 5xx or no connection) is tried again a few
 * times with a growing, jittered pause (or the server's Retry-After), so a
 * passing server error does not leave the channel silent for the whole
 * session. Retries stop when the socket changes or nobody listens anymore.
 */
function authorize(
    params: { socketId: string; channelName: string },
    callback: ChannelAuthorizationCallback,
): void {
    const { socketId, channelName } = params;

    const attempt = async (retry: number): Promise<void> => {
        let response: Response | null = null;
        let failure = new Error('Channel authorization failed');
        let authorization: ChannelAuthorization | null = null;

        try {
            response = await authRequest(socketId, channelName);

            if (response.ok) {
                authorization = await response.json();
            }
        } catch (error: unknown) {
            response = null;
            failure =
                error instanceof Error
                    ? error
                    : new Error('Channel authorization failed');
        }

        if (authorization) {
            callback(null, authorization);

            return;
        }

        if (response) {
            if (response.status === 403) {
                const subscription = subscriptions.get(channelKey(channelName));

                if (subscription) {
                    subscription.denied = true;
                }
            }

            failure = new Error(
                `Channel authorization failed (${response.status})`,
            );
        }

        const retryable =
            response === null || isRetryableStatus(response.status);

        if (
            retryable &&
            retry < AUTH_RETRY_DELAYS_MS.length &&
            isStillWanted(socketId, channelName)
        ) {
            setTimeout(
                () => {
                    if (isStillWanted(socketId, channelName)) {
                        void attempt(retry + 1);
                    } else {
                        callback(failure, null);
                    }
                },
                retryDelay(retry, response),
            );

            return;
        }

        callback(failure, null);
    };

    void attempt(0);
}

function mapState(pusherState: string): RealtimeState {
    switch (pusherState) {
        case 'connected':
            return 'connected';
        case 'initialized':
        case 'connecting':
            return 'connecting';
        default:
            return 'unavailable';
    }
}

function dispatch(name: string, event: string, payload: unknown): void {
    const handlers = subscriptions.get(name)?.handlers.get(event);

    if (!handlers) {
        return;
    }

    const data =
        payload !== null && typeof payload === 'object'
            ? (payload as RealtimePayload)
            : {};

    handlers.forEach((handler) => {
        try {
            handler(data);
        } catch (error) {
            console.error(error);
        }
    });
}

function attach(subscription: Subscription): void {
    if (!echo) {
        return;
    }

    const channel = echo.private(subscription.name);

    subscription.handlers.forEach((_, event) => {
        if (!subscription.attached.has(event)) {
            channel.listen(`.${event}`, (payload: unknown) =>
                dispatch(subscription.name, event, payload),
            );
            subscription.attached.add(event);
        }
    });
}

function detach(subscription: Subscription): void {
    echo?.leave(subscription.name);
    subscription.attached.clear();
}

function memberId(member: unknown): number | null {
    const id = (member as { id?: unknown } | null)?.id;

    return typeof id === 'number' ? id : null;
}

function notifyPresence(presence: Presence): void {
    const ids = [...presence.members];

    presence.listeners.forEach((listener) => {
        try {
            listener(ids);
        } catch (error) {
            console.error(error);
        }
    });
}

/**
 * Joins a presence channel. The server answers each member with its id
 * only; "here" brings the full list again after every (re)subscription.
 */
function joinPresenceChannel(presence: Presence): void {
    if (!echo || presence.joined) {
        return;
    }

    presence.joined = true;
    echo.join(presence.name)
        .here((members: unknown[]) => {
            presence.members = new Set(
                members.map(memberId).filter((id): id is number => id !== null),
            );
            notifyPresence(presence);
        })
        .joining((member: unknown) => {
            const id = memberId(member);

            if (id !== null) {
                presence.members.add(id);
                notifyPresence(presence);
            }
        })
        .leaving((member: unknown) => {
            const id = memberId(member);

            if (id !== null && presence.members.delete(id)) {
                notifyPresence(presence);
            }
        });
}

function leavePresenceChannel(presence: Presence): void {
    if (presence.joined) {
        echo?.leave(presence.name);
    }

    presence.joined = false;
    presence.members.clear();
    notifyPresence(presence);
}

/**
 * Closing the socket ends every subscription on the server, so channels are
 * not left one by one first: pusher-js defers each frame (setTimeout), and
 * the "unsubscribe" frames would reach the already closed socket and throw
 * "WebSocket is already in CLOSING or CLOSED state" (e.g. on logout).
 */
function disconnect(): void {
    subscriptions.forEach((subscription) => subscription.attached.clear());
    presences.forEach((presence) => {
        presence.joined = false;
        presence.members.clear();
        notifyPresence(presence);
    });
    echo?.disconnect();
    echo = null;
    client = null;
    hasConnected = false;
}

/**
 * Opens, replaces or closes the connection to match the configuration of
 * the current page. Calling it again with the same configuration does
 * nothing, so it can run after every visit.
 */
export function configureRealtime(
    config: RealtimeConfig | null | undefined,
): void {
    const enabled = Boolean(config?.enabled && config.key);
    const key = enabled
        ? JSON.stringify([
              config!.key,
              config!.host,
              config!.port,
              config!.scheme,
              config!.path,
          ])
        : null;

    if (key === currentKey) {
        return;
    }

    disconnect();
    currentKey = key;

    if (!enabled || !config) {
        state.value = 'disabled';

        return;
    }

    const secure = config.scheme === 'https';
    const port = config.port ?? (secure ? 443 : 80);

    client = new Pusher(config.key!, {
        cluster: '',
        wsHost: config.host ?? window.location.hostname,
        wsPort: port,
        wssPort: port,
        wsPath: config.path ?? '',
        forceTLS: secure,
        enabledTransports: ['ws', 'wss'],
        disableStats: true,
        channelAuthorization: { customHandler: authorize },
    });

    state.value = 'connecting';

    client.connection.bind(
        'state_change',
        ({ current }: { previous: string; current: string }) => {
            const next = mapState(current);

            if (next === 'connected') {
                const isReconnection = hasConnected;
                hasConnected = true;
                state.value = next;

                if (isReconnection) {
                    reconnectListeners.forEach((listener) => listener());
                }

                return;
            }

            state.value = next;
        },
    );

    echo = new Echo({
        broadcaster: 'reverb',
        key: config.key!,
        client,
    });

    subscriptions.forEach(attach);
    presences.forEach(joinPresenceChannel);
}

/**
 * Listens to an event of a private channel. Returns the function that stops
 * listening (the channel is left when nobody listens to it any more).
 */
export function subscribe(
    channel: string,
    event: string,
    handler: RealtimeHandler,
): () => void {
    const name = eventName(event);
    let subscription = subscriptions.get(channel);

    if (!subscription) {
        subscription = {
            name: channel,
            handlers: new Map(),
            attached: new Set(),
            denied: false,
        };
        subscriptions.set(channel, subscription);
    }

    const handlers = subscription.handlers.get(name) ?? new Set();
    handlers.add(handler);
    subscription.handlers.set(name, handlers);
    attach(subscription);

    return () => {
        const current = subscriptions.get(channel);
        const set = current?.handlers.get(name);

        if (!current || !set) {
            return;
        }

        set.delete(handler);

        if (set.size === 0) {
            current.handlers.delete(name);
        }

        if (current.handlers.size === 0) {
            detach(current);
            subscriptions.delete(channel);
        }
    };
}

/**
 * Leaves every channel and subscribes again, so each one is authorized
 * again with the user's current permissions (after an access change).
 */
export function resubscribeAll(): void {
    subscriptions.forEach((subscription) => {
        detach(subscription);
        subscription.denied = false;
        attach(subscription);
    });

    presences.forEach((presence) => {
        leavePresenceChannel(presence);
        joinPresenceChannel(presence);
    });

    reconnectListeners.forEach((listener) => listener());
}

/**
 * Joins a presence channel and reports the ids of its members (the tab's
 * own user included) on every change. Returns the function that stops
 * listening; the channel is left when nobody listens to it any more.
 */
export function joinPresence(
    channel: string,
    listener: PresenceListener,
): () => void {
    let presence = presences.get(channel);

    if (!presence) {
        presence = {
            name: channel,
            listeners: new Set(),
            members: new Set(),
            joined: false,
        };
        presences.set(channel, presence);
    }

    presence.listeners.add(listener);
    joinPresenceChannel(presence);

    if (presence.members.size > 0) {
        listener([...presence.members]);
    }

    return () => {
        const current = presences.get(channel);

        if (!current) {
            return;
        }

        current.listeners.delete(listener);

        if (current.listeners.size === 0) {
            leavePresenceChannel(current);
            presences.delete(channel);
        }
    };
}

/**
 * Runs the callback every time the connection comes back after a drop and
 * after channels are authorized again; signals may have been missed then.
 */
export function onReconnect(listener: () => void): () => void {
    reconnectListeners.add(listener);

    return () => reconnectListeners.delete(listener);
}

/** Whether the server refused the channel to the current user. */
export function isDenied(channel: string): boolean {
    return subscriptions.get(channel)?.denied ?? false;
}
