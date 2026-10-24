import Pusher from 'pusher-js';
import type { RealtimeConfig } from '../types';

/**
 * Real-time signals of the visitor's session: pusher-js straight to Reverb
 * (no Echo), one private channel authorized by
 * POST /api/v1/widget/broadcasting/auth with the visitor's bearer token.
 * Signals carry no content ({type, cursor?}): the frame reads the messages
 * over HTTP. onConnected also fires on every reconnection, so the caller
 * catches up with GET messages?after.
 */
export type RealtimeHandle = {
    isConnected(): boolean;
    disconnect(): void;
};

export function connectRealtime(
    config: RealtimeConfig,
    token: () => string | null,
    handlers: {
        onSignal: (type: string) => void;
        onConnected: () => void;
        onStateChange: (connected: boolean) => void;
    },
): RealtimeHandle | null {
    if (
        !config.enabled ||
        !config.key ||
        !config.channel ||
        !config.auth_endpoint
    ) {
        return null;
    }

    const secure = config.scheme === 'https';
    const port = config.port ?? (secure ? 443 : 80);
    const endpoint = config.auth_endpoint;
    let connected = false;

    const client = new Pusher(config.key, {
        cluster: '',
        wsHost: config.host ?? location.hostname,
        wsPort: port,
        wssPort: port,
        wsPath: config.path ?? '',
        forceTLS: secure,
        enabledTransports: ['ws', 'wss'],
        disableStats: true,
        channelAuthorization: {
            customHandler: ({ socketId, channelName }, callback) => {
                const bearer = token();

                fetch(endpoint, {
                    method: 'POST',
                    credentials: 'omit',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        ...(bearer
                            ? { Authorization: `Bearer ${bearer}` }
                            : {}),
                    },
                    body: JSON.stringify({
                        socket_id: socketId,
                        channel_name: channelName,
                    }),
                })
                    .then(async (response) => {
                        if (!response.ok) {
                            throw new Error(`auth ${response.status}`);
                        }

                        callback(null, await response.json());
                    })
                    .catch((error: Error) => callback(error, null));
            },
        },
    });

    client.connection.bind(
        'state_change',
        ({ current }: { previous: string; current: string }) => {
            const next = current === 'connected';

            if (next !== connected) {
                connected = next;
                handlers.onStateChange(connected);
            }

            if (next) {
                handlers.onConnected();
            }
        },
    );

    const channel = client.subscribe(config.channel);
    channel.bind('webchat.signal', (data: { type?: unknown } | null) => {
        handlers.onSignal(
            typeof data?.type === 'string' ? data.type : 'messages',
        );
    });

    return {
        isConnected: () => connected,
        disconnect: () => client.disconnect(),
    };
}
