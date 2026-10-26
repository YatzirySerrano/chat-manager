/*
| Scenario "export": large exports while messages keep arriving.
|
| The superadministrator asks for the conversations and contacts reports
| in XLSX without filters (the whole synthetic history: above the
| synchronous limit, so both are queued on the exports queue). While they
| are generated, customers keep writing (signed fixture webhooks,
| SIMULACIÓN) at LOAD_EXPORT_WEBHOOKS_PER_S and a few agents keep using
| the inbox, so the impact of the export on ingestion and on the inbox is
| measured. The exports page is polled like the browser does until both
| files are ready (bounded by LOAD_EXPORT_TIMEOUT seconds).
*/

import {
    env,
    sleep,
    pick,
    loadManifest,
    writeResult,
    Recorder,
    Session,
    pool,
    inboundBody,
    postWebhook,
    PusherClient,
    SignalWatch,
    summarize,
} from '../lib.mjs';

const manifest = loadManifest();
const baseUrl = env('E2E_BASE_URL');
const rate = Number(env('LOAD_EXPORT_WEBHOOKS_PER_S', 10));
const timeout = Number(env('LOAD_EXPORT_TIMEOUT', 180)) * 1000;
const agentCount = Number(env('LOAD_EXPORT_AGENTS', 5));
const reports = env('LOAD_EXPORT_REPORTS', 'conversations,contacts').split(',');
const label = env('LOAD_LABEL', 'export');
const runId = env('LOAD_RUN_ID', '4');

const recorder = new Recorder();
const admin = new Session(baseUrl, 'superadmin');
await admin.login(manifest.users.superadmin.email, manifest.password);

const agents = manifest.users.agents.slice(0, agentCount);
const agentSessions = [];

await pool(agents, 5, async (agent) => {
    const session = new Session(baseUrl, agent.email);
    await session.login(agent.email, manifest.password);
    agentSessions.push(session);
});

/*
| Real-time probes: LOAD_EXPORT_PROBES assignees watch one of their open
| conversations; once per second a customer writes to one of them (at most
| one probe in flight per conversation) and the time from the POST to
| ".message.recorded" is recorded per phase (baseline, during_export,
| after_export): ingestion + realtime queues, which must not wait behind
| the exports queue.
*/
const probeCount = Number(env('LOAD_EXPORT_PROBES', 5));
const probes = [];

for (const candidate of manifest.probes) {
    if (
        probes.length < probeCount &&
        !probes.some((probe) => probe.agent === candidate.agent)
    ) {
        probes.push(candidate);
    }
}

const watch = new SignalWatch();
const probeClients = [];
const probeLatencies = {};
const probeMissing = {};

if (env('LOAD_REVERB_KEY')) {
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
        await client.subscribe(`conversations.${probe.conversation}`);
        probeClients.push(client);
    }
}

const expectedInbound = [];
let running = true;
let sequence = 0;

/* Baseline: ingestion and inbox for 10 s before the exports start. */
async function traffic(phase) {
    const senders = manifest.existing_senders;

    const ingest = (async () => {
        while (running) {
            const sender = pick(senders);
            const wamid = `wamid.load.export.${runId}.${++sequence}`;
            const connection = manifest.connections[sender.connection];
            const body = JSON.stringify(
                inboundBody(connection, {
                    waId: sender.wa_id,
                    name: 'Cliente histórico',
                    wamid,
                    text: 'Mensaje durante exportación (simulación)',
                    timestamp: Math.floor(Date.now() / 1000),
                }),
            );
            expectedInbound.push(wamid);
            // Fire and forget: the rate must not depend on the response time.
            void recorder.measure(
                `${phase()}.webhook`,
                () =>
                    postWebhook(
                        baseUrl,
                        connection,
                        manifest.webhook_secrets[sender.connection],
                        body,
                    ),
                (status) => status === 200,
            );
            await sleep(1000 / rate);
        }
    })();

    const browse = Promise.all(
        agentSessions.map(async (session) => {
            while (running) {
                await recorder.measure(`${phase()}.inbox.list`, () =>
                    session.inertia('/conversations?view=mine'),
                );
                await sleep(1500);
            }
        }),
    );

    const probing = (async () => {
        const busy = new Set();
        let index = 0;

        while (running && probeClients.length > 0) {
            const probe = probes[index++ % probes.length];

            if (!busy.has(probe.conversation)) {
                busy.add(probe.conversation);
                const wamid = `wamid.load.export.${runId}.probe.${index}`;
                const connection = manifest.connections[probe.connection];
                const body = JSON.stringify(
                    inboundBody(connection, {
                        waId: probe.wa_id,
                        name: 'Cliente sonda',
                        wamid,
                        text: 'Sonda durante exportación (simulación)',
                        timestamp: Math.floor(Date.now() / 1000),
                    }),
                );
                expectedInbound.push(wamid);
                const at = phase();
                const channel = `private-conversations.${probe.conversation}`;
                const started = performance.now();
                const arrival = watch.wait(
                    (event, frameChannel, data) =>
                        event === 'message.recorded' &&
                        frameChannel === channel &&
                        data?.direction === 'inbound',
                    30000,
                );
                void recorder
                    .measure(
                        `${at}.webhook.probe`,
                        () =>
                            postWebhook(
                                baseUrl,
                                connection,
                                manifest.webhook_secrets[probe.connection],
                                body,
                            ),
                        (status) => status === 200,
                    )
                    .then(() => arrival)
                    .then((arrivedAt) => {
                        busy.delete(probe.conversation);

                        if (arrivedAt === null) {
                            probeMissing[at] = (probeMissing[at] ?? 0) + 1;
                        } else {
                            (probeLatencies[at] ??= []).push(
                                arrivedAt - started,
                            );
                        }
                    });
            }

            await sleep(1000);
        }
    })();

    await Promise.all([ingest, browse, probing]);
}

let phase = 'baseline';
recorder.start();
const background = traffic(() => phase);
await sleep(10000);

phase = 'during_export';
const requestedAt = new Date();
const exportStarted = performance.now();

const exportRequests = [];

for (const report of reports) {
    // A form post with the CSRF token, like the "Exportar" menu of the
    // pages; the whole history (status=all) so the file is large and queued.
    // Since phase 5 the contacts report lists only saved contacts unless
    // «Incluir no guardados» is on; the synthetic contacts are mostly
    // provisional, so without it the file stays under the synchronous limit.
    const filters = new URLSearchParams(
        report === 'conversations'
            ? { status: 'all', view: 'all' }
            : report === 'contacts'
              ? { include_provisional: '1' }
              : {},
    );
    const response = await recorder.measure(`export.request.${report}`, () =>
        admin.request('POST', `/exports/${report}/xlsx`, {
            headers: {
                Accept: 'text/html, application/xhtml+xml',
                'Content-Type': 'application/x-www-form-urlencoded',
                Referer: `${baseUrl}/exports`,
            },
            body: filters.toString(),
        }),
    );
    exportRequests.push({
        report,
        status: response?.status ?? null,
        location: response?.headers?.get('location') ?? null,
        bytes: response?.body?.length ?? 0,
    });
}

let finished = null;

while (performance.now() - exportStarted < timeout) {
    await sleep(2000);
    const page = await recorder.measure('exports.poll', () =>
        admin.inertia('/exports'),
    );
    const transfers =
        page && page.status === 200
            ? (JSON.parse(page.body).props?.transfers?.data ??
              JSON.parse(page.body).props?.transfers ??
              [])
            : [];
    const list = Array.isArray(transfers) ? transfers : [];
    const recent = list.filter(
        (transfer) =>
            reports.includes(transfer.report) &&
            new Date(transfer.created_at ?? 0) >=
                new Date(requestedAt.getTime() - 5000),
    );

    if (
        recent.length >= reports.length &&
        recent.every((transfer) =>
            ['completed', 'failed'].includes(
                transfer.status?.value ?? transfer.status,
            ),
        )
    ) {
        finished = {
            seconds: Math.round((performance.now() - exportStarted) / 100) / 10,
            transfers: recent.map((transfer) => ({
                report: transfer.report,
                status: transfer.status?.value ?? transfer.status,
                rows: transfer.rows_total ?? transfer.rows_processed ?? null,
            })),
        };
        break;
    }
}

phase = 'after_export';
await sleep(10000);
running = false;
await background;
// Let the last probes arrive (bounded by their own 30 s timeout).
await sleep(5000);
recorder.stop();

for (const client of probeClients) {
    client.close();
}

writeResult({
    scenario: 'export',
    label,
    parameters: {
        reports,
        webhooks_per_s: rate,
        browsing_agents: agentSessions.length,
        timeout_s: timeout / 1000,
    },
    http: recorder.report(),
    export: finished ?? { seconds: null, timed_out: true },
    realtime_probes: {
        probes: probeClients.length,
        missing: probeMissing,
        message_recorded_ms: Object.fromEntries(
            Object.entries(probeLatencies).map(([at, values]) => [
                at,
                summarize(values),
            ]),
        ),
    },
    export_requests: exportRequests,
    expectations: {
        inbound_wamids: expectedInbound,
        statuses: [],
        prefix: `wamid.load.export.${runId}.`,
        exports_requested_after: requestedAt.toISOString(),
    },
});
