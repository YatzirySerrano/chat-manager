/*
| Scenario "widget" (phase 4): LOAD_WIDGET_VISITORS web chat visitors at
| once on the connection tests/load/run.php creates for it (widget allowing
| LOAD_WIDGET_ORIGIN), each doing what the embedded widget does:
|
| 1. GET /widget/v1/frame/{key} with the host page as Referer (the frame
|    carries the ticket that proves an authorized page loaded it);
| 2. POST /api/v1/widget/{key}/sessions with that ticket → visitor token;
| 3. (LOAD_WIDGET_SOCKET_SHARE of them) a WebSocket to Reverb subscribed to
|    private-webchat.sessions.{id}, authorized through
|    POST /api/v1/widget/broadcasting/auth with the Bearer token — like a
|    second tab of the same visitor;
| 4. LOAD_WIDGET_MESSAGES messages, one after the other (a person typing),
|    each also resent once with the same client_message_id (a retry after a
|    timeout: must answer 200, never store twice);
| 5. GET messages (the polling fallback) once at the end.
|
| Measured: latencies per operation, the time from each POST to the
| 'webchat.signal' frame on the visitor's own socket, and the end state is
| verified by run.php from the expectations: every accepted message stored
| exactly once (inbound_wamids = 'web.in.{session}.{client uuid}'), one open
| conversation per visitor.
|
| Knobs: LOAD_WIDGET_VISITORS (40), LOAD_WIDGET_MESSAGES (5, at most the
| per-session limit of 20 per minute), LOAD_WIDGET_CONCURRENCY (20),
| LOAD_WIDGET_SOCKET_SHARE (0.5).
*/

import { randomUUID } from 'node:crypto';
import { Recorder, env, pool, sleep, summarize, writeResult } from '../lib.mjs';

const baseUrl = env('E2E_BASE_URL');
const key = env('LOAD_WIDGET_KEY');
const origin = env('LOAD_WIDGET_ORIGIN', 'http://tienda.test');
const visitors = Number(env('LOAD_WIDGET_VISITORS', 40));
const messagesPerVisitor = Math.min(20, Number(env('LOAD_WIDGET_MESSAGES', 5)));
const concurrency = Number(env('LOAD_WIDGET_CONCURRENCY', 20));
const socketShare = Number(env('LOAD_WIDGET_SOCKET_SHARE', 0.5));
const label = env('LOAD_LABEL', `widget-${visitors}`);
const reverb = {
    port: env('LOAD_REVERB_PORT'),
    key: env('LOAD_REVERB_KEY'),
};

if (!key) {
    throw new Error(
        'LOAD_WIDGET_KEY is empty: run it through tests/load/run.php.',
    );
}

const recorder = new Recorder();
const expectedInbound = [];
const signalLatencies = [];
const failures = { frame_without_ticket: 0, no_token: 0, socket: 0 };
const sessions = [];

async function timed(call) {
    const started = performance.now();
    const response = await call();
    const body = await response.text();

    return {
        status: response.status,
        ms: performance.now() - started,
        body,
        json: (() => {
            try {
                return JSON.parse(body);
            } catch {
                return null;
            }
        })(),
    };
}

const api = (path, token, init = {}) =>
    fetch(`${baseUrl}${path}`, {
        ...init,
        headers: {
            Accept: 'application/json',
            ...(init.body ? { 'Content-Type': 'application/json' } : {}),
            ...(token ? { Authorization: `Bearer ${token}` } : {}),
            ...init.headers,
        },
    });

/** The visitor's channel on Reverb, authorized with the Bearer token. */
function visitorSocket(session) {
    return new Promise((resolve, reject) => {
        const socket = new WebSocket(
            `ws://127.0.0.1:${reverb.port}/app/${reverb.key}?protocol=7&client=js&version=8.4.0&flash=false`,
            { headers: { Origin: baseUrl } },
        );
        const watcher = { socket, signals: [] };
        const timer = setTimeout(() => {
            socket.close();
            reject(new Error('socket timeout'));
        }, 15_000);

        socket.addEventListener('message', async (event) => {
            const frame = JSON.parse(String(event.data));

            if (frame.event === 'pusher:connection_established') {
                const socketId = JSON.parse(frame.data).socket_id;
                const channel = session.realtime.channel;
                const auth = await recorder.measure(
                    'visitor.broadcasting_auth',
                    () =>
                        timed(() =>
                            api(
                                '/api/v1/widget/broadcasting/auth',
                                session.token,
                                {
                                    method: 'POST',
                                    body: JSON.stringify({
                                        socket_id: socketId,
                                        channel_name: channel,
                                    }),
                                },
                            ),
                        ),
                );

                if (!auth?.json?.auth) {
                    clearTimeout(timer);
                    socket.close();
                    reject(new Error(`auth ${auth?.status}`));

                    return;
                }

                socket.send(
                    JSON.stringify({
                        event: 'pusher:subscribe',
                        data: { channel, auth: auth.json.auth },
                    }),
                );
            } else if (
                frame.event === 'pusher_internal:subscription_succeeded'
            ) {
                clearTimeout(timer);
                resolve(watcher);
            } else if (frame.event === 'webchat.signal') {
                watcher.signals.push(performance.now());
            }
        });
        socket.addEventListener('error', () => {
            clearTimeout(timer);
            reject(new Error('socket error'));
        });
    });
}

recorder.start();

await pool(
    Array.from({ length: visitors }, (_, index) => index),
    concurrency,
    async (index) => {
        const frame = await recorder.measure('visitor.frame', () =>
            timed(() =>
                fetch(`${baseUrl}/widget/v1/frame/${key}`, {
                    headers: { Referer: `${origin}/producto/${index}` },
                }),
            ),
        );
        const ticket = /"ticket":"([^"]+)"/.exec(frame?.body ?? '')?.[1];

        if (!ticket) {
            failures.frame_without_ticket++;

            return;
        }

        const started = await recorder.measure(
            'visitor.session',
            () =>
                timed(() =>
                    api(`/api/v1/widget/${key}/sessions`, null, {
                        method: 'POST',
                        body: JSON.stringify({
                            ticket,
                            profile: { name: `Visitante de carga ${index}` },
                            landing_url: `${origin}/producto/${index}`,
                        }),
                    }),
                ),
            (status) => status === 201,
        );
        const token = started?.json?.token;

        if (!token) {
            failures.no_token++;

            return;
        }

        const session = { token, ...started.json.data };
        sessions.push(session.id);
        let watcher = null;

        if (session.realtime?.enabled && index < visitors * socketShare) {
            watcher = await visitorSocket(session).catch(() => {
                failures.socket++;

                return null;
            });
        }

        for (let i = 0; i < messagesPerVisitor; i++) {
            const clientId = randomUUID();
            const body = {
                client_message_id: clientId,
                body: `Mensaje ${i + 1} del visitante de carga ${index}`,
            };
            const before = watcher?.signals.length ?? 0;
            const sentAt = performance.now();
            const sent = await recorder.measure(
                'visitor.message',
                () =>
                    timed(() =>
                        api('/api/v1/widget/session/messages', token, {
                            method: 'POST',
                            body: JSON.stringify(body),
                        }),
                    ),
                (status) => status === 201,
            );

            if (sent?.status === 201) {
                expectedInbound.push(`web.in.${session.id}.${clientId}`);
            }

            // A retry of the same message (lost answer): 200, stored once.
            await recorder.measure(
                'visitor.message_retry',
                () =>
                    timed(() =>
                        api('/api/v1/widget/session/messages', token, {
                            method: 'POST',
                            body: JSON.stringify(body),
                        }),
                    ),
                (status) => status === 200,
            );

            if (watcher) {
                const deadline = performance.now() + 8_000;

                while (
                    watcher.signals.length === before &&
                    performance.now() < deadline
                ) {
                    await sleep(25);
                }

                if (watcher.signals.length > before) {
                    signalLatencies.push(watcher.signals[before] - sentAt);
                }
            }

            await sleep(200);
        }

        await recorder.measure('visitor.poll', () =>
            timed(() => api('/api/v1/widget/session/messages', token)),
        );
        watcher?.socket.close();
    },
);

recorder.stop();

writeResult({
    scenario: 'widget',
    label,
    parameters: {
        visitors,
        messages_per_visitor: messagesPerVisitor,
        concurrency,
        socket_share: socketShare,
        origin,
    },
    http: recorder.report(),
    sessions_started: sessions.length,
    failures,
    signal_ms: summarize(signalLatencies),
    expectations: {
        inbound_wamids: expectedInbound,
        web_sessions: sessions,
    },
});
