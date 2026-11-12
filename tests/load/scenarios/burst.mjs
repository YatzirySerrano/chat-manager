/*
| Scenario "burst": LOAD_BURST_AGENTS agents each write LOAD_BURST_SIZE
| replies in a row (no think time, each one right after the previous HTTP
| answer) to one of their own open conversations, LOAD_BURST_ROUNDS times
| (a different conversation each round, LOAD_BURST_PAUSE_MS between
| rounds). It measures how long each reply of a burst takes from the HTTP
| request until the provider accepted it, which exposes the per-conversation
| order wait of DeliverOutboundMessage.
|
| Run it with a slow simulated provider, e.g.
|   WHATSAPP_SIMULATE_LATENCY_MS=1000-3000 php tests/load/run.php burst
| (SIMULACIÓN: nothing reaches Meta). run.php reads the delivery timing
| stored on each message (metadata.delivery.timing) and reports, per
| position in the burst, time-to-accepted, provider time and the internal
| time (everything but the provider), plus order, duplicates and retries.
*/

import {
    env,
    sleep,
    loadManifest,
    writeResult,
    Recorder,
    Session,
    pool,
    uuid,
} from '../lib.mjs';
import { readFileSync } from 'node:fs';

const manifest = loadManifest();
const baseUrl = env('E2E_BASE_URL');
const agentCount = Number(env('LOAD_BURST_AGENTS', 3));
const burstSize = Number(env('LOAD_BURST_SIZE', 5));
const rounds = Number(env('LOAD_BURST_ROUNDS', 4));
const pauseMs = Number(env('LOAD_BURST_PAUSE_MS', 3000));
const label = env('LOAD_LABEL', `burst-${agentCount}`);

const recorder = new Recorder();
const setup = new Recorder();
const state = env('LOAD_STATE')
    ? JSON.parse(readFileSync(env('LOAD_STATE'), 'utf8'))
    : null;

// Agents that have at least `rounds` open conversations of their own.
const agents = manifest.users.agents
    .map((agent) => ({
        ...agent,
        open: [...(state ? (state.open_by_agent[agent.id] ?? []) : agent.open)],
    }))
    .filter((agent) => agent.open.length >= rounds)
    .slice(0, agentCount);

if (agents.length < agentCount) {
    throw new Error(
        `Only ${agents.length} agents have ${rounds} open conversations; ${agentCount} were requested.`,
    );
}

const sessions = new Map();

await pool(agents, 4, async (agent) => {
    const session = new Session(baseUrl, agent.email);
    const started = performance.now();

    try {
        await session.login(agent.email, manifest.password);
        setup.record('login', { status: 200, ms: performance.now() - started });
        sessions.set(agent.id, session);
    } catch (error) {
        setup.fail('login', error, performance.now() - started);
    }
});

recorder.start();

/** @type {{conversation: number, references: {reference: string, sent_at_ms: number, position: number}[]}[]} */
const bursts = [];

await Promise.all(
    agents.map(async (agent, index) => {
        const session = sessions.get(agent.id);

        if (!session) {
            return;
        }

        // Stagger the agents so their bursts overlap without starting together.
        await sleep(index * 250);

        for (let round = 0; round < rounds; round++) {
            const conversation = agent.open[round];
            const burst = { conversation, references: [] };

            for (let position = 1; position <= burstSize; position++) {
                const reference = uuid();
                const sentAt = Date.now();
                const response = await recorder.measure(
                    'message.send',
                    () =>
                        session.json(
                            'POST',
                            `/conversations/${conversation}/messages`,
                            {
                                body: `Ráfaga de carga ${position}/${burstSize} (simulación)`,
                                client_reference: reference,
                            },
                        ),
                    (status) => status === 202,
                );

                if (response?.status === 202) {
                    burst.references.push({
                        reference,
                        sent_at_ms: sentAt,
                        position,
                    });
                }
            }

            bursts.push(burst);
            await sleep(pauseMs);
        }
    }),
);

recorder.stop();

writeResult({
    scenario: 'burst',
    label,
    parameters: {
        agents: agents.length,
        burst_size: burstSize,
        rounds,
        pause_ms: pauseMs,
        simulated_latency_ms: env('WHATSAPP_SIMULATE_LATENCY_MS', ''),
    },
    setup: setup.report(),
    http: recorder.report(),
    expectations: {
        client_references: bursts.flatMap((burst) =>
            burst.references.map(({ reference }) => reference),
        ),
        bursts,
    },
});
