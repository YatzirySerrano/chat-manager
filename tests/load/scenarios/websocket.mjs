/*
| Scenario "websocket": LOAD_WS_CONNECTIONS concurrent pusher-protocol
| connections to Reverb (what laravel-echo opens per browser tab), spread
| over the agents of the dataset (several tabs per agent when there are
| more connections than agents). Each one subscribes, through
| POST /broadcasting/auth (real session, CSRF, channel authorization):
| its personal channel users.{id}, the queue channel of its first
| department inbox.queues.{id} and one of its open conversations.
|
| Then:
| 1. fan-out: LOAD_WS_FANOUT_ROUNDS new customers write to the Ventas
|    connection (SIMULACIÓN, signed fixture webhooks); every socket on the
|    Ventas queue channel should get ".inbox.changed": the time from the
|    POST to each socket's first signal is measured;
| 2. reconnect storms: LOAD_WS_STORMS times every socket is closed at once
|    and reconnects + re-authorizes + re-subscribes at once.
| 3. denied channels: a sample of sockets asks for a conversation of
|    another agent; every one must be refused (403), never subscribed.
*/

import {
    env,
    sleep,
    loadManifest,
    writeResult,
    Recorder,
    Session,
    PusherClient,
    pool,
    summarize,
    inboundBody,
    postWebhook,
} from '../lib.mjs';

const manifest = loadManifest();
const baseUrl = env('E2E_BASE_URL');
const connectionCount = Number(env('LOAD_WS_CONNECTIONS', 200));
const storms = Number(env('LOAD_WS_STORMS', 3));
const fanoutRounds = Number(env('LOAD_WS_FANOUT_ROUNDS', 10));
const label = env('LOAD_LABEL', `websocket-${connectionCount}`);
const runId = env('LOAD_RUN_ID', '3');
const reverb = {
    host: '127.0.0.1',
    port: env('LOAD_REVERB_PORT'),
    key: env('LOAD_REVERB_KEY'),
    origin: baseUrl,
};

if (!reverb.key) {
    throw new Error(
        'Reverb is not running for this scenario (LOAD_REVERB_KEY is empty).',
    );
}

const recorder = new Recorder();
const agents = manifest.users.agents.filter((agent) => agent.open.length > 0);
const sessions = new Map();

await pool(
    agents.slice(0, Math.min(agents.length, connectionCount)),
    8,
    async (agent) => {
        const session = new Session(baseUrl, agent.email);
        const started = performance.now();
        await recorder.measure('login', async () => {
            await session.login(agent.email, manifest.password);

            return { status: 200, ms: performance.now() - started };
        });
        sessions.set(agent.id, session);
    },
);

const usable = agents.filter((agent) => sessions.has(agent.id));
const ventas = manifest.departments.ventas.id;

const sockets = Array.from({ length: connectionCount }, (_, index) => {
    const agent = usable[index % usable.length];
    const channels = [
        `users.${agent.id}`,
        `inbox.queues.${agent.departments[0]}`,
        `conversations.${agent.open[index % agent.open.length]}`,
    ];

    return { agent, channels, client: null, received: [] };
});

async function open(socket, phase) {
    const client = new PusherClient({
        ...reverb,
        session: sessions.get(socket.agent.id),
    });
    socket.client = client;
    const started = performance.now();

    try {
        const connectMs = await client.connect();
        recorder.record(`${phase}.connect`, { status: 200, ms: connectMs });
    } catch (error) {
        recorder.fail(`${phase}.connect`, error, performance.now() - started);

        return false;
    }

    client.on((event, channel, data) => {
        if (event === 'inbox.changed') {
            socket.received.push({ at: performance.now(), channel, data });
        }
    });

    let ok = true;

    for (const channel of socket.channels) {
        const subscribeStarted = performance.now();

        try {
            const { authMs, totalMs } = await client.subscribe(channel);
            recorder.record(`${phase}.auth`, { status: 200, ms: authMs });
            recorder.record(`${phase}.subscribe`, { status: 200, ms: totalMs });
        } catch (error) {
            ok = false;
            recorder.record(`${phase}.subscribe`, {
                status: error.status ?? 0,
                ms: performance.now() - subscribeStarted,
                body: String(
                    error.cause?.code ?? error.cause?.message ?? error.message,
                ),
            });
        }
    }

    recorder.record(`${phase}.ready`, {
        status: ok ? 200 : 500,
        ms: performance.now() - started,
    });

    return ok;
}

recorder.start();

const initialStarted = performance.now();
await pool(sockets, 50, (socket) => open(socket, 'initial'));
const initialSeconds = (performance.now() - initialStarted) / 1000;

/* ---------------------------------------------------------------- fan-out */

const ventasSockets = sockets.filter((socket) =>
    socket.channels.includes(`inbox.queues.${ventas}`),
);
const fanout = [];
const connection = manifest.connections.ventas;

for (let round = 1; round <= fanoutRounds; round++) {
    const waId = `52157${runId}${String(round).padStart(7, '0')}`;
    const body = JSON.stringify(
        inboundBody(connection, {
            waId,
            name: `Cliente difusión ${round}`,
            wamid: `wamid.load.ws.${runId}.${round}`,
            text: 'Prueba de difusión (simulación)',
            timestamp: Math.floor(Date.now() / 1000),
        }),
    );
    const sentAt = performance.now();
    await recorder.measure(
        'webhook.fanout',
        () =>
            postWebhook(
                baseUrl,
                connection,
                manifest.webhook_secrets.ventas,
                body,
            ),
        (status) => status === 200,
    );
    await sleep(3000);

    const latencies = [];
    let missing = 0;

    for (const socket of ventasSockets) {
        const first = socket.received.find(
            (entry) =>
                entry.at >= sentAt &&
                entry.channel === `private-inbox.queues.${ventas}`,
        );

        if (first) {
            latencies.push(first.at - sentAt);
        } else {
            missing++;
        }
    }

    fanout.push({
        round,
        sockets: ventasSockets.length,
        missing,
        latency_ms: summarize(latencies),
    });
}

const fanoutAll = summarize(
    fanout.flatMap((round) =>
        round.latency_ms.count ? [round.latency_ms.p50] : [],
    ),
);

/* -------------------------------------------------------- denied channels */

const denied = { attempts: 0, refused: 0, subscribed: 0 };
const others = manifest.users.agents.filter((agent) => agent.open.length > 0);

for (const socket of sockets.slice(0, 20)) {
    const stranger = others.find(
        (agent) =>
            agent.id !== socket.agent.id &&
            !agent.open.some((id) => socket.agent.open.includes(id)),
    );

    if (!stranger || !socket.client?.socketId) {
        continue;
    }

    denied.attempts++;

    try {
        await socket.client.subscribe(`conversations.${stranger.open[0]}`);
        denied.subscribed++;
    } catch (error) {
        if (error.status === 403) {
            denied.refused++;
        }
    }
}

/* ----------------------------------------------------------------- storms */

const stormResults = [];

for (let storm = 1; storm <= storms; storm++) {
    for (const socket of sockets) {
        socket.client?.close();
    }

    await sleep(500);
    const started = performance.now();
    const outcomes = await Promise.all(
        sockets.map((socket) => open(socket, 'storm')),
    );
    stormResults.push({
        storm,
        seconds_until_all_ready:
            Math.round((performance.now() - started) / 10) / 100,
        ready: outcomes.filter(Boolean).length,
        failed: outcomes.filter((outcome) => !outcome).length,
    });
    await sleep(2000);
}

recorder.stop();

for (const socket of sockets) {
    socket.client?.close();
}

writeResult({
    scenario: 'websocket',
    label,
    parameters: {
        connections: connectionCount,
        users: usable.length,
        channels_per_connection: 3,
        storms,
        fanout_rounds: fanoutRounds,
    },
    initial_connect_seconds: Math.round(initialSeconds * 100) / 100,
    http: recorder.report(),
    fanout: { rounds: fanout, p50_of_round_p50_ms: fanoutAll.p50 },
    denied,
    storms: stormResults,
});
