/*
| Scenario "webhooks": concurrent ingestion of signed WhatsApp Cloud API
| webhooks (SIMULACIÓN: local fixture connections, throwaway secrets).
|
| The mix sent through LOAD_WEBHOOK_CONCURRENCY parallel lanes:
| - new customers (some of them writing 3 messages at once),
| - customers of the historical dataset writing again,
| - messages into open conversations,
| - exact duplicates (same body again, also two copies at the same time),
| - redeliveries of the same message id inside a different envelope,
| - statuses of outbound messages in reverse order (read, delivered, sent).
|
| Like Meta, a webhook answered with 429 or 5xx (or a network error) is
| delivered again later (backoff), so the end state can be checked for
| lost messages. Probes: when Reverb is available, a socket watches some
| open conversations and the time from the POST to the ".message.recorded"
| signal is measured (webhook -> queue -> processing -> broadcast).
|
| The expectations (message ids that must exist exactly once, statuses that
| must end as "read") are written to the result for run.php to verify.
*/

import {
    env,
    sleep,
    pick,
    loadManifest,
    writeResult,
    Recorder,
    Session,
    PusherClient,
    SignalWatch,
    inboundBody,
    statusBody,
    postWebhook,
    summarize,
} from '../lib.mjs';

const manifest = loadManifest();
const baseUrl = env('E2E_BASE_URL');
const total = Number(env('LOAD_WEBHOOK_TOTAL', 2000));
const concurrency = Number(env('LOAD_WEBHOOK_CONCURRENCY', 20));
const ratePerSecond = Number(env('LOAD_WEBHOOK_RATE', 0));
const probeCount = Number(env('LOAD_PROBES', 30));
const runId = env('LOAD_RUN_ID', String(Date.now()));
const label = env('LOAD_LABEL', 'webhooks');

const connections = manifest.connections;
const secrets = manifest.webhook_secrets;
const now = () => Math.floor(Date.now() / 1000);
const recorder = new Recorder();

let sequence = 0;
const nextWamid = () => `wamid.load.${runId}.${++sequence}`;
const newWaId = (index) =>
    `52156${String(Number(runId.slice(-3)) % 10)}${String(index).padStart(7, '0')}`;

/*
| Build the deliveries. Each item is one or more webhooks posted together
| (a "group" goes out concurrently to provoke races).
*/
const expectedInbound = new Set();
const statusExpectations = [];
const groups = [];
let newSenders = 0;
// One probe per agent; the open-conversation traffic of the burst never
// targets a probe agent, so their signals can be attributed to the probe.
const probes = [];

for (const candidate of manifest.probes) {
    if (
        probes.length < probeCount &&
        !probes.some((probe) => probe.agent === candidate.agent)
    ) {
        probes.push(candidate);
    }
}

const probeAgents = new Set(probes.map((probe) => probe.agent));
const openTargets = manifest.probes.filter(
    (target) => !probeAgents.has(target.agent),
);
const statusTargets = [...manifest.status_targets];
const sentBodies = [];

const inbound = (
    connectionKey,
    waId,
    name,
    wamid = nextWamid(),
    text = 'Mensaje de carga (simulación)',
) => {
    expectedInbound.add(wamid);
    const body = JSON.stringify(
        inboundBody(connections[connectionKey], {
            waId,
            name,
            wamid,
            text,
            timestamp: now(),
        }),
    );
    sentBodies.push({ connectionKey, body, wamid, waId, name });

    return { connectionKey, body, kind: 'inbound' };
};

let produced = 0;

while (produced < total) {
    const roll = Math.random() * 100;

    if (roll < 40) {
        const index = ++newSenders;
        groups.push([
            inbound(
                Math.random() < 0.7 ? 'ventas' : 'soporte',
                newWaId(index),
                `Cliente carga ${index}`,
            ),
        ]);
        produced += 1;
    } else if (roll < 50) {
        // A new customer sending three messages at once.
        const index = ++newSenders;
        const connectionKey = Math.random() < 0.7 ? 'ventas' : 'soporte';
        groups.push(
            [1, 2, 3].map(() =>
                inbound(
                    connectionKey,
                    newWaId(index),
                    `Cliente carga ${index}`,
                ),
            ),
        );
        produced += 3;
    } else if (roll < 63) {
        const sender = pick(manifest.existing_senders);
        groups.push([
            inbound(sender.connection, sender.wa_id, 'Cliente histórico'),
        ]);
        produced += 1;
    } else if (roll < 73 && openTargets.length > 0) {
        const target = pick(openTargets);
        groups.push([
            inbound(target.connection, target.wa_id, 'Cliente en atención'),
        ]);
        produced += 1;
    } else if (roll < 83 && sentBodies.length > 0) {
        // Exact duplicate: later on its own, or two copies at the same time.
        const previous = pick(sentBodies);
        const copy = {
            connectionKey: previous.connectionKey,
            body: previous.body,
            kind: 'duplicate',
        };
        groups.push(Math.random() < 0.5 ? [copy] : [copy, { ...copy }]);
        produced += 1;
    } else if (roll < 88 && sentBodies.length > 0) {
        // Same message id, different envelope (another hash, same wamid).
        const previous = pick(sentBodies);
        const body = JSON.stringify(
            inboundBody(connections[previous.connectionKey], {
                waId: previous.waId,
                name: `${previous.name} (reenvío)`,
                wamid: previous.wamid,
                text: 'Mensaje de carga (simulación)',
                timestamp: now() + 1,
            }),
        );
        groups.push([
            { connectionKey: previous.connectionKey, body, kind: 'redelivery' },
        ]);
        produced += 1;
    } else if (statusTargets.length > 0) {
        const target = statusTargets.shift();
        const stamp = now();
        statusExpectations.push({
            message_id: target.message,
            wamid: target.wamid,
            expected: 'read',
        });
        groups.push(
            ['read', 'delivered', 'sent'].map((status, offset) => ({
                connectionKey: target.connection,
                body: JSON.stringify(
                    statusBody(connections[target.connection], {
                        wamid: target.wamid,
                        status,
                        timestamp: stamp - offset,
                    }),
                ),
                kind: 'status',
            })),
        );
        produced += 3;
    }
}

/* ------------------------------------------------------------ realtime probes */

/*
| Each probe conversation is watched by its own assignee (a real agent
| session, like the open chat of their browser), on two channels:
| conversations.{id} (".message.recorded") and users.{agent} (".inbox.changed"
| of that conversation and the ".notification" of the bell). The three
| latencies are measured separately from the POST of the webhook:
| - message_recorded: queue webhooks -> processing -> realtime job -> Reverb,
| - inbox_user: same path, the inbox signal of the assignee,
| - notification: notifications queue -> notification saved -> its signal.
| Probe agents own no other conversation of the burst, so a message signal
| (and a "conversation.message" notification) can only come from their probe.
*/
const probeResults = {
    enabled: false,
    latencies: { message_recorded: [], inbox_user: [], notification: [] },
    missing: { message_recorded: 0, inbox_user: 0, notification: 0 },
    subscribe_errors: 0,
};
const probeClients = [];
const watch = new SignalWatch();

if (env('LOAD_REVERB_KEY') && probes.length > 0) {
    try {
        for (const probe of probes) {
            const agent = manifest.users.agents.find(
                (candidate) => candidate.id === probe.agent,
            );
            const session = new Session(baseUrl, agent.email);
            await session.login(agent.email, manifest.password);
            const client = watch.attach(
                new PusherClient({
                    host: '127.0.0.1',
                    port: env('LOAD_REVERB_PORT'),
                    key: env('LOAD_REVERB_KEY'),
                    origin: baseUrl,
                    session,
                }),
            );
            await client.connect();
            probeClients.push(client);

            for (const channel of [
                `conversations.${probe.conversation}`,
                `users.${probe.agent}`,
            ]) {
                try {
                    await client.subscribe(channel);
                } catch {
                    probeResults.subscribe_errors++;
                }
            }
        }

        probeResults.enabled = true;
    } catch (error) {
        probeResults.error = String(error?.message ?? error);
    }
}

async function sendProbe(probe) {
    const wamid = nextWamid();
    expectedInbound.add(wamid);
    const body = JSON.stringify(
        inboundBody(connections[probe.connection], {
            waId: probe.wa_id,
            name: 'Cliente sonda',
            wamid,
            text: 'Sonda de latencia (simulación)',
            timestamp: now(),
        }),
    );
    const conversationChannel = `private-conversations.${probe.conversation}`;
    const userChannel = `private-users.${probe.agent}`;
    const started = performance.now();
    const arrivals = {
        message_recorded: watch.wait(
            (event, channel, data) =>
                event === 'message.recorded' &&
                channel === conversationChannel &&
                data?.direction === 'inbound',
        ),
        inbox_user: watch.wait(
            (event, channel, data) =>
                event === 'inbox.changed' &&
                channel === userChannel &&
                Number(data?.conversation_id) === probe.conversation,
        ),
        // Only the bell entry of a customer message: new customers of the
        // burst also notify the queue (conversation.pending) to these agents.
        notification: watch.wait(
            (event, channel, data) =>
                event === 'notification' &&
                channel === userChannel &&
                data?.kind === 'conversation.message',
        ),
    };

    await deliver({ connectionKey: probe.connection, body, kind: 'probe' });

    for (const [kind, arrival] of Object.entries(arrivals)) {
        const arrivedAt = await arrival;

        if (arrivedAt === null) {
            probeResults.missing[kind]++;
        } else {
            probeResults.latencies[kind].push(arrivedAt - started);
        }
    }
}

/* ------------------------------------------------------------------ delivery */

const redeliveries = { attempts: 0, gave_up: 0 };

async function deliver(item) {
    // Meta retries failed deliveries with backoff; the test does the same,
    // bounded, so a refused webhook is delayed but not lost.
    for (let attempt = 1; attempt <= 12; attempt++) {
        const name =
            attempt === 1
                ? `webhook.${item.kind}`
                : 'webhook.redelivery_after_refusal';
        const response = await recorder.measure(
            name,
            () =>
                postWebhook(
                    baseUrl,
                    connections[item.connectionKey],
                    secrets[item.connectionKey],
                    item.body,
                ),
            (status) => status === 200,
        );

        if (response !== null && response.status === 200) {
            return;
        }

        redeliveries.attempts++;
        await sleep(Math.min(8000, 250 * 2 ** attempt));
    }

    redeliveries.gave_up++;
}

let started = 0;
const interval = ratePerSecond > 0 ? 1000 / ratePerSecond : 0;
const t0 = performance.now();

recorder.start();

const probeTimer = (async () => {
    if (!probeResults.enabled) {
        return;
    }

    // Spread the probes over the burst, one conversation at a time.
    for (const probe of probes) {
        await sleep(
            Math.max(
                200,
                ((total / Math.max(1, concurrency)) * 4) / probes.length,
            ),
        );
        await sendProbe(probe);
    }
})();

let cursor = 0;
await Promise.all(
    Array.from({ length: concurrency }, async () => {
        while (cursor < groups.length) {
            const group = groups[cursor++];

            if (interval > 0) {
                const due = t0 + started * interval;
                started += group.length;
                const wait = due - performance.now();

                if (wait > 0) {
                    await sleep(wait);
                }
            }

            await Promise.all(group.map((item) => deliver(item)));
        }
    }),
);

recorder.stop();
await probeTimer;
for (const client of probeClients) {
    client.close();
}

const report = recorder.report();
const posted = Object.entries(report.operations)
    .filter(([name]) => name !== 'webhook.redelivery_after_refusal')
    .reduce((sum, [, op]) => sum + op.requests, 0);

writeResult({
    scenario: 'webhooks',
    label,
    parameters: {
        total,
        concurrency,
        rate_per_second: ratePerSecond || 'sin límite',
        probes: probes.length,
    },
    http: report,
    webhooks_posted_first_attempt: posted,
    redeliveries,
    probes: {
        enabled: probeResults.enabled,
        error: probeResults.error,
        subscribe_errors: probeResults.subscribe_errors,
        missing: probeResults.missing,
        e2e_ms: Object.fromEntries(
            Object.entries(probeResults.latencies).map(([kind, values]) => [
                kind,
                summarize(values),
            ]),
        ),
    },
    expectations: {
        inbound_wamids: [...expectedInbound],
        statuses: statusExpectations,
        new_senders: newSenders,
        prefix: `wamid.load.${runId}.`,
    },
});
