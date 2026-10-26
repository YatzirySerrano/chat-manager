/*
 * Phase 5.1 multi-user supervision scenario: real simultaneous sessions in
 * separate Chromium contexts, the non-root Administrador (global), the
 * Ventas supervisor (departments scope), agents Ana and Beto of E2E Ventas
 * and Carla of E2E Soporte.
 *
 *   inbound-everyone-default  a customer writes (signed webhook): Ana's
 *                             general inbox, the Administrador's and the
 *                             supervisor's DEFAULT view (no filter touched)
 *                             show it live; the default chip is named after
 *                             the reach («Todas las conversaciones», «Conversaciones
 *                             de mis departamentos»)
 *   accept-visible-to-admin   Ana accepts; the Administrador keeps the row
 *                             in the default view and the open chat follows
 *   reply-live-history        Ana replies and the customer writes again:
 *                             both reach the Administrador's open chat live
 *   transfer-still-visible    Ana transfers to the Soporte queue: the
 *                             Administrador still has it (row + chat), the
 *                             supervisor keeps it read-only, Ana loses it
 *   close-closed-filter       Carla accepts and closes it: the
 *                             Administrador's chat shows «Cerrada» live and
 *                             the conversation is still consultable, with
 *                             its history, under the «Cerradas» filter
 *   supervisor-reach          the supervisor never lists nor opens a
 *                             conversation that only lived in Soporte (404)
 *   agent-out-of-scope        Ana gets 404 by URL for it and for the one she
 *                             transferred
 *   revoke-transfer-live      the Administrador removes «Transferir
 *                             conversaciones» from Beto's actual role
 *                             (Agente) in the role editor while Beto has the
 *                             conversation open in two tabs: the action
 *                             disappears from both without a reload
 *                             (access.changed), and a direct POST is 403
 *   grant-transfer-live       granting it back brings the action back in
 *                             both tabs
 *
 *   E2E_REVERB=1 E2E_PORT=8100 E2E_REVERB_PORT=8161 WHATSAPP_TRANSPORT=simulated \
 *     QUEUE_CONNECTION=sync TEST_DB_LOCK_WAIT=3600 php tests/e2e/run.php supervision.mjs
 *
 * Logout without reloading (normal, service worker, back button) is covered
 * by logout.mjs and fase5.mjs (pwa-logout-clears-caches).
 *
 * Seed: tests/e2e/supervision-seed.php. SIMULACIÓN: customer messages are
 * webhook bodies in the documented WhatsApp Cloud API format, signed with the
 * throwaway app secret of the testing database; replies use the simulated
 * transport. Meta is never contacted.
 */
import { join } from 'node:path';
import {
    BASE,
    HERE,
    LIVE_BUDGET_MS,
    USERS,
    chooseInboxStatus,
    frameSince,
    launchBrowser,
    listRow,
    liveIndicator,
    login,
    measure,
    openRealtimeSession,
    operations,
    php,
    phpAsync,
    printLatencies,
    printProblems,
    record,
    recordPageProblems,
    exitWithSummary,
    requireRealtimeEnvironment,
    runOperation,
    shot,
    signedWebhook,
    step as runStep,
    trackWebSocket,
    unavailableSelection,
    waitFor,
    watchProblems,
} from './lib.mjs';

requireRealtimeEnvironment();

if (process.env.QUEUE_CONNECTION !== 'sync') {
    console.error('Run with QUEUE_CONNECTION=sync (see the header).');
    process.exit(2);
}

const SEED = join(HERE, 'supervision-seed.php');
const CUSTOMER = 'Cliente Supervisión E2E';
const CUSTOMER_WA_ID = '5215530000001';
const data = JSON.parse(php(SEED, 'prepare'));
const sessions = [];

/** A failed step leaves a screenshot of every session. */
const step = (id, input, scenario) =>
    runStep(id, input, scenario, () =>
        Promise.all(
            sessions.map((page) =>
                shot(page, `supervision-${id}-${page.session}`, {
                    fullPage: false,
                }),
            ),
        ),
    );

async function customerWrites(text) {
    const body = (
        await phpAsync(SEED, 'message', CUSTOMER_WA_ID, CUSTOMER, text)
    ).trim();

    return signedWebhook(data.webhook_key, data.app_secret, body);
}

const bubble = (page, text) =>
    page.locator('[data-message-id]', { hasText: text });
const chatRegion = (page, name = CUSTOMER) =>
    page.getByRole('region', { name: `Conversación con ${name}` });
const history = (page) =>
    page.getByRole('log', { name: /Historial de la conversación/ });

/** Text of the pressed chip of the list (the view the list is on). */
const pressedChip = async (page) =>
    (
        await page
            .locator('nav[aria-label="Bandejas"] button[aria-pressed="true"]')
            .allInnerTexts()
    )
        .map((text) => text.replace(/\s+/g, ' ').trim())
        .join(' | ');

async function newSession(browser, name, email) {
    const page = await openRealtimeSession(browser, name, {
        trackToasts: true,
    });
    sessions.push(page);
    await login(page, email, { settle: false });

    return page;
}

/** A second tab of the same session (same cookies), frames logged too. */
async function secondTab(page, name) {
    const tab = await page.context().newPage();
    tab.session = name;
    tab.setDefaultTimeout(15_000);
    trackWebSocket(tab);
    watchProblems(
        tab,
        /status of (403|404|409|422|429)|WebSocket connection to|ERR_CONNECTION_REFUSED/,
        `[${name}] `,
    );
    sessions.push(tab);

    return tab;
}

async function openChat(page, id) {
    await page.goto(`${BASE}/conversations?c=${id}`);
    await liveIndicator(page).waitFor();
    await history(page).waitFor();
    await page.waitForLoadState('networkidle');
}

/** Whether the open chat offers «Transferir» (direct button or menu). */
async function offersTransfer(page) {
    const direct = await operations(page)
        .getByRole('button', { name: 'Transferir', exact: true })
        .count();

    if (direct > 0) {
        return true;
    }

    const more = page.getByRole('button', {
        name: 'Más acciones de la conversación',
    });

    if ((await more.count()) === 0) {
        return false;
    }

    await more.first().click();
    const menu = page.getByRole('menu');
    await menu.waitFor({ timeout: 5_000 });
    const offered =
        (await menu
            .getByRole('menuitem', { name: 'Transferir', exact: true })
            .count()) > 0;
    await page.keyboard.press('Escape');
    await menu.waitFor({ state: 'detached', timeout: 5_000 }).catch(() => {});

    return offered;
}

/** The Administrador toggles «Transferir conversaciones» of a role. */
async function setRoleTransfer(page, roleLabel, granted) {
    await page.goto(`${BASE}/admin/roles`);
    await page.waitForLoadState('networkidle');
    const cards = page.getByRole('radio', { name: 'Vista de tarjetas' });

    if ((await cards.count()) > 0) {
        await cards.click();
    }

    await page
        .getByRole('button', {
            name: `Editar el rol ${roleLabel}`,
            exact: true,
        })
        .first()
        .click();
    const dialog = page.getByRole('dialog');
    await dialog.waitFor();
    await dialog
        .getByRole('searchbox', { name: 'Buscar permiso' })
        .fill('Transferir');
    const checkbox = dialog.getByRole('checkbox', {
        name: /^Transferir conversaciones/,
    });
    await checkbox.waitFor();
    const checked = (await checkbox.getAttribute('aria-checked')) === 'true';

    if (checked !== granted) {
        await checkbox.click();
    }

    const text = (await dialog.innerText()).replace(/\s+/g, ' ');
    const since = Date.now();
    await dialog.getByRole('button', { name: 'Guardar rol' }).click();
    await dialog.waitFor({ state: 'detached', timeout: 15_000 });

    return { since, changed: checked !== granted, text };
}

/** Waits until both tabs (do not) offer «Transferir», without a reload. */
async function transferFollows(tabs, expected, since) {
    const results = await Promise.all(
        tabs.map(async (tab) => {
            const ok = await waitFor(
                async () => (await offersTransfer(tab)) === expected,
                LIVE_BUDGET_MS + 4_000,
                400,
            );
            const elapsed = Date.now() - since;
            const frame = frameSince(tab, 'access.changed', since);
            const sameDocument = await tab.evaluate(
                () => window.__supervisionMarker === true,
            );

            return {
                ok: ok && frame !== undefined && sameDocument,
                note: `${tab.session}: ${ok ? `${elapsed} ms` : 'not updated'}, access.changed ${frame ? `${frame.t - since} ms` : 'not seen'}, no reload ${sameDocument}`,
            };
        }),
    );

    return [results.every((result) => result.ok), results.map((r) => r.note)];
}

async function directTransfer(page, conversationId) {
    const version = Number(
        (await phpAsync(SEED, 'version', String(conversationId))).trim(),
    );
    const xsrf = (await page.context().cookies()).find(
        (cookie) => cookie.name === 'XSRF-TOKEN',
    );
    const response = await page.request.post(
        `${BASE}/conversations/${conversationId}/transfer`,
        {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': decodeURIComponent(xsrf?.value ?? ''),
            },
            data: {
                version,
                department_id: data.departments.support,
                reason: 'Intento directo sin permiso (E2E)',
            },
        },
    );

    return response.status();
}

const run = async () => {
    const browser = await launchBrowser();
    let conversationId = null;

    try {
        const administrator = await newSession(
            browser,
            'administrator',
            data.users.administrator,
        );
        const supervisor = await newSession(
            browser,
            'supervisor',
            data.users.supervisor,
        );
        const ana = await newSession(browser, 'ana', USERS.ana);
        const beto = await newSession(browser, 'beto', USERS.beto);
        const carla = await newSession(browser, 'carla', USERS.carla);

        await Promise.all(
            [administrator, supervisor, ana, carla].map(async (page) => {
                await page.goto(`${BASE}/conversations`);
                await liveIndicator(page).waitFor({ timeout: 15_000 });
                await page.waitForLoadState('networkidle');
            }),
        );

        await runStep('seed', 'supervision-seed.php', async () => [
            data.transport === 'simulated' &&
                data.roles.beto.join() === 'Agente' &&
                data.roles.beto_direct_permissions.length === 0 &&
                data.roles.administrator.join() === 'Administrador',
            `roles ${JSON.stringify(data.roles)}; conversations ${JSON.stringify(data.conversations)}`,
        ]);

        // 1. The customer writes: every reach sees it in its default view.
        await step(
            'inbound-everyone-default',
            'signed webhook, default views',
            async () => {
                const before = {
                    administrator: administrator.url(),
                    supervisor: supervisor.url(),
                };
                const since = Date.now();
                const status = await customerWrites(
                    'Hola, quiero información de sus planes',
                );
                const measured = await Promise.all(
                    [ana, administrator, supervisor].map((page) =>
                        measure(
                            'inbound→default view',
                            page,
                            listRow(page, CUSTOMER),
                            since,
                            'inbox.changed',
                        ),
                    ),
                );
                const carlaRows = await listRow(carla, CUSTOMER).count();
                const chips = {
                    administrator: await pressedChip(administrator),
                    supervisor: await pressedChip(supervisor),
                };
                const untouched =
                    administrator.url() === before.administrator &&
                    supervisor.url() === before.supervisor &&
                    !new URL(administrator.url()).searchParams.has('view') &&
                    !new URL(supervisor.url()).searchParams.has('view');

                return [
                    status === 200 &&
                        measured.every((m) => m.live) &&
                        carlaRows === 0 &&
                        untouched &&
                        chips.administrator.startsWith(
                            'Todas las conversaciones',
                        ) &&
                        chips.supervisor.startsWith(
                            'Conversaciones de mis departamentos',
                        ),
                    `webhook ${status}; ${measured.map((m) => m.note).join('; ')}; Carla rows=${carlaRows}; default chips ${JSON.stringify(chips)}; URLs untouched=${untouched}`,
                ];
            },
        );

        await listRow(ana, CUSTOMER).click();
        await ana.waitForURL(/[?&]c=\d+/);
        conversationId = Number(new URL(ana.url()).searchParams.get('c'));
        await history(ana).waitFor();

        await openChat(administrator, conversationId);

        // 2. Ana accepts: still in the Administrador's default view.
        await step('accept-visible-to-admin', 'mouse', async () => {
            const accept = operations(ana).getByRole('button', {
                name: 'Aceptar',
                exact: true,
            });
            await accept.waitFor();
            const since = Date.now();
            await accept.click();
            const header = await measure(
                'accept→administrator chat header',
                administrator,
                chatRegion(administrator)
                    .locator('header')
                    .first()
                    .getByText('E2E Ana'),
                since,
                'conversation.changed',
            );
            await administrator.waitForTimeout(1_000);
            const row = await listRow(administrator, CUSTOMER).count();

            return [
                header.live && row === 1,
                `${header.note}; row in the default view=${row}`,
            ];
        });

        // 3. Ana replies, the customer writes again: live history.
        await step('reply-live-history', 'keyboard + webhook', async () => {
            const reply = 'Claro, te comparto los planes en un momento.';
            await ana.locator('#chat-composer').click();
            await ana.keyboard.type(reply);
            let since = Date.now();
            await ana.keyboard.press('Enter');
            const replied = await measure(
                'reply→administrator open chat',
                administrator,
                bubble(administrator, reply),
                since,
                'message.recorded',
            );
            const inboundText = '¿Incluye soporte los fines de semana?';
            since = Date.now();
            const status = await customerWrites(inboundText);
            const [admin, own] = await Promise.all([
                measure(
                    'inbound→administrator open chat',
                    administrator,
                    bubble(administrator, inboundText),
                    since,
                    'message.recorded',
                ),
                measure(
                    'inbound→Ana open chat',
                    ana,
                    bubble(ana, inboundText),
                    since,
                    'message.recorded',
                ),
            ]);
            const composer = await administrator
                .locator('#chat-composer')
                .count();

            return [
                replied.live && admin.live && own.live && status === 200,
                `${replied.note}; webhook ${status}; ${admin.note}; ${own.note}; administrator composer=${composer}`,
            ];
        });

        // 4. Transfer to the Soporte queue.
        await step('transfer-still-visible', 'mouse + keyboard', async () => {
            await runOperation(ana, 'Transferir');
            const dialog = ana.getByRole('dialog');
            await dialog.locator('#transfer-department').waitFor();
            await dialog
                .locator('#transfer-department')
                .selectOption({ label: 'E2E Soporte' });
            await dialog
                .locator('#transfer-reason')
                .fill('Lo atiende soporte (E2E)');
            const since = Date.now();
            await dialog
                .getByRole('button', { name: 'Transferir', exact: true })
                .click();
            const [carlaRow] = await Promise.all([
                measure(
                    'transfer→Carla list',
                    carla,
                    listRow(carla, CUSTOMER),
                    since,
                    'inbox.changed',
                ),
                listRow(ana, CUSTOMER).waitFor({ state: 'detached' }),
            ]);
            await administrator.waitForTimeout(1_500);
            const adminRow = await listRow(administrator, CUSTOMER).count();
            const adminChat = await chatRegion(administrator).count();
            const adminHistory = await bubble(
                administrator,
                'Claro, te comparto los planes',
            ).count();
            await supervisor.goto(`${BASE}/conversations?c=${conversationId}`);
            await history(supervisor).waitFor();
            const supervisorRow = await listRow(supervisor, CUSTOMER).count();
            const supervisorComposer = await supervisor
                .locator('#chat-composer')
                .count();
            const anaTimeline = await ana.request.get(
                `${BASE}/conversations/${conversationId}/timeline`,
                { headers: { Accept: 'application/json' } },
            );

            return [
                carlaRow.live &&
                    adminRow === 1 &&
                    adminChat === 1 &&
                    adminHistory > 0 &&
                    supervisorRow === 1 &&
                    supervisorComposer === 0 &&
                    [403, 404].includes(anaTimeline.status()),
                `${carlaRow.note}; administrator row=${adminRow} chat=${adminChat} history=${adminHistory}; supervisor row=${supervisorRow} composer=${supervisorComposer}; Ana timeline ${anaTimeline.status()}`,
            ];
        });

        // 5. Carla accepts and closes: consultable under «Cerradas».
        await step('close-closed-filter', 'mouse + filter', async () => {
            await listRow(carla, CUSTOMER).click();
            await carla.waitForURL(new RegExp(`c=${conversationId}`));
            await history(carla).waitFor();
            const accept = operations(carla).getByRole('button', {
                name: 'Aceptar',
                exact: true,
            });
            await accept.waitFor();
            await accept.click();
            await chatRegion(carla)
                .locator('header')
                .first()
                .getByText('E2E Carla')
                .waitFor();
            await runOperation(carla, 'Cerrar conversación');
            await carla
                .locator('textarea#conversation-reason')
                .fill('Resuelto por soporte (E2E)');
            const since = Date.now();
            await carla
                .getByRole('dialog')
                .getByRole('button', { name: 'Cerrar conversación' })
                .click();
            await carla.getByRole('dialog').waitFor({ state: 'detached' });
            const closed = await measure(
                'close→administrator header',
                administrator,
                chatRegion(administrator)
                    .locator('header')
                    .first()
                    .getByText('Cerrada', { exact: true }),
                since,
                'conversation.changed',
            );
            await administrator.goto(`${BASE}/conversations`);
            await administrator.waitForLoadState('networkidle');
            await chooseInboxStatus(administrator, 'Cerradas');
            const row = listRow(administrator, CUSTOMER);
            await row.waitFor({ timeout: 10_000 });
            await row.click();
            await history(administrator).waitFor();
            await administrator.waitForLoadState('networkidle');
            const text = await history(administrator).innerText();
            const expected = [
                'Hola, quiero información de sus planes',
                'Claro, te comparto los planes en un momento.',
                '¿Incluye soporte los fines de semana?',
                'Transferida',
                'Cerrada',
            ];
            const missing = expected.filter((label) => !text.includes(label));

            return [
                closed.live && missing.length === 0,
                `${closed.note}; «Cerradas» lists it; history missing ${JSON.stringify(missing)}`,
            ];
        });

        // 6-7. Reach: the supervisor and Ana never reach the Soporte one.
        await step('supervisor-reach', 'list + url', async () => {
            await supervisor.goto(`${BASE}/conversations`);
            await supervisor.waitForLoadState('networkidle');
            // Default view (active conversations of her departments): the
            // Soporte-only one is active too, so it would be listed here if
            // her reach leaked; Beto's Ventas conversation is.
            await listRow(supervisor, 'Cliente Permisos E2E').waitFor({
                timeout: 10_000,
            });
            const listed = await listRow(
                supervisor,
                'Cliente Solo Soporte SV',
            ).count();
            const ownReach = await listRow(
                supervisor,
                'Cliente Permisos E2E',
            ).count();
            const page = await supervisor.request.get(
                `${BASE}/conversations/${data.conversations.support_only}`,
                { maxRedirects: 0 },
            );
            const timeline = await supervisor.request.get(
                `${BASE}/conversations/${data.conversations.support_only}/timeline`,
                { headers: { Accept: 'application/json' } },
            );
            await supervisor.goto(
                `${BASE}/conversations?c=${data.conversations.support_only}`,
            );
            await supervisor.waitForLoadState('networkidle');
            const unavailable = await unavailableSelection(supervisor).count();

            return [
                listed === 0 &&
                    ownReach === 1 &&
                    page.status() === 404 &&
                    [403, 404].includes(timeline.status()) &&
                    unavailable > 0,
                `Soporte-only rows=${listed}, own reach rows=${ownReach}; GET ${page.status()}, timeline ${timeline.status()}, ?c= unavailable=${unavailable > 0}`,
            ];
        });

        await step('agent-out-of-scope', 'url', async () => {
            const statuses = [];

            for (const id of [
                data.conversations.support_only,
                conversationId,
            ]) {
                const page = await ana.request.get(
                    `${BASE}/conversations/${id}`,
                    { maxRedirects: 0 },
                );
                const timeline = await ana.request.get(
                    `${BASE}/conversations/${id}/timeline`,
                    { headers: { Accept: 'application/json' } },
                );
                statuses.push([page.status(), timeline.status()]);
            }

            return [
                statuses.every(
                    ([page, timeline]) =>
                        page === 404 && [403, 404].includes(timeline),
                ),
                `Ana GET/timeline: Soporte-only ${statuses[0].join('/')}, transferred ${statuses[1].join('/')}`,
            ];
        });

        // 8-9. Beto's actual role loses «Transferir conversaciones» while
        // his conversation is open in two tabs.
        const betoTabs = [beto, await secondTab(beto, 'beto-tab2')];
        await Promise.all(
            betoTabs.map(async (tab) => {
                await openChat(tab, data.conversations.beto);
                await tab.evaluate(() => {
                    window.__supervisionMarker = true;
                });
            }),
        );

        await step(
            'revoke-transfer-live',
            'role editor, two tabs',
            async () => {
                const offeredBefore = await Promise.all(
                    betoTabs.map((tab) => offersTransfer(tab)),
                );
                const saved = await setRoleTransfer(
                    administrator,
                    'Agente',
                    false,
                );
                const [followed, notes] = await transferFollows(
                    betoTabs,
                    false,
                    saved.since,
                );
                const post = await directTransfer(
                    beto,
                    data.conversations.beto,
                );
                const stillHis = await chatRegion(beto, 'Cliente Permisos E2E')
                    .locator('header')
                    .first()
                    .getByText('E2E Beto')
                    .count();

                return [
                    offeredBefore.every(Boolean) &&
                        saved.changed &&
                        followed &&
                        post === 403 &&
                        stillHis > 0,
                    `offered before ${JSON.stringify(offeredBefore)}; ${notes.join('; ')}; direct POST ${post}; editor impact text: ${(saved.text.match(/[^.]*(afecta|usuarios? (activos?|con este rol))[^.]*\./i)?.[0] ?? 'none').slice(0, 160)}`,
                ];
            },
        );

        await step('grant-transfer-live', 'role editor, two tabs', async () => {
            const saved = await setRoleTransfer(administrator, 'Agente', true);
            const [followed, notes] = await transferFollows(
                betoTabs,
                true,
                saved.since,
            );

            return [saved.changed && followed, notes.join('; ')];
        });
    } finally {
        await browser.close();
    }
};

await run().catch((error) => {
    record('scenario', null, false, String(error).split('\n')[0]);
});

recordPageProblems(800);
printLatencies();
printProblems();
exitWithSummary();
