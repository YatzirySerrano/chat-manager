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
const probes = manifest.probes.slice(0, probeCount);
const openTargets = manifest.probes.slice(probeCount);
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

const probeResults = {
    enabled: false,
    latencies: [],
    missing: 0,
    subscribe_errors: 0,
};
let probeClient = null;
const probeWaiters = new Map();

if (env('LOAD_REVERB_KEY') && probes.length > 0) {
    try {
        const session = new Session(baseUrl, 'probe');
        await session.login(manifest.users.superadmin.email, manifest.password);
        probeClient = new PusherClient({
            host: '127.0.0.1',
            port: env('LOAD_REVERB_PORT'),
            key: env('LOAD_REVERB_KEY'),
            origin: baseUrl,
            session,
        });
        await probeClient.connect();

        for (const probe of probes) {
            try {
                await probeClient.subscribe(
                    `conversations.${probe.conversation}`,
                );
            } catch {
                probeResults.subscribe_errors++;
            }
        }

        probeClient.on((event, channel, data) => {
            if (event !== 'message.recorded' || data?.direction !== 'inbound') {
                return;
            }

            const id = Number(
                String(channel).replace('private-conversations.', ''),
            );
            const waiter = probeWaiters.get(id);

            if (waiter) {
                probeWaiters.delete(id);
                waiter(performance.now());
            }
        });
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
    const started = performance.now();
    const arrival = new Promise((resolve) => {
        probeWaiters.set(probe.conversation, resolve);
        setTimeout(() => resolve(null), 60000).unref();
    });

    await deliver({ connectionKey: probe.connection, body, kind: 'probe' });
    const arrivedAt = await arrival;
    probeWaiters.delete(probe.conversation);

    if (arrivedAt === null) {
        probeResults.missing++;
    } else {
        probeResults.latencies.push(arrivedAt - started);
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
probeClient?.close();

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
        ...probeResults,
        latencies: undefined,
        e2e_ms: summarize(probeResults.latencies),
    },
    expectations: {
        inbound_wamids: [...expectedInbound],
        statuses: statusExpectations,
        new_senders: newSenders,
        prefix: `wamid.load.${runId}.`,
    },
});
