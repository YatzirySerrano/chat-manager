/*
| Scenario "reconnect": Reverb is restarted while agents are connected and
| customers keep writing (SIMULACIÓN, signed fixture webhooks).
|
| LOAD_RECONNECT_CONNECTIONS sockets (one per agent with open
| conversations) subscribe users.{id} and one of their open conversations.
| Once per LOAD_RECONNECT_INTERVAL_MS a customer writes to one of those
| conversations (one message in flight per conversation) and the time from
| the POST to ".message.recorded" is recorded per phase. After
| LOAD_RECONNECT_BASELINE_S seconds the scenario asks run.php to restart
| Reverb (control file next to the result); every socket that loses its
| connection reconnects like pusher-js (retry every ~1 s with jitter),
| re-authorizes and re-subscribes; the time from the drop to "ready again"
| is measured. Signals published while Reverb was down are lost by design
| (best effort): the pages recover them with the reload on reconnect; the
| messages themselves must all be stored (run.php verifies).
*/

import {
    env,
    sleep,
    between,
    loadManifest,
    writeResult,
    Recorder,
    Session,
    PusherClient,
    SignalWatch,
    pool,
    summarize,
    inboundBody,
    postWebhook,
} from '../lib.mjs';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';

const manifest = loadManifest();
const baseUrl = env('E2E_BASE_URL');
const connectionCount = Number(env('LOAD_RECONNECT_CONNECTIONS', 100));
const interval = Number(env('LOAD_RECONNECT_INTERVAL_MS', 500));
const baselineSeconds = Number(env('LOAD_RECONNECT_BASELINE_S', 10));
const afterSeconds = Number(env('LOAD_RECONNECT_AFTER_S', 20));
const label = env('LOAD_LABEL', 'reconnect');
const runId = env('LOAD_RUN_ID', '6');
const reverb = {
    host: '127.0.0.1',
    port: env('LOAD_REVERB_PORT'),
    key: env('LOAD_REVERB_KEY'),
    origin: baseUrl,
};

if (!reverb.key) {
    throw new Error('Reverb is not running for this scenario.');
}

const controlDirectory = dirname(env('LOAD_RESULT'));
const restartRequest = join(controlDirectory, `${label}.restart-reverb`);
const restartDone = join(controlDirectory, `${label}.reverb-restarted`);

const recorder = new Recorder();
const watch = new SignalWatch();

// One probe conversation per agent (assigned, open, with its customer).
const probes = [];

for (const candidate of manifest.probes) {
    if (
        probes.length < connectionCount &&
        !probes.some((probe) => probe.agent === candidate.agent)
    ) {
        probes.push(candidate);
    }
}

const sockets = [];

await pool(probes, 8, async (probe) => {
    const agent = manifest.users.agents.find(
        (candidate) => candidate.id === probe.agent,
    );
    const session = new Session(baseUrl, agent.email);
    await session.login(agent.email, manifest.password);
    sockets.push({
        probe,
        session,
        client: watch.attach(new PusherClient({ ...reverb, session })),
        drops: 0,
        ready: false,
        droppedAt: null,
    });
});

let phase = 'baseline';
let stopping = false;
const reconnectMs = [];
let reconnectFailures = 0;

async function open(socket, name) {
    const started = performance.now();

    try {
        await socket.client.connect(5000);

        for (const channel of [
            `users.${socket.probe.agent}`,
            `conversations.${socket.probe.conversation}`,
        ]) {
            await socket.client.subscribe(channel, 5000);
        }

        recorder.record(name, { status: 200, ms: performance.now() - started });
        socket.ready = true;
        socket.client.socket.addEventListener('close', () => {
            socket.ready = false;

            if (!stopping) {
                socket.drops++;
                socket.droppedAt = performance.now();
                void reconnect(socket);
            }
        });

        return true;
    } catch (error) {
        recorder.fail(name, error, performance.now() - started);

        return false;
    }
}

async function reconnect(socket) {
    // pusher-js retries with a short delay; give up after 60 s.
    const deadline = socket.droppedAt + 60000;

    while (!stopping && performance.now() < deadline) {
        await sleep(between(800, 1200));
        socket.client.close();

        if (await open(socket, 'reconnect.attempt')) {
            reconnectMs.push(performance.now() - socket.droppedAt);

            return;
        }
    }

    reconnectFailures++;
}

await pool(sockets, 25, (socket) => open(socket, 'initial.ready'));

/* ----------------------------------------------------------------- traffic */

const expectedInbound = [];
const latencies = {};
const missing = {};
let sequence = 0;
let running = true;

const inFlight = new Set();
const traffic = (async () => {
    const busy = new Set();
    let index = 0;

    while (running) {
        const socket = sockets[index++ % sockets.length];
        const probe = socket.probe;

        if (!busy.has(probe.conversation)) {
            busy.add(probe.conversation);
            const wamid = `wamid.load.reconnect.${runId}.${++sequence}`;
            const connection = manifest.connections[probe.connection];
            const at = phase;
            const channel = `private-conversations.${probe.conversation}`;
            expectedInbound.push(wamid);
            const started = performance.now();
            const arrival = watch.wait(
                (event, frameChannel, data) =>
                    event === 'message.recorded' &&
                    frameChannel === channel &&
                    data?.direction === 'inbound',
                30000,
            );
            const flight = recorder
                .measure(
                    `${at}.webhook`,
                    () =>
                        postWebhook(
                            baseUrl,
                            connection,
                            manifest.webhook_secrets[probe.connection],
                            JSON.stringify(
                                inboundBody(connection, {
                                    waId: probe.wa_id,
                                    name: 'Cliente en reconexión',
                                    wamid,
                                    text: 'Mensaje durante reinicio de Reverb (simulación)',
                                    timestamp: Math.floor(Date.now() / 1000),
                                }),
                            ),
                        ),
                    (status) => status === 200,
                )
                .then(() => arrival)
                .then((arrivedAt) => {
                    busy.delete(probe.conversation);

                    if (arrivedAt === null) {
                        missing[at] = (missing[at] ?? 0) + 1;
                    } else {
                        (latencies[at] ??= []).push(arrivedAt - started);
                    }
                });
            inFlight.add(flight);
            void flight.then(() => inFlight.delete(flight));
        }

        await sleep(interval);
    }
})();

recorder.start();
await sleep(baselineSeconds * 1000);

/* ----------------------------------------------------------------- restart */

phase = 'restart';
const requestedAt = performance.now();
writeFileSync(restartRequest, String(Date.now()));
let restart = null;

while (performance.now() - requestedAt < 60000) {
    if (existsSync(restartDone)) {
        restart = JSON.parse(readFileSync(restartDone, 'utf8'));
        break;
    }

    await sleep(100);
}

const restartSeconds = (performance.now() - requestedAt) / 1000;

// Until every socket is ready again (or 60 s).
while (
    performance.now() - requestedAt < 60000 &&
    sockets.some((socket) => !socket.ready)
) {
    await sleep(200);
}

const allReadySeconds = (performance.now() - requestedAt) / 1000;

phase = 'after';
await sleep(afterSeconds * 1000);
running = false;
await traffic;
// Every probe resolves: its signal arrived or its 30 s timeout counted it
// as missing (signals published while Reverb was down are lost).
await Promise.all(inFlight);
recorder.stop();
stopping = true;

for (const socket of sockets) {
    socket.client.close();
}

writeResult({
    scenario: 'reconnect',
    label,
    parameters: {
        connections: sockets.length,
        interval_ms: interval,
        baseline_s: baselineSeconds,
        after_s: afterSeconds,
    },
    http: recorder.report(),
    restart: {
        ...restart,
        seconds_until_reported_restarted: Math.round(restartSeconds * 10) / 10,
        seconds_until_all_sockets_ready: Math.round(allReadySeconds * 10) / 10,
        sockets_dropped: sockets.filter((socket) => socket.drops > 0).length,
        sockets_not_ready_at_end: sockets.filter((socket) => !socket.ready)
            .length,
        reconnect_failures: reconnectFailures,
        drop_to_ready_ms: summarize(reconnectMs),
    },
    message_recorded_ms: Object.fromEntries(
        Object.entries(latencies).map(([at, values]) => [
            at,
            summarize(values),
        ]),
    ),
    missing_signals: missing,
    expectations: {
        inbound_wamids: expectedInbound,
        statuses: [],
        prefix: `wamid.load.reconnect.${runId}.`,
    },
});
