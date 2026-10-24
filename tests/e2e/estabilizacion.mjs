/*
 * Multi-session stabilization scenario (SPEC 22): the whole life of one
 * customer conversation across departments, watched at the same time by the
 * superadministrator, the administrator, two Ventas agents (Ana, Beto) and
 * the Soporte agent (Carla), each in its own Chromium context.
 *
 *   1. the customer writes (signed webhook) to the business number;
 *   2. it reaches the Ventas queue live, never Soporte;
 *   3. Ana accepts; 4. it leaves Beto's shared queue live;
 *   5. Ana replies (simulated transport); 6. Ana adds an internal note;
 *   7. Ana transfers it to Soporte choosing Carla in the recipients dialog;
 *   8. Carla receives it live; 9. Ana loses access (UI and 403/404);
 *  10. a new customer message reaches Carla only; 11. Carla closes it;
 *  12. a new customer message reopens it (connection reopening window);
 *  then: the administrator pauses the connection and the composer is
 *  blocked live (internal notes still allowed), the message-content search,
 *  and 13. the administrator sees the history and the audit log. 14. Every live step
 *  must see the WebSocket signal and the UI change well before the polling
 *  fallback (20 s).
 *
 *   E2E_REVERB=1 E2E_PORT=8050 E2E_REVERB_PORT=8111 \
 *     WHATSAPP_TRANSPORT=simulated TEST_DB_LOCK_WAIT=3600 \
 *     php tests/e2e/run.php estabilizacion.mjs
 *
 * The runner rebuilds chatmanager_testing and starts the web server and
 * Reverb with throwaway credentials; this scenario starts a real queue worker
 * (webhooks, outbound delivery, notifications) and stops it before exiting.
 *
 * SIMULACIÓN: customer messages are webhook bodies in the documented
 * WhatsApp Cloud API format (tests/e2e/estabilizacion-seed.php message),
 * signed with the throwaway app secret of the testing database and posted to
 * the local server; replies use WHATSAPP_TRANSPORT=simulated. Meta is never
 * contacted.
 */
import { join } from 'node:path';
import {
    BASE,
    FALLBACK_MS,
    HERE,
    LIVE_BUDGET_MS,
    USERS,
    chromium,
    latencies,
    listRow,
    liveIndicator,
    login,
    measure,
    openInboxView,
    openRealtimeSession,
    operations,
    php,
    phpAsync,
    printLatencies,
    printProblems,
    record,
    recordPageProblems,
    requireRealtimeEnvironment,
    results,
    runOperation,
    shot,
    signedWebhook,
    startWorker,
    step as runStep,
    stopChildren,
    toastText,
    waitFor,
} from './lib.mjs';

const SEED = join(HERE, 'estabilizacion-seed.php');
const CUSTOMER = 'Cliente Estabilización';
const CUSTOMER_WA_ID = '5215511118888';
/** Unique word of the first message, used by the message-content search. */
const SEARCH_WORD = 'refrigerador';

const sessions = [];

/** A failed step leaves a screenshot of every session. */
const step = (id, input, scenario) =>
    runStep(id, input, scenario, () =>
        Promise.all(
            sessions.map((page) =>
                shot(page, `estabilizacion-${id}-${page.session}`, {
                    fullPage: false,
                }),
            ),
        ),
    );

async function newSession(browser, name) {
    const page = await openRealtimeSession(browser, name, {
        trackToasts: true,
    });
    sessions.push(page);

    return page;
}

const bubble = (page, text) =>
    page.locator('[data-message-id]', { hasText: text });
const chatRegion = (page) =>
    page.getByRole('region', { name: `Conversación con ${CUSTOMER}` });
const chatHeader = (page) => chatRegion(page).locator('header').first();
const history = (page) =>
    page.getByRole('log', { name: /Historial de la conversación/ });

/** Waits until the open chat's private channel is subscribed. */
async function subscribedTo(page, conversationId, since) {
    return waitFor(
        () =>
            page.frames$.some(
                (frame) =>
                    frame.t >= since &&
                    frame.event === 'pusher_internal:subscription_succeeded' &&
                    frame.channel === `private-conversations.${conversationId}`,
            ),
        10_000,
    );
}

async function openChat(page, conversationId) {
    const since = Date.now();
    await page.goto(`${BASE}/conversations?c=${conversationId}`);
    await liveIndicator(page).waitFor();
    await subscribedTo(page, conversationId, since);
}

const run = async () => {
    requireRealtimeEnvironment();
    startWorker();

    const data = JSON.parse(php(SEED, 'prepare'));

    /** Posts a customer message signed like Meta does (X-Hub-Signature-256). */
    async function customerWrites(text) {
        const body = (
            await phpAsync(SEED, 'message', CUSTOMER_WA_ID, CUSTOMER, text)
        ).trim();

        return signedWebhook(data.webhook_key, data.app_secret, body);
    }

    const browser = await chromium.launch();
    let conversationId = null;

    try {
        const superadmin = await newSession(browser, 'superadmin');
        const administrator = await newSession(browser, 'administrator');
        const ana = await newSession(browser, 'ana');
        const beto = await newSession(browser, 'beto');
        const carla = await newSession(browser, 'carla');
        const everyone = [superadmin, administrator, ana, beto, carla];

        await Promise.all([
            login(superadmin, USERS.superadmin, { settle: false }),
            login(administrator, USERS.administrator, { settle: false }),
            login(ana, USERS.ana, { settle: false }),
            login(beto, USERS.beto, { settle: false }),
            login(carla, USERS.carla, { settle: false }),
        ]);
        await Promise.all(
            everyone.map((page) => page.goto(`${BASE}/conversations`)),
        );

        await step('connected', 'websocket', async () => {
            const states = await Promise.all(
                everyone.map(async (page) => {
                    await liveIndicator(page).waitFor({ timeout: 15_000 });

                    return `${page.session}:${page.frames$.some((f) => f.event === 'pusher:connection_established')}`;
                }),
            );

            return [
                states.every((state) => state.endsWith('true')),
                `"Tiempo real activo" + pusher:connection_established on ${states.join(', ')}`,
            ];
        });

        await Promise.all(
            [ana, beto, carla].map((page) => openInboxView(page, 'Pendientes')),
        );

        // 1-2. The customer writes; the Ventas queue (Ana, Beto) and the
        // inboxes of both administrators show it live; Soporte never does.
        await step(
            'inbound-to-sales-queue',
            'signed webhook (simulated)',
            async () => {
                const since = Date.now();
                const status = await customerWrites(
                    `Hola, quiero cotizar un ${SEARCH_WORD} industrial`,
                );
                const measured = await Promise.all(
                    [ana, beto, superadmin, administrator].map((page) =>
                        measure(
                            'inbound→inbox',
                            page,
                            listRow(page, CUSTOMER),
                            since,
                            'inbox.changed',
                        ),
                    ),
                );
                await listRow(ana, CUSTOMER).click();
                await ana.waitForURL(/[?&]c=\d+/);
                conversationId = Number(
                    new URL(ana.url()).searchParams.get('c'),
                );

                return [
                    status === 200 && measured.every((m) => m.live),
                    `webhook ${status}; conversation ${conversationId}; ${measured.map((m) => m.note).join('; ')}`,
                ];
            },
        );

        await step('not-in-support-queue', 'render + url', async () => {
            await carla.waitForTimeout(2_000);
            const rows = await listRow(carla, CUSTOMER).count();
            const direct = await carla.request.get(
                `${BASE}/conversations/${conversationId}`,
                { maxRedirects: 0 },
            );

            return [
                rows === 0 && direct.status() === 404,
                `Carla (Soporte) rows=${rows}, GET /conversations/${conversationId} → ${direct.status()}`,
            ];
        });

        // 3-4. Ana accepts: the row leaves Beto's shared queue live.

        await step('accept-leaves-shared-queue', 'mouse', async () => {
            const accept = operations(ana).getByRole('button', {
                name: 'Aceptar',
                exact: true,
            });
            await accept.waitFor();
            const since = Date.now();
            await accept.click();
            const gone = await measure(
                'accept→Beto queue row gone',
                beto,
                listRow(beto, CUSTOMER),
                since,
                'inbox.changed',
                { state: 'detached' },
            );
            const toast = await toastText(ana, /Aceptaste/);

            return [
                gone.live && /Aceptaste la conversación/.test(toast),
                `${gone.note}; Ana: "${toast}"`,
            ];
        });

        // Both administrators follow the conversation from its open chat.
        await Promise.all([
            openChat(superadmin, conversationId),
            openChat(administrator, conversationId),
        ]);

        // 5. Ana replies through the simulated transport.
        await step('reply-simulated', 'keyboard', async () => {
            const text = 'Con gusto te cotizo el equipo.';
            await ana
                .locator('[role="tab"][aria-selected="true"]', {
                    hasText: 'Responder al cliente',
                })
                .waitFor();
            await ana.locator('#chat-composer').click();
            await ana.keyboard.type(text);
            const since = Date.now();
            await ana.keyboard.press('Enter');
            const live = await measure(
                'reply→superadmin open chat',
                superadmin,
                bubble(superadmin, text),
                since,
                'message.recorded',
            );
            const delivered = await waitFor(
                async () =>
                    /Entregado|Leído|Enviado/.test(
                        (
                            await bubble(ana, text)
                                .locator('.sr-only')
                                .allInnerTexts()
                        ).join('|'),
                    ),
                20_000,
            );
            const simulated = await bubble(ana, text)
                .getByText('Simulación')
                .count();

            return [
                live.live && simulated > 0 && delivered,
                `${live.note}; simulated badge=${simulated > 0}; delivery status reached=${delivered}`,
            ];
        });

        // 6. Internal note: not a message, seen live by the administrator.
        await step('internal-note', 'keyboard', async () => {
            const text = 'Nota estabilización: cliente mayorista';
            await ana.getByRole('tab', { name: 'Nota interna' }).click();
            await ana.locator('#chat-composer').click();
            await ana.keyboard.type(text);
            const since = Date.now();
            await ana.getByRole('button', { name: 'Guardar nota' }).click();
            const live = await measure(
                'note→administrator open chat',
                administrator,
                administrator.locator('[aria-label^="Nota interna de"]', {
                    hasText: text,
                }),
                since,
                'note.saved',
            );
            const asMessage = await bubble(ana, text).count();
            await ana
                .getByRole('tab', { name: 'Responder al cliente' })
                .click();

            return [
                live.live && asMessage === 0,
                `${live.note}; shown as a message=${asMessage}`,
            ];
        });

        await openInboxView(carla, 'Mis conversaciones');

        // 7-9. Ana transfers it to Soporte choosing Carla in the recipients
        // dialog; Carla receives it live and Ana loses it.
        await step(
            'transfer-to-support-agent',
            'mouse + keyboard',
            async () => {
                await runOperation(ana, 'Transferir');
                const dialog = ana.getByRole('dialog');
                await dialog.locator('#transfer-department').waitFor();
                await dialog
                    .locator('#transfer-department')
                    .selectOption({ label: 'E2E Soporte' });
                const option = dialog.locator('#route-agent option', {
                    hasText: 'E2E Carla · Disponible',
                });
                await option.waitFor({ state: 'attached' });
                const offered = (
                    await dialog.locator('#route-agent option').allInnerTexts()
                ).map((text) => text.trim());
                await dialog
                    .locator('#route-agent')
                    .selectOption(await option.getAttribute('value'));
                await dialog
                    .locator('#transfer-reason')
                    .fill('Requiere soporte técnico');
                const since = Date.now();
                await dialog
                    .getByRole('button', { name: 'Transferir', exact: true })
                    .click();
                // Carla is told over the WebSocket; Ana (the actor) leaves
                // the conversation through her own request: the row goes and
                // the chat panel closes or says it is no longer available.
                const [received] = await Promise.all([
                    measure(
                        'transfer→Carla mine',
                        carla,
                        listRow(carla, CUSTOMER),
                        since,
                        'inbox.changed',
                    ),
                    listRow(ana, CUSTOMER).waitFor({ state: 'detached' }),
                    ana
                        .getByText(
                            /Elige una conversación|La conversación no está disponible/,
                        )
                        .first()
                        .waitFor(),
                ]);
                const actorMs = Date.now() - since;
                const toast = await toastText(ana, /transferida/);

                return [
                    received.live && /transferida a E2E Carla/.test(toast),
                    `options: ${offered.join(' | ')}; ${received.note}; Ana's row and chat gone after ${actorMs} ms; Ana: "${toast}"`,
                ];
            },
        );

        await step('previous-agent-loses-access', 'url', async () => {
            const timeline = await ana.request.get(
                `${BASE}/conversations/${conversationId}/timeline`,
                { headers: { Accept: 'application/json' } },
            );
            const page = await ana.request.get(
                `${BASE}/conversations/${conversationId}`,
                { maxRedirects: 0 },
            );

            return [
                [403, 404].includes(timeline.status()) &&
                    [403, 404].includes(page.status()),
                `Ana: timeline ${timeline.status()}, conversation ${page.status()}`,
            ];
        });

        await listRow(carla, CUSTOMER).click();
        await carla.waitForURL(new RegExp(`c=${conversationId}`));
        await subscribedTo(carla, conversationId, 0);
        await history(carla).waitFor();

        // 10. A new customer message reaches Carla's open chat, not Ana.
        await step('new-message-after-transfer', 'signed webhook', async () => {
            const text = 'El equipo no enciende desde ayer';
            const since = Date.now();
            const status = await customerWrites(text);
            const live = await measure(
                'inbound→Carla open chat',
                carla,
                bubble(carla, text),
                since,
                'message.recorded',
            );
            await ana.waitForTimeout(1_500);
            const anaRows = await listRow(ana, CUSTOMER).count();
            const anaBubbles = await bubble(ana, text).count();

            return [
                status === 200 && live.live && anaRows + anaBubbles === 0,
                `webhook ${status}; ${live.note}; Ana rows=${anaRows} bubbles=${anaBubbles}`,
            ];
        });

        // 11. Carla closes it; the administrator's open chat follows live.
        await step('close', 'mouse + keyboard', async () => {
            await runOperation(carla, 'Cerrar conversación');
            await carla
                .locator('textarea#conversation-reason')
                .fill('Falla resuelta por soporte');
            const since = Date.now();
            await carla
                .getByRole('dialog')
                .getByRole('button', { name: 'Cerrar conversación' })
                .click();
            await carla.getByRole('dialog').waitFor({ state: 'detached' });
            const live = await measure(
                'close→administrator header',
                administrator,
                chatHeader(administrator).getByText('Cerrada', {
                    exact: true,
                }),
                since,
                'conversation.changed',
            );
            await chatHeader(carla)
                .getByText('Cerrada', { exact: true })
                .waitFor();

            return [live.live, live.note];
        });

        // 12. The customer writes again inside the reopening window: the
        // conversation reopens with Carla, live for her and the administrator.
        await step('reopen-by-new-message', 'signed webhook', async () => {
            // The reopening compares whole seconds with closed_at.
            await carla.waitForTimeout(1_200);
            const text = 'Volvió a fallar, ¿me ayudan?';
            const since = Date.now();
            const status = await customerWrites(text);
            const [carlaLive, adminLive] = await Promise.all([
                measure(
                    'reopen→Carla open chat',
                    carla,
                    bubble(carla, text),
                    since,
                    'message.recorded',
                ),
                measure(
                    'reopen→administrator header',
                    administrator,
                    chatHeader(administrator).getByText('En atención', {
                        exact: true,
                    }),
                    since,
                    'conversation.changed',
                ),
            ]);
            // The bubble (message.recorded) and the header (the
            // conversation reloaded after conversation.changed) are two
            // live updates: the header gets the same live budget.
            await waitFor(
                async () =>
                    /En atención/.test(await chatHeader(carla).innerText()),
                LIVE_BUDGET_MS,
            );
            const carlaHeader = (await chatHeader(carla).innerText()).replace(
                /\s+/g,
                ' ',
            );

            return [
                status === 200 &&
                    carlaLive.live &&
                    adminLive.live &&
                    /En atención/.test(carlaHeader),
                `webhook ${status}; ${carlaLive.note}; ${adminLive.note}; Carla header "${carlaHeader.slice(0, 80)}"`,
            ];
        });

        // Paused connection: the administrator deactivates it; Carla's
        // composer blocks replies live, internal notes still work;
        // reactivating unblocks it.
        await step('paused-connection-blocks-composer', 'mouse', async () => {
            await administrator.goto(`${BASE}/connections/${data.connection}`);
            await administrator
                .getByRole('button', { name: 'Desactivar conexión' })
                .click();
            const since = Date.now();
            await administrator
                .getByRole('dialog')
                .getByRole('button', { name: 'Desactivar', exact: true })
                .click();
            const blocked = await measure(
                'connection paused→Carla composer blocked',
                carla,
                carla.locator('[role="status"]', {
                    hasText:
                        /está desactivada.*Puedes seguir agregando notas internas/,
                }),
                since,
                'conversation.changed',
            );
            const replyBox = await carla.locator('#chat-composer').count();
            const note = 'Nota con la conexión pausada';
            await carla.getByRole('tab', { name: 'Nota interna' }).click();
            await carla.locator('#chat-composer').click();
            await carla.keyboard.type(note);
            await carla.getByRole('button', { name: 'Guardar nota' }).click();
            await carla
                .locator('[aria-label^="Nota interna de"]', { hasText: note })
                .waitFor();
            await carla
                .getByRole('tab', { name: 'Responder al cliente' })
                .click();

            const sinceActive = Date.now();
            await administrator
                .getByRole('button', { name: 'Activar conexión' })
                .click();
            const unblocked = await measure(
                'connection active→Carla composer back',
                carla,
                carla.locator('#chat-composer'),
                sinceActive,
                'conversation.changed',
            );

            return [
                blocked.live && replyBox === 0 && unblocked.live,
                `${blocked.note}; reply box while paused=${replyBox}; note saved; ${unblocked.note}`,
            ];
        });

        // Search by message content: the superadministrator finds it by a
        // word of the first message; Ana (no access any more) does not.
        await step('search-message-content', 'keyboard + mouse', async () => {
            // Phase 4: the inbox opens on "Bandeja general" (pending ones and
            // the person's own); the superadministrator searches every
            // authorized conversation in "Todas" (view=all). Ana has no
            // "Todas" view: she searches her default inbox.
            const search = async (page, query = '') => {
                await page.goto(`${BASE}/conversations${query}`);
                await page.waitForLoadState('networkidle');
                await page.getByRole('button', { name: /^Filtros/ }).click();
                await page.locator('#inbox-search-in').selectOption('messages');
                await page.keyboard.press('Escape');
                await page
                    .getByRole('searchbox', { name: 'Buscar conversaciones' })
                    .fill(SEARCH_WORD);
                await page.waitForURL(/search_in=messages/);
                await page.waitForLoadState('networkidle');

                return listRow(page, CUSTOMER).count();
            };
            const found = await search(superadmin, '?view=all');
            const byContact = await superadmin.request.get(
                `${BASE}/conversations/page?search=${SEARCH_WORD}`,
                { headers: { Accept: 'application/json' } },
            );
            const contactHits = byContact.ok()
                ? ((await byContact.json()).data ?? []).length
                : `HTTP ${byContact.status()}`;
            const anaFound = await search(ana);

            return [
                found === 1 && contactHits === 0 && anaFound === 0,
                `superadmin message search rows=${found}; contact search hits=${contactHits}; Ana message search rows=${anaFound}`,
            ];
        });

        // 13. The administrator sees every step in the history and their
        // connection pause in the audit log (entries made by the
        // superadministrator are reserved to the root authority).
        await step('administrator-history-and-audit', 'render', async () => {
            await administrator.goto(
                `${BASE}/conversations?c=${conversationId}`,
            );
            await history(administrator).waitFor();
            await administrator.waitForLoadState('networkidle');
            const text = await history(administrator).innerText();
            const expected = [
                'Aceptada',
                'Transferida',
                'Requiere soporte técnico',
                'Cerrada',
                'Reabierta',
                'Nota estabilización: cliente mayorista',
            ];
            const missing = expected.filter((label) => !text.includes(label));
            await administrator.goto(`${BASE}/admin/audit`);
            await administrator.waitForLoadState('networkidle');
            const audit = await administrator.locator('main').innerText();
            const auditMissing = [
                'Conexión desactivada',
                'Conexión activada',
            ].filter((label) => !audit.includes(label));

            return [
                missing.length === 0 && auditMissing.length === 0,
                `history missing: ${JSON.stringify(missing)}; audit missing: ${JSON.stringify(auditMissing)}`,
            ];
        });

        // 14. Every live update saw its WebSocket signal before the UI change
        // and well before the polling fallback.
        record(
            'live-before-polling',
            'websocket',
            latencies.length > 0 &&
                latencies.every(
                    (entry) =>
                        entry.frameMs !== null &&
                        entry.frameMs <= entry.elapsed &&
                        entry.elapsed <= LIVE_BUDGET_MS &&
                        entry.elapsed < FALLBACK_MS,
                ),
            `${latencies.length} live updates, slowest UI ${Math.max(...latencies.map((entry) => entry.elapsed))} ms (budget ${LIVE_BUDGET_MS} ms, polling ${FALLBACK_MS} ms)`,
        );
    } finally {
        await browser.close();
        stopChildren();
    }
};

await run().catch((error) => {
    record('scenario', null, false, String(error).split('\n')[0]);
});

recordPageProblems(600);
printLatencies();
printProblems();

const failed = results.filter((result) => !result.ok).length;
console.log(`\n${results.length - failed} passed, ${failed} failed`);
stopChildren();
process.exit(failed === 0 ? 0 : 1);
