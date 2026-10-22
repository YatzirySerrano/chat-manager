/*
 * Real-time browser scenario with several concurrent sessions (SPEC 9, 10,
 * 14, 20 "Caída y reconexión de WebSockets", 22 "Tiempo real"): an action in
 * one session must reach the other sessions over the WebSocket, without
 * reloading, faster than the polling fallback (20 s).
 *
 * Three real Chromium contexts: an administrator and two agents of the same
 * department. Real Reverb server, real queue worker, real mouse/keyboard.
 *
 *   E2E_REVERB=1 WHATSAPP_TRANSPORT=simulated WHATSAPP_SIMULATE_READ=1 \
 *     php tests/e2e/run.php realtime.mjs
 *
 * The runner rebuilds chatmanager_testing, starts the web server and Reverb
 * (E2E_REVERB_PORT) with throwaway credentials. This scenario starts its own
 * queue worker (notifications, team chat signals, outbound delivery and the
 * simulated statuses), stops and restarts Reverb for the outage step and
 * stops everything it started before exiting.
 *
 * SIMULACIÓN: customer messages are fake webhooks in the documented WhatsApp
 * Cloud API format (tests/e2e/increment-b-seed.php inbound) and outbound
 * messages use the simulated transport, which reports sent/delivered/read
 * through the same status processing as Meta webhooks. Meta is never called.
 *
 * Each step prints PASSED/FAILED with the observed latency and whether the
 * signal frame was seen on the browser's WebSocket before the UI changed.
 */
import { execFile, spawn, execFileSync } from 'node:child_process';
import { mkdirSync, openSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_MODULE ?? 'playwright');
const execFileAsync = promisify(execFile);

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const BASE = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8020';
const SEED = join(ROOT, 'tests', 'e2e', 'increment-b-seed.php');
const REALTIME_SEED = join(ROOT, 'tests', 'e2e', 'realtime-seed.php');
const SHOTS = process.env.E2E_SCREENSHOTS ?? null;
const REVERB_PORT = Number(process.env.E2E_REVERB_PORT ?? 0);
const PASSWORD = 'e2e-password';
const USERS = {
    admin: 'e2e-admin@example.test',
    ana: 'e2e-ana@example.test',
    beto: 'e2e-beto@example.test',
};
/** Polling fallback of the inbox pages: a live update must beat it. */
const FALLBACK_MS = 20_000;
/** A live update must arrive well before the fallback would refresh. */
const LIVE_BUDGET_MS = 8_000;

if (SHOTS) {
    mkdirSync(SHOTS, { recursive: true });
}

const results = [];
const latencies = [];
const problems = { console: [], pageerror: [], server: [] };
const children = new Set();

function record(id, ok, note = '') {
    results.push({ id, ok, note });
    console.log(`${ok ? 'PASSED' : 'FAILED'} | ${id} | ${note}`);
}

const sessions = [];

async function step(id, scenario) {
    let ok = false;

    try {
        const [passed, note] = await scenario();
        ok = passed;
        record(id, passed, note);
    } catch (error) {
        record(id, false, String(error).split('\n')[0]);
    }

    if (!ok) {
        await Promise.all(
            sessions.map((page) => shot(page, `${id}-${page.session}`)),
        );
    }
}

/* ------------------------------------------------------------------ */
/* Processes started by this scenario (always stopped at the end).     */
/* ------------------------------------------------------------------ */

function startProcess(args, logName = null) {
    const log =
        SHOTS && logName
            ? openSync(join(SHOTS, `${String(logName)}.log`), 'a')
            : 'ignore';
    const child = spawn('php', args, {
        cwd: ROOT,
        env: process.env,
        stdio: ['ignore', log, log],
    });
    children.add(child);
    child.on('exit', () => children.delete(child));

    return child;
}

function stopChildren() {
    for (const child of children) {
        try {
            child.kill('SIGTERM');
        } catch {
            // Already gone.
        }
    }
}

process.on('exit', stopChildren);
process.on('SIGINT', () => {
    stopChildren();
    process.exit(130);
});

function reverbListenerPids() {
    try {
        return execFileSync(
            'lsof',
            ['-nP', '-t', `-iTCP:${REVERB_PORT}`, '-sTCP:LISTEN'],
            { encoding: 'utf8' },
        )
            .split('\n')
            .map((line) => Number(line.trim()))
            .filter((pid) => pid > 0);
    } catch {
        return [];
    }
}

async function waitFor(predicate, timeoutMs, intervalMs = 200) {
    const started = Date.now();

    while (Date.now() - started < timeoutMs) {
        if (await predicate()) {
            return true;
        }

        await new Promise((resolve) => setTimeout(resolve, intervalMs));
    }

    return false;
}

/** Stops the Reverb server (the one the runner started, or ours). */
async function stopReverb() {
    const pids = reverbListenerPids();

    for (const pid of pids) {
        process.kill(pid, 'SIGTERM');
    }

    const stopped = await waitFor(
        () => reverbListenerPids().length === 0,
        10_000,
    );

    if (!stopped) {
        for (const pid of reverbListenerPids()) {
            process.kill(pid, 'SIGKILL');
        }
    }

    return pids.length > 0;
}

async function startReverb() {
    startProcess(
        [
            'artisan',
            'reverb:start',
            '--host=127.0.0.1',
            `--port=${REVERB_PORT}`,
        ],
        'reverb',
    );

    return waitFor(() => reverbListenerPids().length > 0, 15_000);
}

/* ------------------------------------------------------------------ */
/* Browser helpers.                                                    */
/* ------------------------------------------------------------------ */

async function newSession(browser, name) {
    const context = await browser.newContext({
        viewport: { width: 1440, height: 900 },
        locale: 'es-MX',
    });
    // The Vite dev server (public/hot) would push full-page reloads while
    // other people edit sources; the HMR socket is answered locally and kept
    // silent so only the application's own behaviour is observed.
    await context.routeWebSocket(
        (url) => url.port === '5173',
        () => {},
    );
    const page = await context.newPage();
    page.setDefaultTimeout(15_000);
    page.session = name;
    sessions.push(page);
    page.frames$ = [];
    page.auth$ = [];
    page.on('websocket', (socket) => {
        page.frames$.push({ t: Date.now(), event: '__open' });
        socket.on('framereceived', ({ payload }) => {
            try {
                const frame = JSON.parse(String(payload));
                page.frames$.push({
                    t: Date.now(),
                    event: frame.event,
                    channel: frame.channel ?? null,
                });
            } catch {
                // Not JSON: ignore.
            }
        });
        socket.on('close', () =>
            page.frames$.push({ t: Date.now(), event: '__close' }),
        );
    });
    page.on('console', (message) => {
        if (
            message.type() === 'error' &&
            !/status of (403|404|409|422|429)|WebSocket connection to|ERR_CONNECTION_REFUSED/.test(
                message.text(),
            )
        ) {
            problems.console.push(
                `[${name}] ${message.text()} @ ${page.url()}`,
            );
        }
    });
    page.on('pageerror', (error) =>
        problems.pageerror.push(`[${name}] ${String(error)}`),
    );
    page.on('response', (response) => {
        if (response.url().includes('/broadcasting/auth')) {
            let channel = '';

            try {
                channel = JSON.parse(
                    response.request().postData() ?? '{}',
                ).channel_name;
            } catch {
                channel = '?';
            }

            page.auth$.push({
                t: Date.now(),
                status: response.status(),
                channel,
            });
        }

        if (response.status() >= 500) {
            problems.server.push(
                `[${name}] ${response.status()} ${response.url()}`,
            );
        }
    });

    return page;
}

async function login(page, email) {
    await page.goto(`${BASE}/login`);
    await page.locator('#email').click();
    await page.keyboard.type(email);
    await page.locator('#password').click();
    await page.keyboard.type(PASSWORD);
    await page.keyboard.press('Enter');
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}

async function shot(page, name) {
    if (SHOTS) {
        await page
            .screenshot({ path: join(SHOTS, `realtime-${name}.png`) })
            .catch(() => {});
    }
}

const liveIndicator = (page) =>
    page.locator('[role="status"][aria-label="Tiempo real activo"]');
const downIndicator = (page) =>
    page.locator('[role="status"][aria-label^="Tiempo real no disponible"]');
const row = (page, name) =>
    page.locator('[data-inbox-item]', { hasText: name });
const unavailableSelection = (page) =>
    page.getByText('La conversación no está disponible');
const bubble = (page, text) =>
    page.locator('[data-message-id]', { hasText: text });

/** First frame of the event received by the page since the given time. */
function frameSince(page, event, since, channel = null) {
    return page.frames$.find(
        (frame) =>
            frame.t >= since &&
            frame.event === event &&
            (channel === null || (frame.channel ?? '').includes(channel)),
    );
}

/**
 * Waits for the locator in another session and measures the delay from the
 * action. The update counts as live only when it arrived within the budget
 * (well before the fallback) and the WebSocket delivered the signal first.
 */
async function measure(label, page, locator, since, event, options = {}) {
    const state = options.state ?? 'visible';
    const timeout = options.timeout ?? LIVE_BUDGET_MS + 4_000;
    await locator.first().waitFor({ state, timeout });
    const elapsed = Date.now() - since;
    const frame = frameSince(page, event, since, options.channel ?? null);
    const frameMs = frame ? frame.t - since : null;
    latencies.push({ label, session: page.session, elapsed, frameMs, event });
    const live =
        elapsed <= (options.budget ?? LIVE_BUDGET_MS) && frame !== undefined;
    const received = frame
        ? ''
        : ` (frames since: ${[...new Set(page.frames$.filter((f) => f.t >= since).map((f) => f.event))].join(',') || 'none'})`;

    return {
        live,
        elapsed,
        frameMs,
        note: `${page.session}: UI ${elapsed} ms, WS ${event} ${frameMs === null ? 'not seen' : `${frameMs} ms`}${received}`,
    };
}

async function inbound(waId, name, text) {
    const { stdout } = await execFileAsync(
        'php',
        [SEED, 'inbound', waId, name, text],
        { env: process.env, cwd: ROOT },
    );

    return JSON.parse(stdout)[0];
}

async function openQueueView(page) {
    const button = page
        .getByRole('navigation', { name: 'Bandejas' })
        .getByRole('button', { name: /Pendientes/ });
    await button.click();
    await page.waitForFunction(
        () =>
            document
                .querySelector(
                    'nav[aria-label="Bandejas"] button[aria-pressed="true"]',
                )
                ?.textContent?.includes('Pendientes') ?? false,
    );
}

async function openMineView(page) {
    await page
        .getByRole('navigation', { name: 'Bandejas' })
        .getByRole('button', { name: /Mis conversaciones/ })
        .click();
}

async function availabilityChecked(page) {
    return (
        (await page
            .getByRole('switch', {
                name: 'Disponible para aceptar y recibir conversaciones',
            })
            .getAttribute('aria-checked')) === 'true'
    );
}

async function becomeAvailable(page) {
    if (!(await availabilityChecked(page))) {
        await page
            .getByRole('switch', {
                name: 'Disponible para aceptar y recibir conversaciones',
            })
            .click();
        await waitFor(() => availabilityChecked(page), 8_000);
    }

    return availabilityChecked(page);
}

async function bellLabel(page) {
    return page
        .getByRole('button', { name: /^Notificaciones/ })
        .first()
        .getAttribute('aria-label');
}

function bellCount(label) {
    const match = /(\d+) sin leer/.exec(label ?? '');

    return match ? Number(match[1]) : 0;
}

/* ------------------------------------------------------------------ */
/* Scenario.                                                           */
/* ------------------------------------------------------------------ */

const main = async () => {
    if (!REVERB_PORT || process.env.BROADCAST_CONNECTION !== 'reverb') {
        console.error(
            'Run with E2E_REVERB=1 through tests/e2e/run.php (Reverb is required).',
        );
        process.exit(2);
    }

    if (process.env.WHATSAPP_TRANSPORT !== 'simulated') {
        console.error(
            'Run with WHATSAPP_TRANSPORT=simulated (outbound messages are simulated).',
        );
        process.exit(2);
    }

    startProcess(
        [
            'artisan',
            'queue:work',
            '--queue=webhooks,messages,media,notifications,default,exports',
            '--sleep=1',
            '--tries=3',
            '--timeout=60',
            '--max-time=900',
        ],
        'worker',
    );

    const prepared = JSON.parse(
        execFileSync('php', [REALTIME_SEED, 'prepare'], {
            env: process.env,
            cwd: ROOT,
            encoding: 'utf8',
        }),
    );
    const departments = prepared.departments;
    const browser = await chromium.launch();
    const conversations = {};

    try {
        const admin = await newSession(browser, 'admin');
        const ana = await newSession(browser, 'ana');
        const beto = await newSession(browser, 'beto');

        await Promise.all([
            login(admin, USERS.admin),
            login(ana, USERS.ana),
            login(beto, USERS.beto),
        ]);
        await Promise.all(
            [admin, ana, beto].map((page) =>
                page.goto(`${BASE}/conversations`),
            ),
        );

        // 0. Every session is connected to Reverb.
        await step('rt-connected', async () => {
            const states = await Promise.all(
                [admin, ana, beto].map(async (page) => {
                    await liveIndicator(page).waitFor({ timeout: 15_000 });

                    return `${page.session}:${page.frames$.some((f) => f.event === 'pusher:connection_established')}`;
                }),
            );

            return [
                states.every((state) => state.endsWith('true')),
                `indicator "Tiempo real activo" + pusher:connection_established on ${states.join(', ')}`,
            ];
        });

        await Promise.all([openQueueView(ana), openQueueView(beto)]);

        // 1. A simulated inbound message reaches both agents' queues and the
        // administrator's inbox without a reload; unread counters move.
        await step('rt-inbound-new-conversation', async () => {
            const since = Date.now();
            const receipt = await inbound(
                '5215500000301',
                'Cliente RT Uno',
                'Hola, necesito ayuda (simulación)',
            );
            conversations.one = receipt.conversation;
            const measured = await Promise.all([
                measure(
                    'inbound→queue',
                    ana,
                    row(ana, 'Cliente RT Uno'),
                    since,
                    'inbox.changed',
                ),
                measure(
                    'inbound→queue',
                    beto,
                    row(beto, 'Cliente RT Uno'),
                    since,
                    'inbox.changed',
                ),
                measure(
                    'inbound→inbox',
                    admin,
                    row(admin, 'Cliente RT Uno'),
                    since,
                    'inbox.changed',
                ),
            ]);

            return [
                receipt.status === 'pending' && measured.every((m) => m.live),
                `conversation ${receipt.conversation} ${receipt.status}; ${measured.map((m) => m.note).join('; ')}`,
            ];
        });

        await step('rt-unread-counter', async () => {
            const first = await row(beto, 'Cliente RT Uno').getAttribute(
                'aria-label',
            );
            const since = Date.now();
            await inbound(
                '5215500000301',
                'Cliente RT Uno',
                'Sigo esperando (simulación)',
            );
            const measured = await Promise.all([
                measure(
                    'unread→2',
                    beto,
                    beto.locator(
                        '[data-inbox-item][aria-label*="2 mensajes sin leer"]',
                        { hasText: 'Cliente RT Uno' },
                    ),
                    since,
                    'inbox.changed',
                ),
                measure(
                    'unread→2',
                    ana,
                    ana.locator(
                        '[data-inbox-item][aria-label*="2 mensajes sin leer"]',
                        { hasText: 'Cliente RT Uno' },
                    ),
                    since,
                    'inbox.changed',
                ),
            ]);
            const queueCount = await beto
                .getByRole('navigation', { name: 'Bandejas' })
                .getByRole('button', { name: /Pendientes/ })
                .innerText();

            return [
                /1 mensaje sin leer/.test(first ?? '') &&
                    measured.every((m) => m.live),
                `before "${first}"; ${measured.map((m) => m.note).join('; ')}; queue tab "${queueCount.replace(/\s+/g, ' ')}"`,
            ];
        });

        // 2. Availability changes of an agent reach the administrator: the
        // assign dialog lists the agent's declared availability.
        await row(admin, 'Cliente RT Uno').click();
        await admin.waitForURL(/[?&]c=\d+/);

        await step('rt-availability-admin', async () => {
            await admin
                .getByRole('button', {
                    name: 'Más acciones de la conversación',
                })
                .click();
            await admin
                .getByRole('menuitem', { name: 'Asignar a un agente' })
                .click();
            const option = admin.locator('#route-agent option', {
                hasText: 'E2E Ana',
            });
            await option.waitFor({ state: 'attached' });
            const before = await option.innerText();
            const since = Date.now();
            await becomeAvailable(ana);
            const measured = await measure(
                'availability→admin dialog',
                admin,
                admin.locator('#route-agent option', {
                    hasText: 'E2E Ana · Disponible',
                }),
                since,
                'availability.changed',
                { state: 'attached' },
            );
            const after = await option.innerText();
            await admin.keyboard.press('Escape');

            return [
                /No disponible/.test(before) && measured.live,
                `before "${before}", after "${after}"; ${measured.note}`,
            ];
        });

        await step('rt-availability-own-tabs', async () => {
            // Beto's second tab follows the change made in the first one.
            const second = await beto.context().newPage();
            second.session = 'beto-tab2';
            second.frames$ = [];
            second.on('websocket', (socket) =>
                socket.on('framereceived', ({ payload }) => {
                    try {
                        const frame = JSON.parse(String(payload));
                        second.frames$.push({
                            t: Date.now(),
                            event: frame.event,
                            channel: frame.channel ?? null,
                        });
                    } catch {
                        // ignore
                    }
                }),
            );
            await second.goto(`${BASE}/conversations`);
            await liveIndicator(second).waitFor();
            const since = Date.now();
            const available = await becomeAvailable(beto);
            const measured = await measure(
                'availability→own other tab',
                second,
                second.locator('[role="switch"][aria-checked="true"]'),
                since,
                'availability.changed',
            );
            await second.close();

            return [available && measured.live, measured.note];
        });

        // 3. Agent A accepts: the conversation leaves B's queue live and B's
        // open chat says it is no longer available.
        await row(beto, 'Cliente RT Uno').click();
        await beto.waitForURL(/[?&]c=\d+/);
        await row(ana, 'Cliente RT Uno').click();
        await ana.waitForURL(/[?&]c=\d+/);

        await step('rt-accept-leaves-queue', async () => {
            await beto
                .getByRole('group', { name: 'Operaciones de la conversación' })
                .getByRole('button', { name: 'Aceptar' })
                .waitFor();
            const since = Date.now();
            await ana
                .getByRole('group', { name: 'Operaciones de la conversación' })
                .getByRole('button', { name: 'Aceptar' })
                .click();
            const [gone, missing] = await Promise.all([
                measure(
                    'accept→B queue row gone',
                    beto,
                    row(beto, 'Cliente RT Uno'),
                    since,
                    'inbox.changed',
                    { state: 'detached' },
                ),
                measure(
                    'accept→B open chat unavailable',
                    beto,
                    unavailableSelection(beto),
                    since,
                    'conversation.changed',
                ),
            ]);
            // The actor's own success toast is shown exactly once although the
            // change signal reaches the tab while the request is finishing.
            const toast = ana.locator('[data-sonner-toast]', {
                hasText: 'Aceptaste la conversación',
            });
            await toast
                .first()
                .waitFor({ timeout: 5_000 })
                .catch(() => {});
            await ana.waitForTimeout(1_500);
            const toasts = await toast.count();
            // The composer of the agent who accepted is ready to reply.
            const replyMode = await ana
                .getByRole('tab', { name: 'Responder al cliente' })
                .getAttribute('aria-selected');

            return [
                gone.live &&
                    missing.live &&
                    toasts === 1 &&
                    replyMode === 'true',
                `${gone.note}; ${missing.note}; actor success toasts: ${toasts}; actor composer in reply mode: ${replyMode}`,
            ];
        });

        await step('rt-accept-race', async () => {
            const receipt = await inbound(
                '5215500000302',
                'Cliente RT Dos',
                'Hola, ¿me atienden? (simulación)',
            );
            conversations.two = receipt.conversation;
            await openQueueView(ana);
            await Promise.all([
                row(ana, 'Cliente RT Dos').waitFor(),
                row(beto, 'Cliente RT Dos').waitFor(),
            ]);
            await row(ana, 'Cliente RT Dos').click();
            await row(beto, 'Cliente RT Dos').click();
            const acceptA = ana
                .getByRole('group', { name: 'Operaciones de la conversación' })
                .getByRole('button', { name: 'Aceptar' });
            const acceptB = beto
                .getByRole('group', { name: 'Operaciones de la conversación' })
                .getByRole('button', { name: 'Aceptar' });
            await Promise.all([acceptA.waitFor(), acceptB.waitFor()]);
            // Toasts of earlier steps must not be read as this race's outcome.
            await Promise.all(
                [ana, beto].map((page) =>
                    page
                        .locator('[data-sonner-toast]')
                        .first()
                        .waitFor({ state: 'detached', timeout: 15_000 })
                        .catch(() => {}),
                ),
            );
            // Both requests leave at the same moment (a real race): the DOM
            // click runs in each browser without waiting for the other.
            await Promise.all(
                [acceptA, acceptB].map((button) =>
                    button.evaluate((element) => element.click()),
                ),
            );
            const outcome = async (page) => {
                const toast = page.locator('[data-sonner-toast]', {
                    hasText:
                        /Aceptaste la conversación|Otra persona ya aceptó esta conversación/,
                });
                await toast
                    .first()
                    .waitFor({ timeout: 10_000 })
                    .catch(() => {});
                const text = (await toast.allInnerTexts()).join(' ');
                const visible = (
                    await page.locator('[data-sonner-toast]').allInnerTexts()
                )
                    .join(' | ')
                    .replace(/\s+/g, ' ');

                return /Aceptaste/.test(text)
                    ? 'won'
                    : /Otra persona/.test(text)
                      ? 'refused'
                      : `none(${visible})`;
            };
            const [a, b] = await Promise.all([outcome(ana), outcome(beto)]);
            const loser = a === 'refused' ? ana : beto;
            // The refused session ends up without the conversation: no row in
            // its queue and no accept button left.
            const settled = await waitFor(
                async () =>
                    (await row(loser, 'Cliente RT Dos').count()) === 0 &&
                    (await loser
                        .getByRole('button', { name: 'Aceptar' })
                        .count()) === 0,
                LIVE_BUDGET_MS,
            );

            return [
                [a, b].sort().join(',') === 'refused,won' && settled,
                `ana=${a}, beto=${b}; refused session (${loser.session}) left without the conversation: ${settled}`,
            ];
        });

        // 4. A replies and adds a note: the administrator sees both live, and
        // the delivery ticks follow the simulated statuses.
        await openMineView(ana);
        await row(ana, 'Cliente RT Uno').click();
        await ana.waitForURL(new RegExp(`c=${conversations.one}`));
        const adminOpenedAt = Date.now();
        await admin.goto(`${BASE}/conversations?c=${conversations.one}`);
        await liveIndicator(admin).waitFor();
        // The open chat's private channel is authorized and subscribed.
        await waitFor(
            () =>
                admin.frames$.some(
                    (frame) =>
                        frame.t >= adminOpenedAt &&
                        frame.event ===
                            'pusher_internal:subscription_succeeded' &&
                        frame.channel ===
                            `private-conversations.${conversations.one}`,
                ),
            10_000,
        );

        await step('rt-message-to-admin', async () => {
            await ana.getByRole('log', { name: /Cliente RT Uno/ }).waitFor();
            await ana
                .locator('[role="tab"][aria-selected="true"]', {
                    hasText: 'Responder al cliente',
                })
                .waitFor();
            await ana.locator('#chat-composer').click();
            await ana.keyboard.type('Hola, soy Ana. Te ayudo enseguida.');
            const since = Date.now();
            const posted = ana.waitForResponse(
                (response) =>
                    response.request().method() === 'POST' &&
                    /\/conversations\/\d+\/messages$/.test(response.url()),
            );
            await ana
                .getByRole('button', { name: 'Enviar', exact: true })
                .click();
            const measured = await measure(
                'reply→admin open chat',
                admin,
                bubble(admin, 'Hola, soy Ana. Te ayudo enseguida.'),
                since,
                'message.recorded',
            );
            const response = await posted;
            const timing = response.request().timing();

            return [
                measured.live,
                `${measured.note}; sender POST ${response.status()} sent ${Math.round(timing.startTime - since)} ms after the click, answered in ${Math.round(timing.responseEnd)} ms`,
            ];
        });

        await step('rt-status-ticks', async () => {
            const target = bubble(admin, 'Hola, soy Ana. Te ayudo enseguida.');
            const seen = [];
            const since = Date.now();
            const done = await waitFor(
                async () => {
                    const label = (
                        await target.locator('.sr-only').allInnerTexts()
                    ).join('|');
                    const last = seen[seen.length - 1];

                    if (label !== last) {
                        seen.push(label);
                    }

                    return /Leído/.test(label);
                },
                20_000,
                150,
            );
            const frame = frameSince(admin, 'message.status', since - 10_000);
            const simulated = await target.getByText('Simulación').count();
            const text = done
                ? ''
                : ` bubble: "${(await target.innerText()).replace(/\s+/g, ' ')}"`;

            return [
                done && frame !== undefined,
                `labels seen on admin: ${seen.join(' → ')}; message.status frame ${frame ? 'seen' : 'missing'}; simulated badge ${simulated > 0}; ${Date.now() - since} ms${text}`,
            ];
        });

        await step('rt-note-to-admin', async () => {
            await ana.getByRole('tab', { name: 'Nota interna' }).click();
            await ana.locator('#chat-composer').click();
            await ana.keyboard.type('Nota RT: cliente frecuente');
            const since = Date.now();
            await ana.getByRole('button', { name: 'Guardar nota' }).click();
            const measured = await measure(
                'note→admin open chat',
                admin,
                admin.getByText('Nota RT: cliente frecuente'),
                since,
                'note.saved',
            );
            await ana
                .getByRole('tab', { name: 'Responder al cliente' })
                .click();

            return [measured.live, measured.note];
        });

        // 5. Transfer from A to B: B is notified and sees it, A loses it.
        await openMineView(beto);

        await step('rt-transfer', async () => {
            // Ordinary agents only see themselves (assigned scope), so they can
            // transfer to a department queue but not to a named colleague: the
            // administrator moves A's conversation to B.
            const bellBefore = bellCount(await bellLabel(beto));
            await admin
                .getByRole('button', {
                    name: 'Más acciones de la conversación',
                })
                .click();
            await admin.getByRole('menuitem', { name: 'Transferir' }).click();
            const option = admin.locator('#route-agent option', {
                hasText: 'E2E Beto',
            });
            await option.waitFor({ state: 'attached' });
            await admin
                .locator('#route-agent')
                .selectOption(await option.getAttribute('value'));
            await admin
                .locator('#transfer-reason')
                .fill('Prueba E2E de tiempo real');
            const since = Date.now();
            await admin
                .getByRole('dialog')
                .getByRole('button', { name: 'Transferir', exact: true })
                .click();
            const [received, belled, lost, lostRow] = await Promise.all([
                measure(
                    'transfer→B mine',
                    beto,
                    row(beto, 'Cliente RT Uno'),
                    since,
                    'inbox.changed',
                ),
                measure(
                    'transfer→B bell',
                    beto,
                    beto.getByRole('button', {
                        name: new RegExp(
                            `Notificaciones, ${bellBefore + 1} sin leer`,
                        ),
                    }),
                    since,
                    'notification',
                ),
                measure(
                    'transfer→A open chat unavailable',
                    ana,
                    unavailableSelection(ana),
                    since,
                    'conversation.changed',
                ),
                measure(
                    'transfer→A list row gone',
                    ana,
                    row(ana, 'Cliente RT Uno'),
                    since,
                    'inbox.changed',
                    { state: 'detached' },
                ),
            ]);

            return [
                received.live && belled.live && lost.live && lostRow.live,
                `${received.note}; ${belled.note} (bell ${bellBefore}→${bellBefore + 1}); ${lost.note}; ${lostRow.note}`,
            ];
        });

        // 6. Internal team chat: A writes to B, B sees it live with the unread
        // badge; the read receipt reaches A.
        await Promise.all([
            ana.goto(`${BASE}/team-chat`),
            beto.goto(`${BASE}/team-chat`),
        ]);
        await Promise.all([
            liveIndicator(ana).waitFor(),
            liveIndicator(beto).waitFor(),
        ]);

        await step('rt-team-chat-message', async () => {
            await ana
                .getByRole('button', { name: 'Nuevo chat', exact: true })
                .first()
                .click();
            await ana.getByPlaceholder('Buscar por nombre').fill('Beto');
            await ana.getByRole('option', { name: /E2E Beto/ }).click();
            await ana.waitForURL(/team-chat\/threads\/\d+/);
            // The new (empty) chat already reaches B's list; let that settle so
            // the unread badge below can only come from the message signal.
            await beto
                .locator('nav[aria-label="Lista de chats"]', {
                    hasText: 'E2E Ana',
                })
                .waitFor({ timeout: LIVE_BUDGET_MS });
            await beto.waitForTimeout(2_500);
            const badgeBefore = await beto
                .locator('[aria-label="1 sin leer"]')
                .count();
            await ana.getByPlaceholder('Escribe un mensaje interno…').click();
            await ana.keyboard.type('Hola Beto, ¿me apoyas con un cliente?');
            const since = Date.now();
            await ana.keyboard.press('Enter');
            const badge = await measure(
                'team chat→B unread badge',
                beto,
                beto.locator('[aria-label="1 sin leer"]'),
                since,
                'team-chat.changed',
            );
            const preview = await beto
                .locator('nav[aria-label="Lista de chats"]')
                .getByText('¿me apoyas con un cliente?')
                .count();

            return [
                badgeBefore === 0 && badge.live && preview > 0,
                `thread listed for B on creation; ${badge.note}; preview in B's list: ${preview > 0}`,
            ];
        });

        await step('rt-team-chat-read-receipt', async () => {
            const since = Date.now();
            await beto
                .locator('nav[aria-label="Lista de chats"]')
                .getByText('E2E Ana')
                .first()
                .click();
            await beto
                .getByText('Hola Beto, ¿me apoyas con un cliente?')
                .waitFor();
            const measured = await measure(
                'team chat read→A "Visto"',
                ana,
                ana.getByText('· Visto'),
                since,
                'read',
            );
            const since2 = Date.now();
            await ana.getByPlaceholder('Escribe un mensaje interno…').click();
            await ana.keyboard.type('Gracias');
            await ana.keyboard.press('Enter');
            const open = await measure(
                'team chat→B open thread',
                beto,
                beto.getByText('Gracias', { exact: true }),
                since2,
                'message.created',
            );

            return [
                measured.live && open.live,
                `${measured.note}; ${open.note}`,
            ];
        });

        // 7. Access revocation: removing B from the department removes access
        // live; B's open conversation shows "no disponible".
        await beto.goto(`${BASE}/conversations?c=${conversations.one}`);
        await liveIndicator(beto).waitFor();
        await beto.getByRole('log').first().waitFor();

        await step('rt-access-revoked', async () => {
            await admin.goto(`${BASE}/departments/${departments.sales}`);
            await admin
                .getByRole('button', { name: 'Retirar a E2E Beto' })
                .click();
            const authBefore = beto.auth$.length;
            const since = Date.now();
            await admin
                .getByRole('dialog')
                .getByRole('button', { name: 'Retirar' })
                .click();
            const measured = await measure(
                'membership removed→B unavailable',
                beto,
                unavailableSelection(beto),
                since,
                'access.changed',
            );
            await beto.waitForTimeout(1_500);
            const reauth = beto.auth$
                .slice(authBefore)
                .map(
                    (entry) =>
                        `${entry.channel.replace('private-', '')}=${entry.status}`,
                );
            const stillQueue = reauth.some((entry) =>
                entry.startsWith(`inbox.queues.${departments.sales}=200`),
            );

            return [
                measured.live && !stillQueue && reauth.length > 0,
                `${measured.note}; channels re-authorized: ${reauth.join(', ')}`,
            ];
        });

        await step('rt-access-revoked-server', async () => {
            const response = await beto.request.get(
                `${BASE}/conversations/${conversations.one}/timeline`,
                {
                    headers: { Accept: 'application/json' },
                },
            );

            return [
                response.status() === 403 || response.status() === 404,
                `timeline after removal: HTTP ${response.status()}`,
            ];
        });

        // 8. WebSocket outage: the UI shows the degraded state and falls back
        // to polling; after Reverb is back it reconnects and catches up once.
        await admin.goto(`${BASE}/conversations`);
        await liveIndicator(admin).waitFor();
        await ana.goto(`${BASE}/conversations`);
        await liveIndicator(ana).waitFor();
        await openQueueView(ana);

        await step('rt-outage-degraded', async () => {
            const since = Date.now();
            const stopped = await stopReverb();
            await Promise.all([
                downIndicator(admin).waitFor({ timeout: 30_000 }),
                downIndicator(ana).waitFor({ timeout: 30_000 }),
            ]);
            const label = await downIndicator(admin).getAttribute('aria-label');
            await shot(admin, 'outage');

            return [
                stopped,
                `Reverb stopped; degraded indicator after ${Date.now() - since} ms: "${label}"`,
            ];
        });

        await step('rt-outage-polling', async () => {
            const since = Date.now();
            const receipt = await inbound(
                '5215500000303',
                'Cliente RT Tres',
                'Escribo durante la caída (simulación)',
            );
            conversations.three = receipt.conversation;
            const measured = await Promise.all([
                measure(
                    'outage inbound→admin (polling)',
                    admin,
                    row(admin, 'Cliente RT Tres'),
                    since,
                    'inbox.changed',
                    {
                        timeout: FALLBACK_MS + 10_000,
                        budget: FALLBACK_MS + 10_000,
                    },
                ),
                measure(
                    'outage inbound→ana queue (polling)',
                    ana,
                    row(ana, 'Cliente RT Tres'),
                    since,
                    'inbox.changed',
                    {
                        timeout: FALLBACK_MS + 10_000,
                        budget: FALLBACK_MS + 10_000,
                    },
                ),
            ]);
            const noFrames = measured.every((m) => m.frameMs === null);

            return [
                receipt.status === 'pending' && noFrames,
                `inbound stored while Reverb is down; ${measured.map((m) => m.note).join('; ')} (no WS frames: ${noFrames})`,
            ];
        });

        await step('rt-reconnect-catch-up', async () => {
            await row(admin, 'Cliente RT Tres').click();
            await admin.waitForURL(new RegExp(`c=${conversations.three}`));
            await bubble(admin, 'Escribo durante la caída').waitFor();
            await inbound(
                '5215500000303',
                'Cliente RT Tres',
                'Segundo mensaje antes de reconectar (simulación)',
            );
            const since = Date.now();
            const started = await startReverb();
            await Promise.all([
                liveIndicator(admin).waitFor({ timeout: 60_000 }),
                liveIndicator(ana).waitFor({ timeout: 60_000 }),
            ]);
            const reconnectMs = Date.now() - since;
            await bubble(admin, 'Segundo mensaje antes de reconectar').waitFor({
                timeout: 10_000,
            });
            const caughtUpMs = Date.now() - since;
            await admin.waitForTimeout(3_000);
            const copies = await bubble(
                admin,
                'Segundo mensaje antes de reconectar',
            ).count();
            const firstCopies = await bubble(
                admin,
                'Escribo durante la caída',
            ).count();
            const rows = await row(admin, 'Cliente RT Tres').count();

            return [
                started && copies === 1 && firstCopies === 1 && rows === 1,
                `Reverb restarted; reconnected after ${reconnectMs} ms, missed message shown after ${caughtUpMs} ms; copies: second=${copies}, first=${firstCopies}, list rows=${rows}`,
            ];
        });

        await step('rt-live-after-reconnect', async () => {
            const since = Date.now();
            await inbound(
                '5215500000303',
                'Cliente RT Tres',
                'Ya volvió el tiempo real (simulación)',
            );
            const measured = await measure(
                'after reconnect inbound→admin open chat',
                admin,
                bubble(admin, 'Ya volvió el tiempo real'),
                since,
                'message.recorded',
            );
            await admin.waitForTimeout(1_500);
            const copies = await bubble(
                admin,
                'Ya volvió el tiempo real',
            ).count();

            return [
                measured.live && copies === 1,
                `${measured.note}; copies ${copies}`,
            ];
        });
    } finally {
        await browser.close();
        stopChildren();
    }
};

await main().catch((error) => {
    record('scenario', false, String(error).split('\n')[0]);
});

console.log(
    '\nLatencies (ms from action to UI; WS = signal frame on the socket):',
);
for (const entry of latencies) {
    console.log(
        `  ${entry.label} [${entry.session}]: UI ${entry.elapsed}, WS ${entry.frameMs ?? '—'} (${entry.event})`,
    );
}

console.log(
    `\nconsole errors: ${problems.console.length}, page errors: ${problems.pageerror.length}, 5xx: ${problems.server.length}`,
);
[...problems.console, ...problems.pageerror, ...problems.server]
    .slice(0, 20)
    .forEach((line) => console.log(`  ${String(line)}`));

const failed = results.filter((result) => !result.ok).length;
console.log(`\n${results.length - failed} passed, ${failed} failed`);
stopChildren();
process.exit(failed === 0 ? 0 : 1);
