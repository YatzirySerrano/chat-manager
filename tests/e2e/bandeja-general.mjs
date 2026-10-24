/*
 * The general inbox of phase 4 with WhatsApp and web chat together
 * (tests/e2e/bandeja-general-seed.php: "E2E WhatsApp Ventas" and
 * "E2E Chat web Tienda", both routed to E2E Ventas). Two agents (Ana and
 * Beto) with Reverb and a real queue worker:
 *
 *   1. the inbox opens on "Bandeja general" and mixes both channels (only
 *      what waits for Ana: pending ones of her department, hers, never
 *      Beto's);
 *   2. every chip's number is exactly what the chip lists;
 *   3. the "Canal" filter appears (two channels) and, with "Conexión",
 *      narrows the rows;
 *   4. accepting from the list keeps the row in the general inbox, now
 *      "En atención";
 *   5. a new web chat visitor reaches both agents live and both accept it
 *      at the same instant: one wins, the other is refused, one Accepted
 *      event in the database;
 *   5b. two pending arrivals a few seconds apart are announced twice by
 *      the polite status region, even with the same text;
 *   6. the toast "X" (aria-label "Cerrar notificación") at 375 and 1280 px:
 *      visible, at least 24 px, the toast goes away, the bell keeps its
 *      unread count (closing is not reading) and no extra sound plays.
 *
 *   E2E_REVERB=1 E2E_PORT=8080 E2E_REVERB_PORT=8141 WHATSAPP_TRANSPORT=simulated \
 *     TEST_DB_LOCK_WAIT=3600 php tests/e2e/run.php bandeja-general.mjs
 *
 * SIMULACIÓN: Meta is never called; visitor messages use the real visitor
 * pipeline of the widget API.
 */
import { join } from 'node:path';
import {
    BASE,
    HERE,
    LABELS,
    LIVE_BUDGET_MS,
    USERS,
    alertDelta,
    exitWithSummary,
    launchBrowser,
    listRow,
    login,
    openRealtimeSession,
    php,
    phpAsync,
    printLatencies,
    printProblems,
    recordPageProblems,
    requireRealtimeEnvironment,
    shot,
    startWorker,
    step as runStep,
    stopChildren,
    toastText,
    waitFor,
} from './lib.mjs';

const SEED = join(HERE, 'bandeja-general-seed.php');
const sessions = [];

/** A failed step leaves a screenshot of every session. */
const step = (id, input, scenario) =>
    runStep(id, input, scenario, () =>
        Promise.all(
            sessions.map((page) =>
                shot(page, `bandeja-general-${id}-${page.session}`, {
                    fullPage: false,
                }),
            ),
        ),
    );

async function newSession(browser, name, options = {}) {
    const page = await openRealtimeSession(browser, name, {
        trackToasts: true,
        trackAlerts: true,
        ...options,
    });
    sessions.push(page);

    return page;
}

const settled = (page) => page.waitForLoadState('networkidle').catch(() => {});

/** Names of the listed rows (first part of each row's accessible name). */
const rowLabels = (page) =>
    page
        .locator('[data-inbox-item]')
        .evaluateAll((rows) =>
            rows.map((row) => row.getAttribute('aria-label') ?? ''),
        );
const rowNames = async (page) =>
    (await rowLabels(page)).map(
        (label) => label.replace(/^Pendiente de aceptar: /, '').split(',')[0],
    );

/** The number shown on a chip (null when it has none). */
async function chipNumber(page, key) {
    const badge = page.locator(
        `[data-inbox-chip="${key}"] span[aria-hidden="true"]`,
    );

    return (await badge.count()) === 0
        ? null
        : Number((await badge.first().innerText()).trim());
}

const acceptButton = (page, name) =>
    page.getByRole('button', {
        name: `Aceptar la conversación de ${name}`,
        exact: true,
    });

async function bellUnread(page) {
    const label = await page
        .getByRole('button', { name: /^Notificaciones/ })
        .first()
        .getAttribute('aria-label');
    const match = /(\d+) sin leer/.exec(label ?? '');

    return match ? Number(match[1]) : 0;
}

/** Unread notifications of the session (server view). */
async function serverUnread(page) {
    const response = await page.request.get(`${BASE}/notifications`, {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });
    const body = await response.json();

    return body.data.filter((item) => item.read_at === null).length;
}

/** A click on a neutral spot: the page may play sound after a gesture. */
async function activate(page) {
    await page.evaluate(() => window.scrollTo(0, 0));
    const box = await page.locator('h1').first().boundingBox();
    await page.mouse.click(box.x + 2, box.y + box.height / 2);
}

const run = async () => {
    requireRealtimeEnvironment();
    startWorker();

    const data = JSON.parse(php(SEED, 'prepare'));
    const drained = await waitFor(
        async () => Number((await phpAsync(SEED, 'pending-jobs')).trim()) === 0,
        60_000,
        500,
    );

    if (!drained) {
        console.error('The queue worker did not process the seed jobs.');
    }

    const browser = await launchBrowser();

    try {
        const ana = await newSession(browser, 'ana');
        const beto = await newSession(browser, 'beto');

        await Promise.all([
            login(ana, USERS.ana, { settle: false }),
            login(beto, USERS.beto, { settle: false }),
        ]);
        await Promise.all(
            [ana, beto].map((page) => page.goto(`${BASE}/conversations`)),
        );
        await Promise.all([settled(ana), settled(beto)]);

        // 1. Default view and both channels together.
        await step('default-general-view', 'render', async () => {
            const pressed = await ana
                .locator(
                    'nav[aria-label="Bandejas"] button[aria-pressed="true"]',
                )
                .allInnerTexts();
            const url = new URL(ana.url()).search;
            const labels = await rowLabels(ana);
            const names = await rowNames(ana);
            const web = labels.filter((label) => /Chat web/.test(label));
            const whatsapp = labels.filter((label) => /WhatsApp/.test(label));
            const expected = [
                'Cliente WhatsApp Pendiente',
                'Visitante Pendiente',
                'Visitante Por Aceptar',
                'Cliente WhatsApp Ana',
                'Visitante En Espera',
            ];

            return [
                drained &&
                    pressed.length === 1 &&
                    pressed[0].startsWith(LABELS.generalInbox) &&
                    !/view=/.test(url) &&
                    expected.every((name) => names.includes(name)) &&
                    !names.includes('Cliente WhatsApp Beto') &&
                    web.length >= 3 &&
                    whatsapp.length >= 2,
                `pressed=${JSON.stringify(pressed.map((text) => text.split('\n')[0]))} url="${url}" rows=${JSON.stringify(names)} web=${web.length} whatsapp=${whatsapp.length}`,
            ];
        });

        // 2. Each chip's number is what the chip lists.
        await step('counters-equal-rows', 'mouse', async () => {
            const notes = [];
            let ok = true;

            for (const key of [
                'base',
                'mine',
                'pending',
                'attending',
                'on_hold',
            ]) {
                await ana.locator(`[data-inbox-chip="${key}"]`).click();
                await ana.waitForFunction(
                    (chip) =>
                        document
                            .querySelector(`[data-inbox-chip="${chip}"]`)
                            ?.getAttribute('aria-pressed') === 'true',
                    key,
                );
                await settled(ana);
                const shown = await chipNumber(ana, key);
                // The list of the chip arrives after aria-pressed changes.
                await waitFor(
                    async () => (await rowNames(ana)).length === shown,
                    5_000,
                );
                const rows = (await rowNames(ana)).length;
                notes.push(`${key}=${shown}/${rows}`);
                ok = ok && shown === rows && rows > 0;
            }

            await ana.locator('[data-inbox-chip="base"]').click();
            await settled(ana);

            return [ok, `chip/rows: ${notes.join(', ')}`];
        });

        // 3. Canal (two channels) and Conexión.
        await step('channel-and-connection-filters', 'mouse', async () => {
            const notes = [];
            await ana.getByRole('button', { name: /^Filtros/ }).click();
            const channelOptions = await ana
                .locator('#inbox-channel option')
                .allInnerTexts();
            await ana.locator('#inbox-channel').selectOption('web_chat');
            await ana.waitForURL(/channel=web_chat/);
            await settled(ana);
            const webOnly = await rowLabels(ana);
            notes.push(`channel options=${JSON.stringify(channelOptions)}`);
            notes.push(`web_chat rows=${webOnly.length}`);

            await ana.locator('#inbox-channel').selectOption({ index: 0 });
            await ana.waitForURL((url) => !/channel=/.test(url.search));
            await ana
                .locator('#inbox-connection')
                .selectOption(String(data.connections.whatsapp.id));
            await ana.waitForURL(
                new RegExp(`connection=${data.connections.whatsapp.id}`),
            );
            await settled(ana);
            const whatsappOnly = await rowLabels(ana);
            notes.push(`WhatsApp connection rows=${whatsappOnly.length}`);
            await ana
                .getByRole('button', { name: 'Limpiar filtros' })
                .first()
                .click();
            await settled(ana);
            await ana.keyboard.press('Escape');

            return [
                channelOptions.some((text) => /Chat web/.test(text)) &&
                    channelOptions.some((text) => /WhatsApp/.test(text)) &&
                    webOnly.length >= 3 &&
                    webOnly.every((label) => /Chat web/.test(label)) &&
                    whatsappOnly.length >= 2 &&
                    whatsappOnly.every(
                        (label) =>
                            /WhatsApp/.test(label) && !/Chat web/.test(label),
                    ),
                notes.join('; '),
            ];
        });

        // 4. Accepting from the list keeps the row (now "En atención").
        await step('accept-keeps-row', 'mouse', async () => {
            await acceptButton(ana, 'Visitante Pendiente').click();
            const toast = await toastText(ana, /Aceptaste la conversación/);
            await settled(ana);
            const row = listRow(ana, 'Visitante Pendiente');
            const kept = (await row.count()) === 1;
            const text = kept
                ? (await row.innerText()).replace(/\s+/g, ' ')
                : '';
            const general = await ana
                .locator('[data-inbox-chip="base"]')
                .getAttribute('aria-pressed');

            return [
                /Aceptaste/.test(toast) &&
                    kept &&
                    /En atención/.test(text) &&
                    general === 'true',
                `toast "${toast}"; row kept=${kept} "${text.slice(0, 120)}"; general still selected=${general}`,
            ];
        });

        // 5. A new visitor; Ana and Beto accept it at the same instant.
        await step('web-accept-race', 'two sessions, mouse', async () => {
            const since = Date.now();
            const visitor = JSON.parse(
                await phpAsync(
                    SEED,
                    'visitor',
                    'Visitante Carrera',
                    'Hola, ¿quién me atiende?',
                ),
            );
            await Promise.all(
                [ana, beto].map((page) =>
                    acceptButton(page, 'Visitante Carrera').waitFor({
                        timeout: LIVE_BUDGET_MS,
                    }),
                ),
            );
            const live = Date.now() - since;
            await Promise.all(
                [ana, beto].map((page) =>
                    page
                        .locator('[data-sonner-toast]')
                        .first()
                        .waitFor({ state: 'detached', timeout: 15_000 })
                        .catch(() => {}),
                ),
            );
            await Promise.all(
                [ana, beto].map((page) =>
                    acceptButton(page, 'Visitante Carrera').evaluate((button) =>
                        button.click(),
                    ),
                ),
            );
            const [anaToast, betoToast] = await Promise.all([
                toastText(ana, /Aceptaste|Otra persona/),
                toastText(beto, /Aceptaste|Otra persona/),
            ]);
            const won = (text) => /Aceptaste la conversación/.test(text);
            const lost = (text) =>
                /Otra persona ya aceptó esta conversación/.test(text);
            const winner = won(anaToast) ? ana : won(betoToast) ? beto : null;
            const loser = winner === ana ? beto : ana;
            const loserGone = await listRow(loser, 'Visitante Carrera')
                .waitFor({ state: 'detached', timeout: LIVE_BUDGET_MS })
                .then(() => true)
                .catch(() => false);
            const check = JSON.parse(
                await phpAsync(SEED, 'check', String(visitor.conversation)),
            );
            const winnerId =
                winner === null ? null : data.agents[winner.session];

            return [
                visitor.status === 'pending' &&
                    winner !== null &&
                    lost(winner === ana ? betoToast : anaToast) &&
                    loserGone &&
                    check.status === 'open' &&
                    check.assigned_user_id === winnerId &&
                    check.accepted_events === 1,
                `row live in ${live} ms; ana "${anaToast}"; beto "${betoToast}"; loser row gone=${loserGone}; db=${JSON.stringify(check)}`,
            ];
        });

        // 5b. Same announcement text twice: the status region must change
        // both times (a screen reader announces mutations, not values).
        await step('pending-announced-twice', 'two arrivals', async () => {
            await ana.evaluate(() => {
                const region = [
                    ...document.querySelectorAll('p[role="status"]'),
                ].find((element) =>
                    element.closest(
                        'section[aria-label="Lista de conversaciones"]',
                    ),
                );
                window.__announced = [];

                if (region) {
                    new MutationObserver(() => {
                        const text = region.textContent.trim();

                        if (text !== '') {
                            window.__announced.push(text);
                        }
                    }).observe(region, {
                        childList: true,
                        characterData: true,
                        subtree: true,
                    });
                }

                window.__announcerFound = region !== undefined;
            });
            const announced = () => ana.evaluate(() => window.__announced);

            for (const [index, name] of [
                'Visitante Aviso Uno',
                'Visitante Aviso Dos',
            ].entries()) {
                await phpAsync(SEED, 'visitor', name, 'Hola, ¿siguen ahí?');
                await waitFor(
                    async () => (await announced()).length > index,
                    LIVE_BUDGET_MS + 5_000,
                );
            }

            const found = await ana.evaluate(() => window.__announcerFound);
            const texts = await announced();

            return [
                found &&
                    texts.length >= 2 &&
                    texts.every((text) => /pendientes? nuevas?/.test(text)),
                `status region found=${found}; announcements=${JSON.stringify(texts)}`,
            ];
        });

        // 6. Closing a notification toast with its X, desktop and phone.
        const storageState = await ana.context().storageState();

        for (const width of [1280, 375]) {
            await step(
                `toast-close-${width}`,
                `${width}px mouse/touch`,
                async () => {
                    const phone = width <= 430;
                    const page = phone
                        ? await newSession(browser, `ana${width}`, {
                              context: {
                                  viewport: { width, height: 812 },
                                  hasTouch: true,
                                  isMobile: true,
                                  storageState,
                              },
                          })
                        : ana;
                    await page.goto(`${BASE}/dashboard`);
                    await settled(page);
                    await activate(page);
                    await page
                        .locator('[data-sonner-toast]')
                        .first()
                        .waitFor({ state: 'detached', timeout: 15_000 })
                        .catch(() => {});

                    await phpAsync(
                        SEED,
                        'whatsapp',
                        '5215511140002',
                        'Cliente WhatsApp Ana',
                        `¿Ya está mi factura? (${width})`,
                    );
                    const toast = page
                        .locator('[data-sonner-toast]', {
                            hasText: /Cliente WhatsApp Ana/,
                        })
                        .first();
                    await toast.waitFor({ timeout: LIVE_BUDGET_MS });
                    // Let the chime of the arrival finish before measuring.
                    await page.waitForTimeout(1_500);
                    const bellBefore = await bellUnread(page);
                    const serverBefore = await serverUnread(page);
                    const close = toast.getByRole('button', {
                        name: LABELS.closeToast,
                    });
                    const box = await close.boundingBox();
                    const visible = await close.isVisible();
                    const delta = await alertDelta(
                        page,
                        async () => {
                            if (phone) {
                                await close.tap();
                            } else {
                                await close.click();
                            }

                            return toast
                                .waitFor({ state: 'detached', timeout: 5_000 })
                                .then(() => true)
                                .catch(() => false);
                        },
                        { settleMs: 1_500 },
                    );
                    const bellAfter = await bellUnread(page);
                    const serverAfter = await serverUnread(page);
                    const viewport = page.viewportSize();
                    const inside =
                        box !== null &&
                        box.x >= 0 &&
                        box.y >= 0 &&
                        box.x + box.width <= viewport.width &&
                        box.y + box.height <= viewport.height;

                    if (phone) {
                        await page.context().close();
                        sessions.splice(sessions.indexOf(page), 1);
                    }

                    return [
                        visible &&
                            inside &&
                            box.width >= 24 &&
                            box.height >= 24 &&
                            delta.result === true &&
                            bellBefore > 0 &&
                            bellAfter === bellBefore &&
                            serverAfter === serverBefore &&
                            delta.chimes === 0 &&
                            delta.media === 0,
                        `X ${box ? `${Math.round(box.width)}x${Math.round(box.height)} at ${Math.round(box.x)},${Math.round(box.y)}` : 'not found'} visible=${visible}; toast gone=${delta.result}; bell ${bellBefore}→${bellAfter}; server unread ${serverBefore}→${serverAfter}; chimes while closing=${delta.chimes}`,
                    ];
                },
            );
        }
    } finally {
        stopChildren();
        await browser.close();
    }

    recordPageProblems(800);
    printLatencies();
    printProblems();
    exitWithSummary();
};

run().catch((error) => {
    console.error(error);
    stopChildren();
    process.exit(1);
});
