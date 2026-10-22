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

    await Promise.all([ingest, browse]);
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
    // A plain navigation, like the export links of the pages; the whole
    // history (status=all) so the file is large and queued.
    const query = report === 'conversations' ? '?status=all&view=all' : '';
    const response = await recorder.measure(`export.request.${report}`, () =>
        admin.request('GET', `/exports/${report}/xlsx${query}`, {
            headers: {
                Accept: 'text/html, application/xhtml+xml',
                Referer: `${baseUrl}/exports`,
            },
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
recorder.stop();

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
    export_requests: exportRequests,
    expectations: {
        inbound_wamids: expectedInbound,
        statuses: [],
        prefix: `wamid.load.export.${runId}.`,
        exports_requested_after: requestedAt.toISOString(),
    },
});
