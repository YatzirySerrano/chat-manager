/*
| Scenario "agents": LOAD_AGENTS simulated agents (plus the four
| supervisors and the administrator, who look at reports) working at the
| same time for LOAD_DURATION seconds, each with a think time between
| actions (LOAD_THINK_MIN_MS..LOAD_THINK_MAX_MS):
|
| - inbox list (Inertia visit), queue list (partial reload of the list),
| - open a conversation (partial reload of the selection props), its
|   timeline (JSON) and the read marker,
| - send a reply (JSON, with a client reference; delivered by the
|   simulated transport on the messages queue — SIMULACIÓN),
| - accept a conversation from the department queue,
| - Dashboard (page + deferred overview), reports with filters (page +
|   deferred analytics) for the reporting users.
|
| Before the loop, a contention round: every agent of a department tries
| to accept the same pending conversation at the same instant; exactly one
| must win (HTTP 302) and the others must get the controlled conflict (409).
| run.php checks the database afterwards (one assignee, one event).
|
| Optional background inbound traffic (LOAD_BACKGROUND_WEBHOOKS_PER_S)
| keeps queues and real-time signals busy like a real shift.
*/

import {
    env,
    sleep,
    pick,
    between,
    loadManifest,
    writeResult,
    Recorder,
    Session,
    pool,
    uuid,
    inboundBody,
    postWebhook,
} from '../lib.mjs';
import { readFileSync } from 'node:fs';

const manifest = loadManifest();
const baseUrl = env('E2E_BASE_URL');
const agentCount = Number(env('LOAD_AGENTS', 10));
const duration = Number(env('LOAD_DURATION', 60)) * 1000;
const thinkMin = Number(env('LOAD_THINK_MIN_MS', 1000));
const thinkMax = Number(env('LOAD_THINK_MAX_MS', 3000));
const contentionRounds = Number(env('LOAD_CONTENTION_ROUNDS', 5));
const backgroundRate = Number(env('LOAD_BACKGROUND_WEBHOOKS_PER_S', 2));
const label = env('LOAD_LABEL', `agents-${agentCount}`);
const runId = env('LOAD_RUN_ID', '1');

const recorder = new Recorder();
const setup = new Recorder();
const agents = manifest.users.agents.slice(0, agentCount);

if (agents.length < agentCount) {
    throw new Error(
        `The dataset has ${agents.length} agents; ${agentCount} were requested.`,
    );
}

/* --------------------------------------------------------------- sessions */

const sessions = new Map();

await pool(
    [
        ...agents,
        manifest.users.admin,
        ...Object.values(manifest.users.supervisors),
    ],
    8,
    async (user) => {
        const session = new Session(baseUrl, user.email);
        const started = performance.now();

        try {
            await session.login(user.email, manifest.password);
            setup.record('login', {
                status: 200,
                ms: performance.now() - started,
            });
            sessions.set(user.id, session);
        } catch (error) {
            setup.fail('login', error, performance.now() - started);
        }
    },
);

/* -------------------------------------------------- shared queue knowledge */

// The launcher's snapshot of the database (LOAD_STATE) wins over the
// manifest, which only describes the dataset as it was generated.
const state = env('LOAD_STATE')
    ? JSON.parse(readFileSync(env('LOAD_STATE'), 'utf8'))
    : null;
const pendingByDepartment = new Map(
    Object.entries(
        state?.pending_by_department ?? manifest.pending_by_department,
    ).map(([id, list]) => [Number(id), [...list]]),
);
const openByAgent = new Map(
    agents.map((agent) => [
        agent.id,
        [...(state ? (state.open_by_agent[agent.id] ?? []) : agent.open)],
    ]),
);

function takePending(departments) {
    for (const department of [...departments].sort(() => Math.random() - 0.5)) {
        const list = pendingByDepartment.get(department) ?? [];

        if (list.length > 0) {
            return list.splice(Math.floor(Math.random() * list.length), 1)[0];
        }
    }

    return null;
}

/* ------------------------------------------------------- contention round */

const contention = [];

for (let round = 0; round < contentionRounds; round++) {
    // The department with the most agents in this run.
    const byDepartment = new Map();

    for (const agent of agents) {
        for (const department of agent.departments) {
            if ((pendingByDepartment.get(department) ?? []).length > 0) {
                byDepartment.set(department, [
                    ...(byDepartment.get(department) ?? []),
                    agent,
                ]);
            }
        }
    }

    const [department, contenders] =
        [...byDepartment].sort((a, b) => b[1].length - a[1].length)[0] ?? [];

    if (!department || contenders.length < 2) {
        break;
    }

    const conversation = takePending([department]);
    const outcomes = await Promise.all(
        contenders.map(async (agent) => {
            const session = sessions.get(agent.id);
            const response = await recorder.measure(
                'accept.contended',
                () =>
                    session.json(
                        'POST',
                        `/conversations/${conversation}/accept`,
                        {},
                    ),
                (status) => status === 302 || status === 409,
            );

            return { agent: agent.id, status: response?.status ?? 'exception' };
        }),
    );
    const winners = outcomes.filter((outcome) => outcome.status === 302);

    for (const winner of winners) {
        openByAgent.get(winner.agent)?.push(conversation);
    }

    contention.push({
        conversation,
        department,
        contenders: contenders.length,
        winners: winners.map((winner) => winner.agent),
        conflicts: outcomes.filter((outcome) => outcome.status === 409).length,
        other: outcomes
            .filter(
                (outcome) => outcome.status !== 302 && outcome.status !== 409,
            )
            .map((outcome) => outcome.status),
    });
}

/* ------------------------------------------------------------------ loops */

const listProps = [
    'conversations',
    'filters',
    'counts',
    'inbox',
    'unreadCount',
];
const selectionProps = [
    'selectedId',
    'conversation',
    'window',
    'timeline',
    'details',
    'contactUrl',
    'availability',
];
const replies = { sent: 0, client_references: [] };
const accepted = [];

async function agentAction(agent, session) {
    const own = openByAgent.get(agent.id) ?? [];
    const roll = Math.random() * 100;
    const conversation = own.length > 0 ? pick(own) : null;

    if (roll < 25) {
        await recorder.measure('inbox.list', () =>
            session.inertia('/conversations?view=mine'),
        );
    } else if (roll < 35) {
        await recorder.measure('inbox.queue', () =>
            session.inertia('/conversations?view=queue', {
                component: 'conversations/Index',
                only: listProps,
            }),
        );
    } else if (roll < 55 && conversation) {
        await recorder.measure('conversation.open', () =>
            session.inertia(`/conversations?c=${conversation}`, {
                component: 'conversations/Index',
                only: selectionProps,
            }),
        );
    } else if (roll < 65 && conversation) {
        await recorder.measure('conversation.timeline', () =>
            session.request('GET', `/conversations/${conversation}/timeline`, {
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            }),
        );
    } else if (roll < 70 && conversation) {
        await recorder.measure('conversation.read', () =>
            session.json('POST', `/conversations/${conversation}/read`, {}),
        );
    } else if (roll < 85 && conversation) {
        const reference = uuid();
        const response = await recorder.measure(
            'message.send',
            () =>
                session.json(
                    'POST',
                    `/conversations/${conversation}/messages`,
                    {
                        body: 'Respuesta de carga (simulación)',
                        client_reference: reference,
                    },
                ),
            (status) => status === 202,
        );

        if (response?.status === 202) {
            replies.sent++;
            replies.client_references.push(reference);
        }
    } else if (roll < 92) {
        const target = takePending(agent.departments);

        if (target !== null) {
            const response = await recorder.measure(
                'accept.queue',
                () =>
                    session.json('POST', `/conversations/${target}/accept`, {}),
                (status) => status === 302 || status === 409,
            );

            if (response?.status === 302) {
                own.push(target);
                accepted.push(target);
            }
        } else {
            await recorder.measure('inbox.queue', () =>
                session.inertia('/conversations?view=queue', {
                    component: 'conversations/Index',
                    only: listProps,
                }),
            );
        }
    } else {
        await dashboard(session);
    }
}

async function dashboard(session) {
    await recorder.measure('dashboard.page', () =>
        session.inertia('/dashboard'),
    );
    await recorder.measure('dashboard.overview', () =>
        session.inertia('/dashboard', {
            component: 'Dashboard',
            only: ['overview'],
        }),
    );
}

const periods = ['today', '7d', '30d', '90d', 'month'];

async function reportingAction(session) {
    const roll = Math.random() * 100;

    if (roll < 50) {
        const department = pick(Object.values(manifest.departments)).id;
        const query = `?period=${pick(periods)}${Math.random() < 0.5 ? `&department=${department}` : ''}`;
        await recorder.measure('reports.page', () =>
            session.inertia(`/reports${query}`),
        );
        await recorder.measure('reports.analytics', () =>
            session.inertia(`/reports${query}`, {
                component: 'reports/Index',
                only: ['analytics'],
            }),
        );
    } else if (roll < 80) {
        await dashboard(session);
    } else {
        await recorder.measure('inbox.list', () =>
            session.inertia('/conversations'),
        );
    }
}

const deadline = performance.now() + duration;

async function background() {
    if (backgroundRate <= 0) {
        return;
    }

    let index = 0;
    const targets = manifest.probes;

    while (performance.now() < deadline) {
        const target = targets[index++ % targets.length];
        const connection = manifest.connections[target.connection];
        const body = JSON.stringify(
            inboundBody(connection, {
                waId: target.wa_id,
                name: 'Cliente en atención',
                wamid: `wamid.load.agents.${runId}.${index}`,
                text: 'Mensaje de fondo (simulación)',
                timestamp: Math.floor(Date.now() / 1000),
            }),
        );
        await recorder.measure(
            'webhook.background',
            () =>
                postWebhook(
                    baseUrl,
                    connection,
                    manifest.webhook_secrets[target.connection],
                    body,
                ),
            (status) => status === 200,
        );
        await sleep(1000 / backgroundRate);
    }
}

recorder.start();

await Promise.all([
    background(),
    ...agents.map(async (agent) => {
        const session = sessions.get(agent.id);

        if (!session) {
            return;
        }

        await sleep(between(0, thinkMax));

        while (performance.now() < deadline) {
            await agentAction(agent, session);
            await sleep(between(thinkMin, thinkMax));
        }
    }),
    ...[manifest.users.admin, ...Object.values(manifest.users.supervisors)].map(
        async (user) => {
            const session = sessions.get(user.id);

            if (!session) {
                return;
            }

            while (performance.now() < deadline) {
                await reportingAction(session);
                await sleep(between(thinkMin * 2, thinkMax * 2));
            }
        },
    ),
]);

recorder.stop();

writeResult({
    scenario: 'agents',
    label,
    parameters: {
        agents: agentCount,
        reporting_users: 5,
        duration_s: duration / 1000,
        think_ms: [thinkMin, thinkMax],
        background_webhooks_per_s: backgroundRate,
    },
    setup: setup.report(),
    http: recorder.report(),
    contention,
    replies: { sent: replies.sent },
    accepted_from_queue: accepted.length,
    expectations: {
        contention: contention.map(({ conversation, winners }) => ({
            conversation,
            winners,
        })),
        client_references: replies.client_references,
        accepted,
    },
});
