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
import { join } from 'node:path';
import {
    BASE,
    FALLBACK_MS,
    HERE,
    LIVE_BUDGET_MS,
    USERS,
    chromium,
    downIndicator,
    frameSince,
    inboundAsync,
    liveIndicator,
    login as signIn,
    measure,
    openInboxView,
    openRealtimeSession,
    php,
    printLatencies,
    printProblems,
    record,
    results,
    requireRealtimeEnvironment,
    shot as saveShot,
    startReverb,
    startWorker,
    step as runStep,
    stopChildren,
    stopReverb,
    unavailableSelection,
    waitFor,
} from './lib.mjs';

const REALTIME_SEED = join(HERE, 'realtime-seed.php');

const sessions = [];

/** A failed step leaves a screenshot of every session. */
const step = (id, scenario) =>
    runStep(id, null, scenario, () =>
        Promise.all(
            sessions.map((page) => shot(page, `${id}-${page.session}`)),
        ),
    );

const shot = (page, name) =>
    saveShot(page, `realtime-${name}`, { fullPage: false });

const login = (page, email) => signIn(page, email, { settle: false });

/* ------------------------------------------------------------------ */
/* Browser helpers.                                                    */
/* ------------------------------------------------------------------ */

async function newSession(browser, name) {
    const page = await openRealtimeSession(browser, name);
    sessions.push(page);

    return page;
}

const row = (page, name) =>
    page.locator('[data-inbox-item]', { hasText: name });
const bubble = (page, text) =>
    page.locator('[data-message-id]', { hasText: text });

async function inbound(waId, name, text) {
    return (await inboundAsync(waId, name, text))[0];
}

const openQueueView = (page) => openInboxView(page, 'Pendientes');
const openMineView = (page) => openInboxView(page, 'Mis conversaciones');

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
    requireRealtimeEnvironment();
    startWorker();

    const prepared = JSON.parse(php(REALTIME_SEED, 'prepare'));
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

        // 0b. A channel authorization that fails once with a server error
        // (e.g. a deadlock on the rate limiter) is tried again by the client
        // and the channel ends subscribed, without reloading the page.
        await step('rt-auth-retry-after-500', async () => {
            const retry = await openRealtimeSession(browser, 'retry', {
                ignoreConsole:
                    /status of (403|404|409|422|429|500)|WebSocket connection to|ERR_CONNECTION_REFUSED/,
            });
            const userChannel = /^private-users\.\d+$/;
            let failed = 0;

            try {
                await retry.route('**/broadcasting/auth', async (route) => {
                    let channel = '';

                    try {
                        channel = JSON.parse(
                            route.request().postData() ?? '{}',
                        ).channel_name;
                    } catch {
                        channel = '';
                    }

                    if (failed === 0 && userChannel.test(channel)) {
                        failed++;
                        await route.fulfill({
                            status: 500,
                            contentType: 'application/json',
                            body: '{"message":"Server Error"}',
                        });

                        return;
                    }

                    await route.continue();
                });
                // The page reached after signing in connects on its own; a
                // single socket proves the retry happened in that document.
                await login(retry, USERS.beto);
                await liveIndicator(retry).waitFor({ timeout: 15_000 });
                const subscribed = await waitFor(
                    () =>
                        retry.frames$.some(
                            (frame) =>
                                frame.event ===
                                    'pusher_internal:subscription_succeeded' &&
                                userChannel.test(frame.channel ?? ''),
                        ),
                    15_000,
                );
                const attempts = retry.auth$
                    .filter((answer) => userChannel.test(answer.channel ?? ''))
                    .map((answer) => answer.status);
                const sockets = retry.frames$.filter(
                    (frame) => frame.event === '__open',
                ).length;

                return [
                    failed === 1 &&
                        subscribed &&
                        attempts[0] === 500 &&
                        attempts.includes(200) &&
                        sockets === 1,
                    `user channel auth answers=${JSON.stringify(attempts)}; subscribed=${subscribed}; sockets opened=${sockets}`,
                ];
            } finally {
                await retry.context().close();
            }
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

        // 2. Manual availability was removed (Fase 5): no switch in the
        // list, and the assign dialog lists every active agent of the
        // department by name (only "Al límite de carga" qualifies one).
        await row(admin, 'Cliente RT Uno').click();
        await admin.waitForURL(/[?&]c=\d+/);

        await step('rt-no-availability', async () => {
            const switches = await Promise.all(
                [ana, beto].map((page) =>
                    page.getByRole('switch', { name: /Disponible/ }).count(),
                ),
            );
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
            const label = await option.innerText();
            await admin.keyboard.press('Escape');

            return [
                switches.every((count) => count === 0) &&
                    !/Disponible/.test(label),
                `switches=${switches.join('/')}; option "${label}"`,
            ];
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
                .getByRole('button', {
                    name: 'Responder al cliente',
                    exact: true,
                })
                .getAttribute('aria-pressed');

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
                    // Exact: the "Pendientes" chip now says "… por aceptar".
                    (await loser
                        .getByRole('button', { name: 'Aceptar', exact: true })
                        .count()) === 0 &&
                    (await loser
                        .getByRole('button', {
                            name: 'Aceptar la conversación de Cliente RT Dos',
                        })
                        .count()) === 0,
                LIVE_BUDGET_MS,
            );

            const left = settled
                ? ''
                : ` (rows=${await row(loser, 'Cliente RT Dos').count()}, accept buttons=${JSON.stringify(await loser.getByRole('button', { name: /^Aceptar/ }).allInnerTexts())}, url=${new URL(loser.url()).search})`;

            return [
                [a, b].sort().join(',') === 'refused,won' && settled,
                `ana=${a}, beto=${b}; refused session (${loser.session}) left without the conversation: ${settled}${left}`,
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
                .locator('button[aria-pressed="true"]', {
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
            const simulated = await target
                .getByText('No enviado (modo de prueba)')
                .count();
            const text = done
                ? ''
                : ` bubble: "${(await target.innerText()).replace(/\s+/g, ' ')}"`;

            return [
                done && frame !== undefined,
                `labels seen on admin: ${seen.join(' → ')}; message.status frame ${frame ? 'seen' : 'missing'}; simulated badge ${simulated > 0}; ${Date.now() - since} ms${text}`,
            ];
        });

        await step('rt-note-to-admin', async () => {
            await ana
                .getByRole('button', { name: 'Nota interna', exact: true })
                .click();
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
                .getByRole('button', {
                    name: 'Responder al cliente',
                    exact: true,
                })
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
            // B's requests to the team chat in flight / last activity, so
            // the step only starts once B is quiet (see below).
            let betoInFlight = 0;
            let betoLastActivity = Date.now();
            const isTeamChat = (request) =>
                new URL(request.url()).pathname.startsWith('/team-chat');
            const started = (request) => {
                if (isTeamChat(request)) {
                    betoInFlight++;
                    betoLastActivity = Date.now();
                }
            };
            const ended = (request) => {
                if (isTeamChat(request)) {
                    betoInFlight = Math.max(0, betoInFlight - 1);
                    betoLastActivity = Date.now();
                }
            };
            beto.on('request', started);
            beto.on('requestfinished', ended);
            beto.on('requestfailed', ended);
            const creationSince = Date.now();
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
            // Root cause of an earlier intermittent miss: the reload B
            // makes for the creation signal (queued signal, 400 ms debounce
            // and the request itself) could still be running when Ana's
            // message was stored, so it brought the unread badge before
            // the message's own team-chat.changed frame arrived. Wait for
            // the creation signal and for B to be quiet (no team chat
            // request in flight, no new signal) before writing.
            const quiet = await waitFor(
                () => {
                    const lastFrame = beto.frames$
                        .filter(
                            (frame) =>
                                frame.t >= creationSince &&
                                frame.event === 'team-chat.changed',
                        )
                        .at(-1);

                    return (
                        lastFrame !== undefined &&
                        betoInFlight === 0 &&
                        Date.now() - Math.max(betoLastActivity, lastFrame.t) >=
                            1_500
                    );
                },
                15_000,
                100,
            );
            beto.off('request', started);
            beto.off('requestfinished', ended);
            beto.off('requestfailed', ended);
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
                quiet && badgeBefore === 0 && badge.live && preview > 0,
                `thread listed for B on creation (B quiet before writing: ${quiet}); ${badge.note}; preview in B's list: ${preview > 0}`,
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
            // Business rule: B's open chat becomes unavailable live and B's
            // session re-authorizes its channels. The removal releases B's
            // conversations (conversation.changed) and revokes B's access
            // (access.changed) in the same request: either signal may reach
            // the browser first and both lead to the same screen, so the
            // UI change must come from the WebSocket (one of the two frames
            // seen before it, within the live budget) and access.changed
            // must still arrive within the budget, not in a fixed order.
            await unavailableSelection(beto)
                .first()
                .waitFor({ timeout: LIVE_BUDGET_MS + 4_000 });
            const elapsed = Date.now() - since;
            const firstSignal = ['access.changed', 'conversation.changed']
                .map((event) => frameSince(beto, event, since))
                .filter((frame) => frame !== undefined)
                .sort((a, b) => a.t - b.t)[0];
            const accessArrived = await waitFor(
                () => frameSince(beto, 'access.changed', since) !== undefined,
                Math.max(0, LIVE_BUDGET_MS - (Date.now() - since)),
                50,
            );
            const accessFrame = frameSince(beto, 'access.changed', since);
            // Nothing of the conversation stays rendered for B (the chat
            // and the list row go; Inertia's initial page payload of the
            // earlier full load, a <script>, is not rendered content).
            const renderedProtected = () =>
                beto.evaluate((text) => {
                    const found = [];
                    const walker = document.createTreeWalker(
                        document.body,
                        NodeFilter.SHOW_TEXT,
                    );

                    while (walker.nextNode()) {
                        const node = walker.currentNode;
                        const element = node.parentElement;

                        if (
                            !node.textContent.includes(text) ||
                            !element ||
                            element.closest('script, template')
                        ) {
                            continue;
                        }

                        const rect = element.getBoundingClientRect();

                        if (rect.width > 0 && rect.height > 0) {
                            found.push(
                                element
                                    .closest('[aria-label]')
                                    ?.getAttribute('aria-label') ??
                                    element.tagName,
                            );
                        }
                    }

                    return found;
                }, 'Hola, soy Ana. Te ayudo enseguida.');
            let rendered = [];
            const protectedGone = await waitFor(
                async () => {
                    rendered = await renderedProtected();

                    return rendered.length === 0;
                },
                Math.max(500, LIVE_BUDGET_MS - (Date.now() - since)),
                100,
            );
            const goneMs = Date.now() - since;
            const measured = {
                live:
                    elapsed <= LIVE_BUDGET_MS &&
                    firstSignal !== undefined &&
                    firstSignal.t - since <= elapsed &&
                    accessArrived &&
                    protectedGone,
                note: `beto: UI ${elapsed} ms after ${firstSignal ? `${firstSignal.event} (${firstSignal.t - since} ms)` : 'no signal frame'}, WS access.changed ${accessFrame ? `${accessFrame.t - since} ms` : 'not seen'}; conversation content no longer rendered: ${protectedGone ? `${goneMs} ms` : JSON.stringify(rendered)}`,
            };
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
    record('scenario', null, false, String(error).split('\n')[0]);
});

printLatencies();
printProblems();

const failed = results.filter((result) => !result.ok).length;
console.log(`\n${results.length - failed} passed, ${failed} failed`);
stopChildren();
process.exit(failed === 0 ? 0 : 1);
