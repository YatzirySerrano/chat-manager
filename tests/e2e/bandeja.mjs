/*
 * Inbox scenario: the conversation list with TWO WhatsApp connections, seen
 * by two Ventas agents (Ana, Beto) and the superadministrator, each in its
 * own Chromium context (own session cookies).
 *
 *   1. real time connected in the three sessions;
 *   2. the sound spy is calibrated with "Probar sonido";
 *   3. every row tells the channel and the connection (the same customer
 *      writes to both numbers: two rows);
 *   4. quick filters combined: No leídas + Conexión + estado, kept on
 *      reload and cleared;
 *   5. Ana and Beto press "Aceptar" on the same row of the list at the same
 *      time: one wins, the other gets a clear error and the row leaves its
 *      list without reloading; the administrator sees it live;
 *   6. unread counters live and cleared when the conversation is opened;
 *   7. a customer message on another page: one toast and one chime (two
 *      tones for an urgent new pending conversation), never duplicated;
 *   8. no toast nor sound for the conversation open and visible;
 *   9. no toast nor sound for the agent's own reply; a customer message
 *      delivered late lands above that reply and is still announced;
 *  10. Inertia navigation does not duplicate listeners;
 *  11. phone navigation list ↔ chat;
 *  12. responsive matrix (320…1440 px, light and dark) of Contactos (Index,
 *      Import, contact dialog) and Conversaciones (list, chat): no sideways
 *      scroll nor internal overflow, readable header description, fonts of
 *      the inbox/chat >= 12 px and touch targets >= 40 px on phones.
 *
 *   E2E_REVERB=1 E2E_PORT=8060 E2E_REVERB_PORT=8121 E2E_SERVER_WORKERS=8 \
 *     WHATSAPP_TRANSPORT=simulated TEST_DB_LOCK_WAIT=3600 \
 *     E2E_SCREENSHOTS=/path/to/shots php tests/e2e/run.php bandeja.mjs
 *
 * The runner rebuilds chatmanager_testing and starts the web server and
 * Reverb with throwaway credentials; this scenario starts a real queue worker
 * (webhooks, outbound delivery, notifications) and stops it before exiting.
 *
 * SIMULACIÓN: customer messages are webhook bodies in the documented
 * WhatsApp Cloud API format (tests/e2e/bandeja-seed.php message), signed
 * with the throwaway app secret of each connection of the testing database
 * and posted to the local server; replies use WHATSAPP_TRANSPORT=simulated.
 * Meta is never contacted.
 */
import { join } from 'node:path';
import {
    BASE,
    HERE,
    LIVE_BUDGET_MS,
    USERS,
    WIDTHS,
    alertCounts,
    alertDelta,
    launchBrowser,
    layoutProblems,
    layoutReport,
    listRow,
    liveIndicator,
    login,
    measure,
    overflow,
    openInboxView,
    applyInboxFilters,
    clearInboxFilters,
    chooseInboxStatus,
    openPage,
    openRealtimeSession,
    php,
    phpAsync,
    printLatencies,
    printProblems,
    recordPageProblems,
    requireRealtimeEnvironment,
    results,
    shot,
    signedWebhook,
    startWorker,
    step as runStep,
    stopChildren,
    toastText,
    toastsSince,
    waitFor,
} from './lib.mjs';

const SEED = join(HERE, 'bandeja-seed.php');
const sessions = [];

/** A failed step leaves a screenshot of every session. */
const step = (id, input, scenario) =>
    runStep(id, input, scenario, () =>
        Promise.all(
            sessions.map((page) =>
                shot(page, `bandeja-${id}-${page.session}`, {
                    fullPage: false,
                }),
            ),
        ),
    );

async function newSession(browser, name) {
    const page = await openRealtimeSession(browser, name, {
        trackToasts: true,
        trackAlerts: true,
    });
    sessions.push(page);

    return page;
}

const sidebarLink = (page, title) =>
    page
        .locator('[data-sidebar="sidebar"]')
        .getByRole('link', { name: title, exact: true });
const acceptButton = (page, name) =>
    page.getByRole('button', {
        name: `Aceptar la conversación de ${name}`,
        exact: true,
    });
const rowLabel = (page, name) =>
    listRow(page, name)
        .getAttribute('aria-label', { timeout: 5_000 })
        .catch(() => null);
const rowNames = (page) =>
    page
        .locator('[data-inbox-item]')
        .evaluateAll((rows) =>
            rows.map((row) => row.getAttribute('aria-label').split(',')[0]),
        );
const unreadChip = (page) => page.locator('[data-inbox-unread]');
const chipCount = async (page) =>
    Number(
        // The visible counter (the chip also carries an sr-only sentence).
        ((await unreadChip(page).innerText()).match(/(\d+)/) ?? [])[1] ?? NaN,
    );
const bubble = (page, text) =>
    page.locator('[data-message-id]', { hasText: text });

/**
 * A click on a neutral spot (the page title): browsers only let a page
 * play sound after the user interacted with it, and the application waits
 * for that activation (navigator.userActivation) before creating audio.
 */
async function activate(page) {
    await page.evaluate(() => window.scrollTo(0, 0));
    const box = await page.locator('h1').first().boundingBox();
    await page.mouse.click(box.x + 2, box.y + box.height / 2);
}

/**
 * Counts, on every DOM change, the toasts matching `pattern` that are on
 * screen (a toast playing its exit animation has data-removed="true" and no
 * longer counts): `now` and the most seen at once since the call.
 */
async function watchLiveToasts(page, pattern) {
    await page.evaluate(
        ({ source, flags }) => {
            const matches = new RegExp(source, flags);
            const state = { now: 0, maxLive: 0 };
            const count = () => {
                state.now = [
                    ...document.querySelectorAll(
                        '[data-sonner-toast]:not([data-removed="true"])',
                    ),
                ].filter((toast) => matches.test(toast.textContent)).length;
                state.maxLive = Math.max(state.maxLive, state.now);
            };
            window.__liveToasts = state;
            new MutationObserver(count).observe(document.body, {
                childList: true,
                subtree: true,
                attributes: true,
                attributeFilter: ['data-removed'],
                characterData: true,
            });
            count();
        },
        { source: pattern.source, flags: pattern.flags },
    );
}

function liveToasts(page) {
    return page.evaluate(() => ({ ...window.__liveToasts }));
}

/** Waits until a list update from the server has settled. */
async function settled(page) {
    await page.waitForLoadState('networkidle').catch(() => {});
}

/** Unread notifications of the session for a conversation (server view). */
async function unreadNotificationsOf(page, conversationId) {
    const response = await page.request.get(`${BASE}/notifications`, {
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });
    const body = await response.json();

    return body.data.filter(
        (item) =>
            item.conversation_id === conversationId && item.read_at === null,
    ).length;
}

const run = async () => {
    requireRealtimeEnvironment();
    startWorker();

    const data = JSON.parse(php(SEED, 'prepare'));
    // Phase 4: the responsive matrix also measures the stress conversation
    // of chat-movil-seed.php (a closed one: the inbox lists do not change).
    const stress = JSON.parse(
        php(join(HERE, 'chat-movil-seed.php'), 'prepare', 'closed'),
    );
    const conversation = (key) => data.conversations[key].id;
    const connectionName = {
        a: data.connections.a.name,
        b: data.connections.b.name,
    };

    /** Posts a customer message signed like Meta does (X-Hub-Signature-256). */
    async function customerWrites(
        connection,
        waId,
        name,
        text,
        secondsAgo = 0,
    ) {
        const body = (
            await phpAsync(
                SEED,
                'message',
                connection,
                waId,
                name,
                text,
                String(secondsAgo),
            )
        ).trim();

        return signedWebhook(
            data.connections[connection].webhook_key,
            data.connections[connection].app_secret,
            body,
        );
    }

    // The seed's notifications are delivered by the worker before anyone
    // opens a page: they are history, not news to alert about.
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
        const admin = await newSession(browser, 'admin');
        // Inertia visits of the administrator (explains a view that changed
        // under the scenario's feet).
        admin.visits$ = [];
        admin.on('framenavigated', (frame) => {
            if (frame === admin.mainFrame()) {
                admin.visits$.push(
                    `${Date.now()} NAV ${frame.url().replace(BASE, '')}`,
                );
            }
        });
        admin.on('response', async (response) => {
            if (response.request().headers()['x-inertia']) {
                const body = await response.json().catch(() => null);
                admin.visits$.push(
                    `${Date.now()} RES ${response.status()} ${body?.url ?? '?'} view=${body?.props?.filters?.view ?? '-'}`,
                );
            }
        });
        admin.on('request', (request) => {
            const headers = request.headers();

            if (headers['x-inertia']) {
                admin.visits$.push(
                    `${Date.now()} ${request.method()} ${request.url().replace(BASE, '')} [${headers['x-inertia-partial-data'] ?? 'full'}]`,
                );
            }
        });
        const everyone = [ana, beto, admin];

        await Promise.all([
            login(ana, USERS.ana, { settle: false }),
            login(beto, USERS.beto, { settle: false }),
            login(admin, USERS.superadmin, { settle: false }),
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
                drained && states.every((state) => state.endsWith('true')),
                `seed jobs drained=${drained}; ${states.join(', ')}`,
            ];
        });

        // 2. The spy sees the real chime of "Probar sonido" (2 tones).
        await step('alert-spy-calibration', 'mouse', async () => {
            await ana.goto(`${BASE}/settings/notifications`);
            const notice = ana.locator('[data-test="sound-locked-notice"]');
            const lockedBefore = await notice.isVisible();
            const before = await alertCounts(ana);
            await activate(ana);
            const noticeGone = await notice
                .waitFor({ state: 'hidden', timeout: 5_000 })
                .then(() => true)
                .catch(() => false);
            const delta = await alertDelta(ana, () =>
                ana
                    .getByRole('button', { name: 'Probar sonido', exact: true })
                    .click(),
            );

            return [
                lockedBefore &&
                    before.contexts === 0 &&
                    noticeGone &&
                    delta.chimes === 1 &&
                    delta.tones === 2 &&
                    delta.lastState === 'running' &&
                    delta.media === 0,
                `locked notice before click=${lockedBefore}, gone after=${noticeGone}; audio contexts before activation=${before.contexts}; test sound: chimes=${delta.chimes} tones=${delta.tones} state=${delta.lastState} media=${delta.media}`,
            ];
        });

        // 3. Channel and connection on every row; one customer, two numbers.
        await step('inbox-two-connections', 'render', async () => {
            const visitsBefore = admin.visits$.length;
            await openInboxView(admin, 'Todas');
            await settled(admin);
            const rows = await admin.locator('[data-inbox-item]').all();
            const described = await Promise.all(
                rows.map(async (row) => ({
                    label: await row.getAttribute('aria-label'),
                    text: await row.innerText(),
                    all: await row.textContent(),
                })),
            );
            const withConnection = described.filter(
                (row) =>
                    /recibida en WhatsApp: E2E WhatsApp (Ventas|Sucursal)/.test(
                        row.label,
                    ) && /E2E WhatsApp (Ventas|Sucursal)/.test(row.text),
            );
            // Phase 4: pending rows start with "Pendiente de aceptar: ".
            const norte = described.filter((row) =>
                row.label
                    .replace(/^Pendiente de aceptar: /, '')
                    .startsWith('Cliente Norte,'),
            );
            const norteConnections = norte
                .map((row) =>
                    row.text.includes(connectionName.a)
                        ? 'a'
                        : row.text.includes(connectionName.b)
                          ? 'b'
                          : '?',
                )
                .sort()
                .join('');

            return [
                rows.length === 10 &&
                    withConnection.length === rows.length &&
                    norteConnections === 'ab',
                `rows=${rows.length} (expected 10 active), with channel+connection=${withConnection.length}, Cliente Norte rows on connections "${norteConnections}"${rows.length === 10 ? '' : `; url=${new URL(admin.url()).search}; visits=${JSON.stringify(admin.visits$.slice(Math.max(0, visitsBefore - 8)))}`}`,
            ];
        });

        // 4. Quick filters combined on Ana's own conversations.
        await step(
            'combined-filters',
            'unread + connection + status',
            async () => {
                await ana.goto(`${BASE}/conversations`);
                await openInboxView(ana, 'Mis conversaciones');
                await settled(ana);
                const notes = [];
                const all = (await rowNames(ana)).sort();
                notes.push(`mine=${all.join('/')}`);

                await unreadChip(ana).click();
                await ana.waitForURL(/unread=1/);
                await settled(ana);
                const unread = (await rowNames(ana)).sort();
                const unreadChipCount = await chipCount(ana);
                notes.push(
                    `unread=${unread.join('/')} chip=${unreadChipCount}`,
                );

                await ana.getByRole('button', { name: /^Filtros/ }).click();
                const options = await ana
                    .locator('#inbox-connection option')
                    .allInnerTexts();
                await ana
                    .locator('#inbox-connection')
                    .selectOption(String(data.connections.b.id));
                // Phase 5.1: the panel edits a draft; «Aplicar» applies it.
                await applyInboxFilters(ana);
                await ana.waitForURL(
                    new RegExp(`connection=${data.connections.b.id}`),
                );
                await settled(ana);
                const unreadB = (await rowNames(ana)).sort();
                notes.push(`unread+B=${unreadB.join('/')}`);

                // "Mis conversaciones" has no status chips (they narrow the
                // general inbox); its status is the "Estado" group of the
                // "Filtros" panel. "En espera" is retired (Fase 5): the
                // status narrowed here is "En atención".
                await chooseInboxStatus(ana, 'En atención');
                await ana.waitForURL(/status=open/);
                await settled(ana);
                const unreadBOpen = (await rowNames(ana)).sort();
                const openChip = await chipCount(ana);
                notes.push(
                    `unread+B+open=${unreadBOpen.join('/')} chip=${openChip}`,
                );

                await ana.reload();
                await settled(ana);
                const afterReload = (await rowNames(ana)).sort();
                const pressed =
                    await unreadChip(ana).getAttribute('aria-pressed');
                notes.push(
                    `reload=${afterReload.join('/')} unreadPressed=${pressed}`,
                );

                await ana.getByRole('button', { name: /^Filtros/ }).click();
                // «Limpiar» of the panel resets every filter and applies.
                await clearInboxFilters(ana);
                await settled(ana);
                const unreadOffAfterClear =
                    (await unreadChip(ana).getAttribute('aria-pressed')) ===
                    'false';
                // Phase 4: the status of "Mis conversaciones" is a filter of
                // the popover, so "Limpiar filtros" also brings it back to
                // the active conversations.
                await ana.waitForURL((url) => !/status=open/.test(url.search));
                await settled(ana);
                const cleared = (await rowNames(ana)).sort();
                const clearedUrl = new URL(ana.url()).search;
                notes.push(`cleared=${cleared.join('/')} url=${clearedUrl}`);

                const same = (list, expected) =>
                    list.join('/') ===
                    [...expected]
                        .sort((a, b) => (a < b ? -1 : a > b ? 1 : 0))
                        .join('/');

                return [
                    options.length === 3 &&
                        same(all, [
                            'Cliente Bajío',
                            'Cliente Golfo',
                            'Cliente Laguna',
                            'Cliente Poniente',
                        ]) &&
                        same(unread, [
                            'Cliente Golfo',
                            'Cliente Laguna',
                            'Cliente Poniente',
                        ]) &&
                        unreadChipCount === unread.length &&
                        same(unreadB, ['Cliente Golfo', 'Cliente Poniente']) &&
                        same(unreadBOpen, [
                            'Cliente Golfo',
                            'Cliente Poniente',
                        ]) &&
                        openChip === 2 &&
                        same(afterReload, [
                            'Cliente Golfo',
                            'Cliente Poniente',
                        ]) &&
                        pressed === 'true' &&
                        unreadOffAfterClear &&
                        // Phase 5: the view is one more filter of the panel,
                        // so "Limpiar filtros" returns to the general inbox
                        // (Ana's own conversations plus the pending ones).
                        clearedUrl === '' &&
                        all.every((name) => cleared.includes(name)),
                    `${notes.join('; ')}; connection options=${JSON.stringify(options)}`,
                ];
            },
        );

        // 5. Accept from the list, two sessions at the same time.
        let winner = null;
        let loser = null;

        await step('quick-accept-race', 'two sessions, mouse', async () => {
            // Back to the default status tab (the filters above are Ana's).
            await ana.goto(`${BASE}/conversations?status=active`);
            await Promise.all([
                openInboxView(ana, 'Pendientes'),
                openInboxView(beto, 'Pendientes'),
            ]);
            await Promise.all([settled(ana), settled(beto)]);
            await Promise.all(
                [ana, beto].map((page) =>
                    acceptButton(page, 'Cliente Sur').waitFor(),
                ),
            );

            const posts = { ana: 0, beto: 0 };

            for (const page of [ana, beto]) {
                page.on('request', (request) => {
                    if (
                        request.method() === 'POST' &&
                        request
                            .url()
                            .endsWith(
                                `/conversations/${conversation('sur@a')}/accept`,
                            )
                    ) {
                        posts[page.session]++;
                    }
                });
                await page.evaluate(() => (window.__e2eSameDocument = true));
            }

            const since = Date.now();
            await Promise.all([
                acceptButton(ana, 'Cliente Sur').click(),
                acceptButton(beto, 'Cliente Sur').click(),
            ]);
            const [anaToast, betoToast] = await Promise.all([
                toastText(ana, /Aceptaste|Otro agente ya está atendiendo/),
                toastText(beto, /Aceptaste|Otro agente ya está atendiendo/),
            ]);
            const won = (text) => /Aceptaste la conversación/.test(text);
            const lost = (text) =>
                /Otro agente ya está atendiendo esta conversación/.test(text);

            if (won(anaToast) && lost(betoToast)) {
                [winner, loser] = [ana, beto];
            } else if (won(betoToast) && lost(anaToast)) {
                [winner, loser] = [beto, ana];
            }

            if (!winner) {
                return [
                    false,
                    `ana: "${anaToast}"; beto: "${betoToast}"; posts=${JSON.stringify(posts)}`,
                ];
            }

            const loserGone = await listRow(loser, 'Cliente Sur')
                .waitFor({ state: 'detached', timeout: LIVE_BUDGET_MS })
                .then(() => Date.now() - since)
                .catch(() => null);
            await winner.waitForURL(
                new RegExp(`[?&]c=${conversation('sur@a')}`),
            );
            const winnerName = winner === ana ? 'E2E Ana' : 'E2E Beto';
            const adminSees = await measure(
                'accept→admin row responsable',
                admin,
                admin.locator(
                    `[data-inbox-item][aria-label^="Cliente Sur,"][aria-label*="responsable ${winnerName}"]`,
                ),
                since,
                'inbox.changed',
            );
            const sameDocument = await Promise.all(
                [ana, beto].map((page) =>
                    page.evaluate(() => window.__e2eSameDocument === true),
                ),
            );
            const loserState = await loser.evaluate(() => ({
                url: location.search,
                selected: document.querySelector(
                    '[data-inbox-item][aria-current="true"]',
                ),
            }));

            return [
                loserGone !== null &&
                    sameDocument.every(Boolean) &&
                    posts.ana === 1 &&
                    posts.beto === 1 &&
                    adminSees.live &&
                    loserState.selected === null,
                `winner=${winner.session} "${winner === ana ? anaToast : betoToast}"; loser=${loser.session} "${loser === ana ? anaToast : betoToast}", row gone in ${loserGone} ms without reload=${sameDocument.join('/')}; POSTs=${JSON.stringify(posts)}; ${adminSees.note}`,
            ];
        });

        // 6. Unread counters live and cleared on open (Ana's own list).
        await step('unread-clears', 'signed webhook + mouse', async () => {
            await ana.goto(`${BASE}/conversations?status=active`);
            await openInboxView(ana, 'Mis conversaciones');
            await settled(ana);
            await activate(ana);
            const chipBefore = await chipCount(ana);
            const since = Date.now();
            const status = await customerWrites(
                'b',
                '5215511130005',
                'Cliente Poniente',
                '¿Ya tienen mi factura?',
            );
            const live = await measure(
                'inbound→unread counter',
                ana,
                ana.locator(
                    '[data-inbox-item][aria-label^="Cliente Poniente,"][aria-label*="3 mensajes sin leer"]',
                ),
                since,
                'inbox.changed',
            );
            await listRow(ana, 'Cliente Poniente').click();
            await ana.waitForURL(
                new RegExp(`[?&]c=${conversation('poniente@b')}`),
            );
            await bubble(ana, '¿Ya tienen mi factura?').waitFor();
            const clearedNow = await waitFor(
                async () =>
                    !/sin leer/.test(
                        (await rowLabel(ana, 'Cliente Poniente')) ?? 'sin leer',
                    ),
                5_000,
            );
            const chipAfter = await waitFor(
                async () => (await chipCount(ana)) === chipBefore - 1,
                5_000,
            );
            const badge = await listRow(ana, 'Cliente Poniente')
                .locator('span.bg-primary.rounded-full')
                .count();
            await ana.goto(`${BASE}/conversations`);
            await settled(ana);
            const afterReload = (await rowLabel(ana, 'Cliente Poniente')) ?? '';
            const bell = await unreadNotificationsOf(
                ana,
                conversation('poniente@b'),
            );

            return [
                status === 200 &&
                    live.live &&
                    clearedNow &&
                    chipAfter &&
                    badge === 0 &&
                    !/sin leer/.test(afterReload) &&
                    bell === 0,
                `webhook ${status}; ${live.note}; cleared on open=${clearedNow}, badge=${badge}, chip ${chipBefore}→${await chipCount(ana)}; after reload "${afterReload.slice(0, 60)}"; unread notifications of the conversation=${bell}`,
            ];
        });

        // 7. On another page: one toast and one chime per message.
        await step(
            'incoming-alert-once',
            'signed webhook, other page',
            async () => {
                await sidebarLink(ana, 'Contactos').click();
                await ana.waitForURL(/\/contacts/);
                await settled(ana);
                await activate(ana);
                const notes = [];
                await watchLiveToasts(ana, /Cliente Bajío/);

                const since = Date.now();
                const first = await alertDelta(
                    ana,
                    async () => {
                        await customerWrites(
                            'a',
                            '5215511130006',
                            'Cliente Bajío',
                            '¿Me confirman la entrega?',
                        );
                        await toastText(ana, /Mensaje nuevo de Cliente Bajío/);
                    },
                    { settleMs: 2_500 },
                );
                const firstToasts = await toastsSince(
                    ana,
                    since,
                    /Cliente Bajío/,
                );
                notes.push(
                    `1st message: toasts=${firstToasts.length} chimes=${first.chimes} tones=${first.tones} media=${first.media}`,
                );

                // Measured when the folded toast shows, not after a fixed
                // delay: a toast lives 4 s after its last update, so a later
                // sample depends on how fast the webhook was delivered.
                const second = await alertDelta(
                    ana,
                    async () => {
                        await customerWrites(
                            'a',
                            '5215511130006',
                            'Cliente Bajío',
                            'Es para el viernes',
                        );
                        const text = await toastText(
                            ana,
                            /2 mensajes nuevos de Cliente Bajío/,
                        );

                        return { text, live: await liveToasts(ana) };
                    },
                    { settleMs: 2_500 },
                );
                const bajioToasts = await toastsSince(
                    ana,
                    since,
                    /Cliente Bajío/,
                );
                const { maxLive } = await liveToasts(ana);
                notes.push(
                    `2nd message: "${second.result.text.slice(0, 60)}", on screen when shown=${second.result.live.now}, most at once=${maxLive}, toast elements since 1st=${bajioToasts.length} (1 = updated in place, 2 = the 1st had already closed), chimes=${second.chimes} tones=${second.tones}`,
                );

                const urgentSince = Date.now();
                const urgent = await alertDelta(
                    ana,
                    () =>
                        customerWrites(
                            'b',
                            '5215511130011',
                            'Cliente Sureste',
                            'Hola, primera vez que escribo',
                        ),
                    { settleMs: 6_000 },
                );
                const urgentToasts = await toastsSince(ana, urgentSince);
                notes.push(
                    `new pending (urgent): toasts=${JSON.stringify(urgentToasts.map((t) => t.text.slice(0, 60)))} chimes=${urgent.chimes} tones=${urgent.tones} media=${urgent.media}`,
                );

                return [
                    firstToasts.length === 1 &&
                        first.chimes === 1 &&
                        first.tones === 1 &&
                        second.result.text.startsWith(
                            '2 mensajes nuevos de Cliente Bajío',
                        ) &&
                        second.result.live.now === 1 &&
                        maxLive === 1 &&
                        bajioToasts.length <= 2 &&
                        second.chimes === 1 &&
                        urgentToasts.length === 1 &&
                        urgent.chimes === 1 &&
                        urgent.tones === 2 &&
                        first.media + second.media + urgent.media === 0,
                    notes.join('; '),
                ];
            },
        );

        // 8. The conversation open and visible: no toast, no sound.
        await step(
            'no-alert-open-conversation',
            'signed webhook, chat open',
            async () => {
                await sidebarLink(ana, 'Conversaciones').click();
                await ana.waitForURL(/\/conversations/);
                await settled(ana);
                await listRow(ana, 'Cliente Bajío').click();
                await ana.waitForURL(
                    new RegExp(`[?&]c=${conversation('bajio@a')}`),
                );
                await waitFor(
                    () =>
                        ana.frames$.some(
                            (frame) =>
                                frame.event ===
                                    'pusher_internal:subscription_succeeded' &&
                                frame.channel ===
                                    `private-conversations.${conversation('bajio@a')}`,
                        ),
                    10_000,
                );
                await settled(ana);
                const since = Date.now();
                const delta = await alertDelta(
                    ana,
                    async () => {
                        await customerWrites(
                            'a',
                            '5215511130006',
                            'Cliente Bajío',
                            'Y si llega antes, mejor',
                        );
                        await bubble(ana, 'Y si llega antes, mejor').waitFor({
                            timeout: LIVE_BUDGET_MS,
                        });
                    },
                    { settleMs: 3_000 },
                );
                const toasts = await toastsSince(ana, since);
                const unread = await unreadNotificationsOf(
                    ana,
                    conversation('bajio@a'),
                );

                return [
                    delta.tones === 0 && toasts.length === 0 && unread === 0,
                    `bubble shown live; tones=${delta.tones}; toasts=${JSON.stringify(toasts.map((t) => t.text))}; unread notifications of the open conversation=${unread}`,
                ];
            },
        );

        // 9. The agent's own reply never alerts.
        await step('no-alert-own-message', 'keyboard', async () => {
            const text = 'Sí, el viernes llega su pedido.';
            const since = Date.now();
            const delta = await alertDelta(
                ana,
                async () => {
                    await ana.locator('#chat-composer').click();
                    await ana.keyboard.type(text);
                    await ana.keyboard.press('Enter');
                    await bubble(ana, text).waitFor();
                    await waitFor(
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
                },
                { settleMs: 2_500 },
            );
            const toasts = await toastsSince(
                ana,
                since,
                /mensajes? nuevos?|pendiente/i,
            );

            return [
                delta.tones === 0 && toasts.length === 0,
                `tones=${delta.tones}; notification toasts=${toasts.length}`,
            ];
        });

        // 9b. A customer message delivered late (written before the reply
        // already on screen) lands above it and is still announced as new.
        await step(
            'late-inbound-announced',
            'signed webhook with an earlier timestamp, chat open',
            async () => {
                const reply = 'Sí, el viernes llega su pedido.';
                const late = '¿Me confirma la dirección de entrega?';
                await ana.evaluate(() => {
                    const region = document.querySelector(
                        '[role="log"] ~ p[aria-live="polite"]',
                    );
                    window.__lateAnnouncements = [];

                    if (region) {
                        new MutationObserver(() => {
                            const text = region.textContent?.trim() ?? '';

                            if (text !== '') {
                                window.__lateAnnouncements.push(text);
                            }
                        }).observe(region, {
                            childList: true,
                            characterData: true,
                            subtree: true,
                        });
                    }
                });
                const status = await customerWrites(
                    'a',
                    '5215511130006',
                    'Cliente Bajío',
                    late,
                    90,
                );
                await bubble(ana, late).waitFor({ timeout: LIVE_BUDGET_MS });
                const order = await ana
                    .locator('[data-message-id]')
                    .evaluateAll(
                        (nodes, texts) =>
                            texts.map((text) =>
                                nodes.findIndex((node) =>
                                    node.textContent?.includes(text),
                                ),
                            ),
                        [late, reply],
                    );
                const announced = await waitFor(
                    async () =>
                        (
                            await ana.evaluate(() => window.__lateAnnouncements)
                        ).some((text) =>
                            text.startsWith('Nuevo mensaje de Cliente Bajío'),
                        ),
                    5_000,
                );

                return [
                    status === 200 &&
                        order[0] >= 0 &&
                        order[0] < order[1] &&
                        announced,
                    `webhook ${status}; positions late=${order[0]} reply=${order[1]}; announced=${announced}`,
                ];
            },
        );

        // 10. Inertia visits keep a single notification center.
        await step(
            'navigation-no-duplicate-listeners',
            'sidebar visits + webhook',
            async () => {
                const since = Date.now();
                const fetches = [];
                const onRequest = (request) => {
                    if (
                        request.method() === 'GET' &&
                        new URL(request.url()).pathname === '/notifications'
                    ) {
                        fetches.push(Date.now());
                    }
                };
                ana.on('request', onRequest);

                for (const title of [
                    'Contactos',
                    'Conversaciones',
                    'Contactos',
                ]) {
                    await sidebarLink(ana, title).click();
                    await ana.waitForURL(
                        title === 'Contactos'
                            ? /\/contacts/
                            : /\/conversations/,
                    );
                    await settled(ana);
                }

                const sent = Date.now();
                const delta = await alertDelta(
                    ana,
                    async () => {
                        await customerWrites(
                            'a',
                            '5215511130010',
                            'Cliente Laguna',
                            '¿Tienen la pieza 4471?',
                        );
                        await toastText(ana, /Cliente Laguna/);
                    },
                    { settleMs: 3_000 },
                );
                ana.off('request', onRequest);
                const toasts = await toastsSince(ana, sent, /Cliente Laguna/);
                const signals = ana.frames$.filter(
                    (frame) =>
                        frame.t >= sent && frame.event === 'notification',
                ).length;
                const fetchesAfter = fetches.filter((t) => t >= sent).length;
                // A new socket (__open / connection_established) or a late
                // access.changed legitimately subscribes the user channel
                // again; only a re-subscription without either is a leak.
                const userChannel = `private-users.${data.agents.ana}`;
                const window$ = ana.frames$.filter((frame) => frame.t >= since);
                let explained = false;
                let subscriptions = 0;
                let resubscriptions = 0;

                for (const frame of window$) {
                    if (
                        frame.event === '__open' ||
                        frame.event === 'pusher:connection_established' ||
                        (frame.event === 'access.changed' &&
                            frame.channel === userChannel)
                    ) {
                        explained = true;
                    } else if (
                        frame.event ===
                            'pusher_internal:subscription_succeeded' &&
                        frame.channel === userChannel
                    ) {
                        if (explained) {
                            resubscriptions++;
                        } else {
                            subscriptions++;
                        }
                    }
                }

                const closes = window$.filter(
                    (frame) => frame.event === '__close',
                ).length;
                const accessChanges = window$.filter(
                    (frame) =>
                        frame.event === 'access.changed' &&
                        frame.channel === userChannel,
                ).length;

                return [
                    toasts.length === 1 &&
                        delta.chimes === 1 &&
                        delta.tones === 1 &&
                        signals >= 1 &&
                        fetchesAfter >= 1 &&
                        fetchesAfter <= signals &&
                        subscriptions === 0,
                    `toasts=${toasts.length}, chimes=${delta.chimes}, tones=${delta.tones}; .notification signals=${signals}, GET /notifications after it=${fetchesAfter}; user channel re-subscriptions during the visits=${subscriptions} (+${resubscriptions} after a reconnection or access change; __close=${closes}, access.changed=${accessChanges})`,
                ];
            },
        );

        // 11-12. Phones, tablets and desktops; light and dark.
        await step('mobile-navigation', '390px touch', async () => {
            const phone = await openPage(browser, {
                context: {
                    viewport: { width: 390, height: 844 },
                    hasTouch: true,
                    isMobile: true,
                },
                prefix: '[phone] ',
            });

            try {
                await login(phone, USERS.ana);
                await phone.goto(`${BASE}/conversations`);
                await openInboxView(phone, 'Mis conversaciones');
                await listRow(phone, 'Cliente Laguna').tap();
                const chat = phone.getByRole('region', {
                    name: 'Conversación con Cliente Laguna',
                });
                await chat.waitFor();
                const listHidden = !(await phone
                    .getByRole('region', { name: 'Lista de conversaciones' })
                    .isVisible());
                await phone
                    .getByRole('button', {
                        name: 'Volver a la lista de conversaciones',
                    })
                    .tap();
                await phone
                    .getByRole('region', { name: 'Lista de conversaciones' })
                    .waitFor();
                const back = await phone
                    .waitForURL((url) => !/[?&]c=/.test(url.search), {
                        timeout: 5_000,
                    })
                    .then(() => true)
                    .catch(() => false);

                await phone.goto(`${BASE}/contacts`);
                await settled(phone);
                const more = phone.getByRole('button', {
                    name: /Más acciones/,
                });
                const hasMore = (await more.count()) > 0;

                return [
                    listHidden && back && (await chat.count()) === 0,
                    `chat alone on screen=${listHidden}; back to list without c=${back}; agent sees "Más acciones" in Contactos=${hasMore}`,
                ];
            } finally {
                await phone.context().close();
            }
        });

        // The layout detector sees what the document width cannot: a wide
        // block inside the clipped inbox panel.
        await step(
            'layout-detector-calibration',
            'injected 2000px block',
            async () => {
                await admin.goto(`${BASE}/conversations`);
                await settled(admin);
                const clean = await overflow(admin);
                await admin.evaluate(() => {
                    const wide = document.createElement('div');
                    wide.id = 'e2e-wide';
                    wide.textContent = 'E2E bloque ancho';
                    wide.style.width = '2000px';
                    document
                        .querySelector(
                            'section[aria-label="Lista de conversaciones"] header',
                        )
                        .append(wide);
                });
                const report = await layoutReport(admin);
                const detected = await overflow(admin);
                await admin.evaluate(() =>
                    document.getElementById('e2e-wide')?.remove(),
                );

                return [
                    clean <= 0 && report.doc <= 0 && detected > 0,
                    `before=${clean}; with the block: document=${report.doc} (page does not grow), detected=${detected} (${layoutProblems(report).slice(0, 160)})`,
                ];
            },
        );

        // One login reused by every viewport (the login is rate limited per
        // account and address).
        const storageState = await admin.context().storageState();

        for (const scheme of ['light', 'dark']) {
            for (const width of WIDTHS) {
                await step(
                    `responsive-${width}-${scheme}`,
                    `${width}px ${scheme}`,
                    () =>
                        responsive(browser, width, scheme, {
                            chatId: conversation('poniente@b'),
                            stressId: stress.conversation,
                            storageState,
                        }),
                );
            }
        }
    } finally {
        stopChildren();
        await browser.close();
    }

    recordPageProblems(800);
    printLatencies();
    printProblems();

    const failed = results.filter((result) => !result.ok);
    console.log(
        `\n${results.length - failed.length}/${results.length} scenarios passed`,
    );
    process.exit(failed.length === 0 ? 0 : 1);
};

/**
 * Main actions of each page that must be comfortable to touch on phones:
 * the page header (PageHeader: the header with the h1), the app bar
 * (navigation menu, bell, theme) and each page's primary controls. The
 * contact cards are not listed: their name link is stretched over the
 * whole card (after:inset-0), so the card is the target.
 */
const APP_BAR = ['[data-sidebar="trigger"]', 'main header.sticky button'];
const TARGETS = {
    contacts: [
        ...APP_BAR,
        'main header:has(h1) a',
        'main header:has(h1) button',
        'input[aria-label="Buscar contactos"]',
        'nav[aria-label*="aginación"] a',
    ],
    import: [
        ...APP_BAR,
        'main header:has(h1) a',
        'main header:has(h1) button',
        'main form button',
    ],
    dialog: ['[role="dialog"] form > div:last-child > button'],
    list: [
        'nav[aria-label="Bandejas"] button',
        '[data-inbox-unread]',
        'section[aria-label="Lista de conversaciones"] [role="search"] button',
        'section[aria-label="Lista de conversaciones"] input[type="search"]',
        'label:has([role="switch"])',
        '[data-inbox-item]',
        '[data-inbox-accept]',
    ],
    chat: [
        'button[aria-label="Volver a la lista de conversaciones"]',
        '[role="group"][aria-label="Operaciones de la conversación"] button',
        'button[aria-label="Más acciones de la conversación"]',
        'button[aria-label="Adjuntar archivo"]',
        '#chat-composer',
        'form button[type="submit"]',
    ],
};

/**
 * One viewport width and color scheme: Contactos (Index, Import, the
 * contact dialog with two custom fields) and Conversaciones (list, chat).
 */
async function responsive(
    browser,
    width,
    scheme,
    { chatId, stressId, storageState },
) {
    const phone = width <= 430;
    const page = await openPage(browser, {
        context: {
            viewport: { width, height: phone ? 844 : 900 },
            colorScheme: scheme,
            hasTouch: phone,
            isMobile: phone,
            storageState,
        },
        prefix: `[${width}${scheme}] `,
    });
    const problems = [];
    const notes = [];
    const check = async (name, options = {}) => {
        const report = await layoutReport(page, {
            minTarget: 40,
            ...options,
            targets: phone ? (options.targets ?? []) : [],
        });
        const text = layoutProblems(report);
        await shot(page, `${name}-${width}-${scheme}`, { fullPage: false });

        if (text) {
            problems.push(`${name}: ${text}`);
        }

        return report;
    };
    const headerText = async (name) => {
        const description = page.locator('main header p').first();

        if ((await description.count()) === 0) {
            problems.push(`${name}: no header description`);

            return;
        }

        const box = await description.boundingBox();
        const minimum = width >= 640 ? 240 : Math.min(240, width - 64);
        notes.push(`${name} description ${Math.round(box.width)}px`);

        if (box.width < minimum) {
            problems.push(
                `${name}: header description squeezed to ${Math.round(box.width)}px (< ${minimum})`,
            );
        }
    };

    try {
        await page.goto(`${BASE}/contacts`);
        const dark = await page.evaluate(() =>
            document.documentElement.classList.contains('dark'),
        );

        if (dark !== (scheme === 'dark')) {
            problems.push(`dark class=${dark}`);
        }

        await page.goto(`${BASE}/contacts`);
        await settled(page);
        await check('contacts', { targets: TARGETS.contacts });
        await headerText('contacts');

        await page
            .getByRole('button', { name: /^Nuevo contacto/ })
            .first()
            .click();
        const dialog = page.getByRole('dialog');
        await dialog.waitFor();
        await dialog.getByRole('button', { name: /Agregar campo/ }).click();
        await dialog.getByRole('button', { name: /Agregar campo/ }).click();
        const dialogBox = await dialog.boundingBox();

        if (dialogBox.x < 0 || dialogBox.x + dialogBox.width > width) {
            problems.push(
                `dialog: outside the viewport x=${Math.round(dialogBox.x)} w=${Math.round(dialogBox.width)}`,
            );
        }

        await check('contact-dialog', { targets: TARGETS.dialog });
        await page.keyboard.press('Escape');
        await dialog.waitFor({ state: 'detached' }).catch(() => {});

        await page.goto(`${BASE}/contacts/import`);
        await settled(page);
        await check('contacts-import', { targets: TARGETS.import });
        await headerText('contacts-import');

        await page.goto(`${BASE}/conversations`);
        await settled(page);
        await check('conversations-list', {
            fontRoots: ['section[aria-label="Lista de conversaciones"]'],
            targets: TARGETS.list,
        });

        await page.goto(`${BASE}/conversations?c=${chatId}`);
        await page
            .getByRole('log', { name: /Historial de la conversación/ })
            .waitFor();
        await settled(page);
        await check('conversations-chat', {
            fontRoots: ['section[aria-label^="Conversación con"]'],
            targets: TARGETS.chat,
        });

        // The stress conversation: 120-character PDF name, 90-character
        // words in a quote and a note, a 300-character URL, 40 emojis and
        // long names (tests/e2e/chat-movil-seed.php).
        await page.goto(`${BASE}/conversations?c=${stressId}`);
        await page
            .getByRole('log', { name: /Historial de la conversación/ })
            .waitFor();
        await settled(page);
        await check('conversations-stress', {
            fontRoots: ['section[aria-label^="Conversación con"]'],
            targets: TARGETS.chat,
        });

        return [
            problems.length === 0,
            `${problems.join(' | ') || 'no overflow, fonts >= 12px, targets ok'}; ${notes.join(', ')}`,
        ];
    } finally {
        await page.context().close();
    }
}

run().catch((error) => {
    console.error(error);
    stopChildren();
    process.exit(1);
});
