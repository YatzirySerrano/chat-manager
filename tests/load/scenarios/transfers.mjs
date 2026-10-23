/*
| Scenario "transfers": LOAD_TRANSFERS conversation transfers on
| LOAD_TRANSFER_CONCURRENCY parallel lanes while customers keep writing
| into the very conversations being transferred (SIMULACIÓN, signed
| fixture webhooks at LOAD_TRANSFER_WEBHOOKS_PER_S), so transfer locks race
| with inbound processing.
|
| Each transfer is what the dialog does: the assignee opens the
| conversation (partial reload, reads its version) and transfers it,
| alternately to another agent of the same department (user_id) or to the
| queue of another department of the connection (department_id). The
| destination watches its channel (users.{agent} or inbox.queues.{dep}) and
| the time from the POST to its ".inbox.changed" is measured.
|
| Contended rounds (LOAD_TRANSFER_CONTENDED): the assignee and the
| department supervisor transfer the same conversation, with the same
| version, at the same instant to different destinations: exactly one must
| win (302) and the other get the controlled conflict (409). run.php checks
| the database afterwards (destination, one "transferred" event per win).
*/

import {
    env,
    sleep,
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
import { readFileSync } from 'node:fs';

const manifest = loadManifest();
const baseUrl = env('E2E_BASE_URL');
const total = Number(env('LOAD_TRANSFERS', 60));
const concurrency = Number(env('LOAD_TRANSFER_CONCURRENCY', 10));
const contendedRounds = Number(env('LOAD_TRANSFER_CONTENDED', 10));
const inboundRate = Number(env('LOAD_TRANSFER_WEBHOOKS_PER_S', 5));
const label = env('LOAD_LABEL', 'transfers');
const runId = env('LOAD_RUN_ID', '5');
const reverbKey = env('LOAD_REVERB_KEY');

const recorder = new Recorder();
const state = env('LOAD_STATE')
    ? JSON.parse(readFileSync(env('LOAD_STATE'), 'utf8'))
    : null;
const agentsById = new Map(
    manifest.users.agents.map((agent) => [agent.id, agent]),
);

// Candidates: open conversations still attended by the probe's agent.
const stillOpen = (candidate) =>
    state === null ||
    (state.open_by_agent[candidate.agent] ?? []).includes(
        candidate.conversation,
    );
const candidates = manifest.probes.filter(stillOpen);
const needed = total + contendedRounds;

if (candidates.length < needed) {
    throw new Error(
        `Only ${candidates.length} open conversations; ${needed} transfers requested.`,
    );
}

const chosen = candidates.slice(0, needed);
const contended = chosen.slice(0, contendedRounds);
const plain = chosen.slice(contendedRounds);

/* ------------------------------------------------------------ destinations */

// Departments of the same connection (Ventas: ventas, cobranza, postventa).
const connectionDepartments = {
    ventas: ['ventas', 'cobranza', 'postventa'].map(
        (slug) => manifest.departments[slug].id,
    ),
    soporte: [manifest.departments.soporte.id],
};

function destinationFor(candidate, index, avoid = null) {
    const otherDepartments = connectionDepartments[candidate.connection].filter(
        (id) => id !== candidate.department && id !== avoid?.department_id,
    );

    if (index % 2 === 1 && otherDepartments.length > 0) {
        return {
            kind: 'to_department',
            department_id: otherDepartments[index % otherDepartments.length],
        };
    }

    const colleagues = manifest.users.agents.filter(
        (agent) =>
            agent.id !== candidate.agent &&
            agent.id !== avoid?.user_id &&
            agent.departments.includes(candidate.department),
    );

    return {
        kind: 'to_agent',
        user_id: colleagues[index % colleagues.length].id,
    };
}

const plans = plain.map((candidate, index) => ({
    candidate,
    destination: destinationFor(candidate, index),
}));

/* ---------------------------------------------------------------- sessions */

const sessions = new Map();
const supervisorsByDepartment = new Map(
    Object.entries(manifest.users.supervisors).map(([slug, user]) => [
        manifest.departments[slug].id,
        user,
    ]),
);
const loginIds = new Set([
    ...chosen.map((candidate) => candidate.agent),
    ...plans.flatMap(({ destination }) =>
        destination.user_id ? [destination.user_id] : [],
    ),
]);
const users = [
    ...[...loginIds].map((id) => agentsById.get(id)),
    ...supervisorsByDepartment.values(),
];

await pool(users, 8, async (user) => {
    const session = new Session(baseUrl, user.email);
    await session.login(user.email, manifest.password);
    sessions.set(user.id, session);
});

/* ------------------------------------------------- destination listeners */

const watch = new SignalWatch();
const clients = [];
const subscribeErrors = [];

async function listen(user, channels) {
    const client = watch.attach(
        new PusherClient({
            host: '127.0.0.1',
            port: env('LOAD_REVERB_PORT'),
            key: reverbKey,
            origin: baseUrl,
            session: sessions.get(user.id),
        }),
    );
    await client.connect();

    for (const channel of channels) {
        try {
            await client.subscribe(channel);
        } catch (error) {
            subscribeErrors.push(`${channel}: ${error.message}`);
        }
    }

    clients.push(client);
}

if (reverbKey) {
    // Destination agents on their personal channel; each destination
    // department's queue through one member who can accept from it.
    const destinationAgents = new Set(
        plans.flatMap(({ destination }) =>
            destination.user_id ? [destination.user_id] : [],
        ),
    );

    await pool([...destinationAgents], 8, (id) =>
        listen(agentsById.get(id), [`users.${id}`]),
    );

    const queues = new Set(
        plans.flatMap(({ destination }) =>
            destination.department_id ? [destination.department_id] : [],
        ),
    );

    for (const department of queues) {
        const member = manifest.users.agents.find(
            (agent) =>
                agent.departments.includes(department) &&
                sessions.has(agent.id),
        );

        if (member) {
            await listen(member, [`inbox.queues.${department}`]);
        } else {
            subscribeErrors.push(`inbox.queues.${department}: no member`);
        }
    }
}

/* -------------------------------------------------------------- operations */

async function currentVersion(session, conversation) {
    const response = await recorder.measure('conversation.open', () =>
        session.inertia(`/conversations?c=${conversation}`, {
            component: 'conversations/Index',
            only: ['selectedId', 'conversation'],
        }),
    );

    if (response?.status !== 200) {
        return null;
    }

    const props = JSON.parse(response.body).props ?? {};
    const conversationProp = props.conversation?.data ?? props.conversation;

    return conversationProp?.version ?? null;
}

const transferOk = (status) => status === 302 || status === 409;

async function transfer(session, conversation, version, destination, name) {
    return recorder.measure(
        name,
        () =>
            session.json('POST', `/conversations/${conversation}/transfer`, {
                version,
                reason: 'Transferencia de carga (simulación)',
                ...(destination.user_id
                    ? {
                          user_id: destination.user_id,
                          confirm_unavailable: true,
                          confirm_over_capacity: true,
                      }
                    : { department_id: destination.department_id }),
            }),
        transferOk,
    );
}

function signalFor(conversation, destination) {
    const channel = destination.user_id
        ? `private-users.${destination.user_id}`
        : `private-inbox.queues.${destination.department_id}`;

    return watch.wait(
        (event, frameChannel, data) =>
            event === 'inbox.changed' &&
            frameChannel === channel &&
            Number(data?.conversation_id) === conversation,
        30000,
    );
}

/* ----------------------------------------------------------- inbound race */

const expectedInbound = [];
let running = true;
let sequence = 0;

const inbound = (async () => {
    if (inboundRate <= 0) {
        return;
    }

    while (running) {
        const target = chosen[sequence % chosen.length];
        const wamid = `wamid.load.transfers.${runId}.${++sequence}`;
        const connection = manifest.connections[target.connection];
        expectedInbound.push(wamid);
        void recorder.measure(
            'webhook.inbound_during_transfers',
            () =>
                postWebhook(
                    baseUrl,
                    connection,
                    manifest.webhook_secrets[target.connection],
                    JSON.stringify(
                        inboundBody(connection, {
                            waId: target.wa_id,
                            name: 'Cliente en transferencia',
                            wamid,
                            text: 'Mensaje durante transferencia (simulación)',
                            timestamp: Math.floor(Date.now() / 1000),
                        }),
                    ),
                ),
            (status) => status === 200,
        );
        await sleep(1000 / inboundRate);
    }
})();

/* ------------------------------------------------------------------- run */

recorder.start();
await sleep(2000);

const signalLatencies = { to_agent: [], to_department: [] };
const signalMissing = { to_agent: 0, to_department: 0 };
const outcomes = [];

await pool(plans, concurrency, async ({ candidate, destination }) => {
    const session = sessions.get(candidate.agent);
    const version = await currentVersion(session, candidate.conversation);

    if (version === null) {
        outcomes.push({
            conversation: candidate.conversation,
            status: 'no_version',
        });

        return;
    }

    const arrival = signalFor(candidate.conversation, destination);
    const started = performance.now();
    const response = await transfer(
        session,
        candidate.conversation,
        version,
        destination,
        `transfer.${destination.kind}`,
    );
    const status = response?.status ?? 'exception';
    outcomes.push({
        conversation: candidate.conversation,
        status,
        destination,
        winners: status === 302 ? 1 : 0,
    });

    if (status === 302 && reverbKey) {
        const arrivedAt = await arrival;

        if (arrivedAt === null) {
            signalMissing[destination.kind]++;
        } else {
            signalLatencies[destination.kind].push(arrivedAt - started);
        }
    }
});

const contention = [];

for (const [index, candidate] of contended.entries()) {
    const supervisor = supervisorsByDepartment.get(candidate.department);
    const agentSession = sessions.get(candidate.agent);
    const version = await currentVersion(agentSession, candidate.conversation);
    const first = destinationFor(candidate, index);
    const second = destinationFor(candidate, index + 1, first);
    const results = await Promise.all([
        transfer(
            agentSession,
            candidate.conversation,
            version,
            first,
            'transfer.contended',
        ),
        transfer(
            sessions.get(supervisor.id),
            candidate.conversation,
            version,
            second,
            'transfer.contended',
        ),
    ]);
    const statuses = results.map((response) => response?.status ?? 'exception');
    const winnerIndex = statuses.indexOf(302);
    contention.push({
        conversation: candidate.conversation,
        statuses,
        winners: statuses.filter((status) => status === 302).length,
        destination: winnerIndex === -1 ? null : [first, second][winnerIndex],
    });
}

running = false;
await inbound;
await sleep(2000);
recorder.stop();

for (const client of clients) {
    client.close();
}

const byStatus = {};

for (const outcome of outcomes) {
    byStatus[outcome.status] = (byStatus[outcome.status] ?? 0) + 1;
}

writeResult({
    scenario: 'transfers',
    label,
    parameters: {
        transfers: plans.length,
        concurrency,
        contended_rounds: contended.length,
        inbound_webhooks_per_s: inboundRate,
    },
    http: recorder.report(),
    outcomes_by_status: byStatus,
    contention: contention.map(({ conversation, statuses, winners }) => ({
        conversation,
        statuses,
        winners,
    })),
    destination_signal: {
        subscribe_errors: subscribeErrors,
        missing: signalMissing,
        inbox_changed_ms: {
            to_agent: summarize(signalLatencies.to_agent),
            to_department: summarize(signalLatencies.to_department),
        },
    },
    expectations: {
        inbound_wamids: expectedInbound,
        statuses: [],
        prefix: `wamid.load.transfers.${runId}.`,
        transfers: [
            ...outcomes
                .filter((outcome) => outcome.destination)
                .map(({ conversation, destination, winners }) => ({
                    conversation,
                    winners,
                    destination,
                })),
            ...contention.map(({ conversation, winners, destination }) => ({
                conversation,
                winners,
                destination,
            })),
        ],
    },
});
