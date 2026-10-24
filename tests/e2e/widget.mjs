/*
 * The embeddable web chat widget on real host pages of ANOTHER site
 * (tests/e2e/hosts, served by run.php on E2E_HOST_PORT as
 * http://tienda.test:{port}; Chromium maps tienda.test and intruso.test to
 * 127.0.0.1, see launchBrowser) while the application runs on
 * http://127.0.0.1:{E2E_PORT}. Seed: tests/e2e/widget-seed.php (two web
 * chat connections: "E2E Chat web Tienda" allows only the shop's origin,
 * "E2E Chat web Otra marca" only https://otra-marca.test).
 *
 *   snippet queue      calls made before the loader exists are replayed
 *   init               idempotent (one node, one listener, same instance)
 *   open/close/toggle  aria-expanded follows; Escape closes and the focus
 *                      goes back to the launcher
 *   postMessage        forged messages (the host window itself, a frame of
 *                      another origin) are ignored
 *   token              the host cannot read it (the iframe's localStorage
 *                      throws SecurityError) and it never is in a URL
 *   intruso.test       does not mount (CSP frame-ancestors) and no session
 *                      request ever answers 2xx; neither does a widget whose
 *                      origins do not include the shop
 *   hostile CSS        the host's !important resets do not reach it
 *   widths             WIDTHS without overflow, inside the iframe too
 *   agent round trip   E2E Ana accepts and answers in Chat Manager; the reply
 *                      reaches the visitor through Reverb (E2E_REVERB=1,
 *                      'webchat.signal' frame first) or through polling
 *   reload/reconnect   same session and history after a reload and after
 *                      the network comes back
 *   destroy            0 nodes, 0 "message" listeners, its WebSocket closed
 *   logout             ChatManager('logout') revokes the session, drops the
 *                      token and a reload does not show the old thread
 *   required identity  with identity_mode = required a stored session is
 *                      not shown until the page identifies again, and
 *                      another person's identity gets a new, empty thread
 *   jQuery             the adapter with tests/e2e/hosts/jquery-stub.js
 *                      (https://code.jquery.com is answered locally)
 *   without jQuery     0 console errors on the shop's page
 *
 *   E2E_REVERB=1 E2E_PORT=8090 E2E_REVERB_PORT=8151 E2E_HOST_PORT=8092 \
 *     WHATSAPP_TRANSPORT=simulated TEST_DB_LOCK_WAIT=3600 \
 *     php tests/e2e/run.php widget.mjs
 *
 * Without E2E_REVERB the round trip is checked through the polling fallback.
 * SIMULACIÓN: Meta is never involved (web chat only).
 */
import { createHmac, randomBytes } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import {
    BASE,
    HERE,
    HOSTS,
    HOST_PORT,
    LIVE_BUDGET_MS,
    REVERB_PORT,
    USERS,
    WIDTHS,
    exitWithSummary,
    launchBrowser,
    layoutProblems,
    layoutReport,
    login,
    openPage,
    operations,
    php,
    printProblems,
    problems,
    recordPageProblems,
    shot,
    startWorker,
    step,
    stopChildren,
    waitFor,
    watchProblems,
} from './lib.mjs';

if (!HOST_PORT) {
    console.error(
        'Run with E2E_HOST_PORT through tests/e2e/run.php (the host pages are served on that port).',
    );
    process.exit(2);
}

const SEED = join(HERE, 'widget-seed.php');
const REALTIME =
    REVERB_PORT > 0 && process.env.BROADCAST_CONNECTION === 'reverb';
const JQUERY_STUB = readFileSync(join(HERE, 'hosts', 'jquery-stub.js'));
const FRAME_PATH = '/widget/v1/frame/';

/*
 * Before any page script: keep the widget's closed shadow root reachable
 * for the checks (window.__cmRoots) and count the window "message"
 * listeners added and removed.
 */
const SPY = () => {
    // oxlint-disable-next-line typescript/unbound-method -- re-bound with .call
    const attach = Element.prototype.attachShadow;
    window.__cmRoots = [];
    Element.prototype.attachShadow = function (options) {
        const root = attach.call(this, options);
        window.__cmRoots.push(root);

        return root;
    };
    window.__msgListeners = 0;
    const add = window.addEventListener;
    const remove = window.removeEventListener;
    window.addEventListener = function (type, ...rest) {
        if (type === 'message') {
            window.__msgListeners++;
        }

        return add.call(this, type, ...rest);
    };
    window.removeEventListener = function (type, ...rest) {
        if (type === 'message') {
            window.__msgListeners--;
        }

        return remove.call(this, type, ...rest);
    };
};

/** Every answer of POST /api/v1/widget/{key}/sessions, per page label. */
const sessionAnswers = [];

async function hostPage(browser, label, options = {}) {
    const context = await browser.newContext({
        viewport: { width: 1280, height: 800 },
        locale: 'es-MX',
        ...options.context,
    });
    await context.routeWebSocket(
        (url) => url.port === '5173',
        () => {},
    );
    await context.addInitScript(SPY);
    await context.route('https://code.jquery.com/**', (route) =>
        route.fulfill({
            contentType: 'application/javascript',
            body: JQUERY_STUB,
        }),
    );
    const page = await context.newPage();
    page.setDefaultTimeout(15_000);
    page.sockets$ = [];
    page.frames$ = [];
    page.on('websocket', (socket) => {
        page.sockets$.push({ socket, t: Date.now() });
        socket.on('framereceived', ({ payload }) => {
            try {
                const frame = JSON.parse(String(payload));
                page.frames$.push({ t: Date.now(), event: frame.event });
            } catch {
                // Not JSON.
            }
        });
    });
    page.on('response', (response) => {
        if (/\/api\/v1\/widget\/[^/]+\/sessions$/.test(response.url())) {
            sessionAnswers.push({ label, status: response.status() });
        }
    });
    watchProblems(page, options.ignoreConsole ?? /^$/, `[${label}] `);

    return page;
}

const hostUrl = (file, key, origin = HOSTS.shop) =>
    `${origin}/${file}?base=${encodeURIComponent(BASE)}&key=${key}`;

const events = (page, name) =>
    page.evaluate(
        (event) => (window.__events ?? []).filter((e) => e.name === event),
        name,
    );

const widgetFrame = (page) =>
    page.frames().find((frame) => frame.url().includes(FRAME_PATH)) ?? null;

/** Launcher and panel of the (closed) shadow root. */
const widgetState = (page, index = 0) =>
    page.evaluate((i) => {
        const root = window.__cmRoots[i];
        const launcher = root?.querySelector('.l');
        const panel = root?.querySelector('.p');
        const rect = launcher?.getBoundingClientRect();
        const box = panel?.getBoundingClientRect();

        return {
            nodes: document.querySelectorAll('chat-manager-widget').length,
            expanded: launcher?.getAttribute('aria-expanded') ?? null,
            label: launcher?.getAttribute('aria-label') ?? null,
            launcher: rect
                ? {
                      x: rect.x,
                      y: rect.y,
                      w: rect.width,
                      h: rect.height,
                      display: getComputedStyle(launcher).display,
                      font: getComputedStyle(launcher).fontSize,
                  }
                : null,
            panel: box
                ? {
                      x: box.x,
                      y: box.y,
                      w: box.width,
                      h: box.height,
                      display: getComputedStyle(panel).display,
                  }
                : null,
            focus: root?.activeElement?.className ?? null,
            listeners: window.__msgListeners,
        };
    }, index);

async function clickLauncher(page) {
    const { launcher } = await widgetState(page);
    await page.mouse.click(
        launcher.x + launcher.w / 2,
        launcher.y + launcher.h / 2,
    );
}

async function readyFrame(page) {
    await waitFor(async () => widgetFrame(page) !== null, 15_000);
    const frame = widgetFrame(page);
    await frame.waitForSelector('[data-phase="ready"]', { timeout: 20_000 });

    return frame;
}

/**
 * Waits for a locator (or any promise-returning wait) and, on timeout,
 * fails with what was expected and where, instead of Playwright's bare
 * "locator.waitFor: Timeout".
 */
async function expecting(what, page, wait) {
    try {
        return await wait();
    } catch (error) {
        const url = (() => {
            try {
                return page.url();
            } catch {
                return '?';
            }
        })();

        throw new Error(
            `waiting for ${what} at ${url}: ${String(error).split('\n')[0]}`,
        );
    }
}

/** A signed identity, as the host's backend builds it (docs/canal-chat-web.md §5). */
function signIdentity(publicKey, secret, externalId, name) {
    const iat = Math.floor(Date.now() / 1000);
    const nonce = randomBytes(24).toString('base64url');
    const message = [
        'v1',
        publicKey,
        externalId,
        '',
        name,
        String(iat),
        nonce,
    ].join('\n');

    return {
        external_id: externalId,
        email: null,
        name,
        iat,
        nonce,
        signature: createHmac('sha256', secret).update(message).digest('hex'),
    };
}

const seedCheck = (connection) =>
    JSON.parse(php(SEED, 'check', String(connection)));

const run = async () => {
    startWorker();

    const data = JSON.parse(php(SEED, 'prepare', HOSTS.shop));
    const browser = await launchBrowser();
    let shop = null;

    try {
        shop = await hostPage(browser, 'tienda', {
            // Only the network outage of the reconnection step is expected.
            ignoreConsole:
                /ERR_INTERNET_DISCONNECTED|ERR_NETWORK_CHANGED|WebSocket connection to/,
        });
        const frameAnswers = [];
        shop.on('response', (response) => {
            if (response.url().includes(FRAME_PATH)) {
                frameAnswers.push(response);
            }
        });

        // 1. The snippet's queue and the authorized frame.
        await step('snippet-queue-and-frame', 'cross-site host', async () => {
            await shop.goto(hostUrl('host-plain.html', data.shop.key));
            await shop.waitForFunction(
                () => (window.__events ?? []).some((e) => e.name === 'ready'),
                null,
                { timeout: 20_000 },
            );
            const queued = await shop.evaluate(() => window.__queuedBeforeLoad);
            const headers = frameAnswers[0]
                ? await frameAnswers[0].allHeaders()
                : {};
            const ancestors =
                /frame-ancestors[^;]*/.exec(
                    headers['content-security-policy'] ?? '',
                )?.[0] ?? '';

            return [
                queued >= 1 &&
                    ancestors.includes(HOSTS.shop) &&
                    !ancestors.includes('intruso') &&
                    headers['x-frame-options'] === undefined,
                `calls queued before the loader=${queued}; ${ancestors}; X-Frame-Options=${headers['x-frame-options'] ?? 'none'}`,
            ];
        });

        // 2. init is idempotent.
        await step('init-idempotent', 'ChatManager.init twice', async () => {
            const result = await shop.evaluate((key) => {
                const first = window.ChatManager.init({ key });
                const second = window.ChatManager.init({ key });

                return {
                    same: first !== null && first === second,
                    nodes: document.querySelectorAll('chat-manager-widget')
                        .length,
                    listeners: window.__msgListeners,
                    roots: window.__cmRoots.length,
                };
            }, data.shop.key);

            return [
                result.same &&
                    result.nodes === 1 &&
                    result.listeners === 1 &&
                    result.roots === 1,
                JSON.stringify(result),
            ];
        });

        // 3. open, close and toggle with aria-expanded.
        await step('open-close-toggle', 'JS API', async () => {
            const seen = [];

            for (const command of ['open', 'close', 'toggle', 'toggle']) {
                await shop.evaluate((c) => window.ChatManager(c), command);
                const state = await widgetState(shop);
                seen.push(
                    `${command}:${state.expanded}/${await shop.evaluate(() => window.ChatManager('isOpen'))}/${state.panel.display}`,
                );
            }

            const opened = (await events(shop, 'open')).length;
            const closed = (await events(shop, 'close')).length;
            const styles = await shop.evaluate(
                () => window.__styles() === window.__before,
            );

            return [
                seen.join(' ') ===
                    'open:true/true/block close:false/false/none toggle:true/true/block toggle:false/false/none' &&
                    opened === 2 &&
                    closed === 2 &&
                    styles,
                `${seen.join(' ')}; events open=${opened} close=${closed}; host styles intact=${styles}`,
            ];
        });

        // 4. Launcher click, Escape and focus.
        let frame = null;

        await step('escape-returns-focus', 'mouse + keyboard', async () => {
            await clickLauncher(shop);
            frame = await readyFrame(shop);
            const expandedOpen = (await widgetState(shop)).expanded;
            await frame.focus('#cm-message');
            await frame.press('#cm-message', 'Escape');
            await shop.waitForFunction(
                () =>
                    (window.__events ?? []).filter((e) => e.name === 'close')
                        .length === 3,
            );
            const state = await widgetState(shop);

            return [
                expandedOpen === 'true' &&
                    state.expanded === 'false' &&
                    state.focus === 'l',
                `opened by click (aria-expanded=${expandedOpen}); after Escape aria-expanded=${state.expanded}, focus on ${state.focus}`,
            ];
        });

        // 5. Forged messages are ignored.
        await step('forged-postmessage-ignored', 'postMessage', async () => {
            const before = {
                unread: (await events(shop, 'unread')).length,
                close: (await events(shop, 'close')).length,
            };
            await shop.evaluate(() => window.ChatManager('open'));
            await shop.evaluate(() => {
                for (const type of ['cm:close-request', 'cm:unread']) {
                    window.postMessage(
                        {
                            v: 1,
                            source: 'chat-manager',
                            type,
                            payload: { count: 5 },
                        },
                        '*',
                    );
                }
            });
            await shop.evaluate(
                ({ url }) => {
                    const forger = document.createElement('iframe');
                    forger.id = 'e2e-forger';
                    forger.src = url;
                    forger.style.cssText = 'width:1px;height:1px;border:0';
                    document.body.append(forger);
                },
                {
                    url: hostUrl(
                        'host-intruder.html',
                        data.shop.key,
                        HOSTS.intruder,
                    ).replace('?', '?forge=1&'),
                },
            );
            await shop.waitForTimeout(1_500);
            const after = {
                unread: (await events(shop, 'unread')).length,
                close: (await events(shop, 'close')).length,
            };
            const state = await widgetState(shop);
            await shop.evaluate(() =>
                document.getElementById('e2e-forger')?.remove(),
            );

            return [
                after.unread === before.unread &&
                    after.close === before.close &&
                    state.expanded === 'true' &&
                    !/Falso/.test(state.label ?? ''),
                `events before ${JSON.stringify(before)}, after ${JSON.stringify(after)}; still open=${state.expanded}; label "${state.label}"`,
            ];
        });

        // 6. The visitor writes; a web chat conversation is created.
        const visitorText = `Hola desde el widget E2E ${Date.now()}`;
        let conversationId = null;

        await step('visitor-message', 'keyboard', async () => {
            frame = widgetFrame(shop);
            await frame.fill('#cm-message', visitorText);
            await frame.press('#cm-message', 'Enter');
            await frame.waitForSelector('[data-status="sent"]', {
                timeout: 15_000,
            });
            const check = seedCheck(data.shop.id);
            const conversation = check.conversations[0];
            conversationId = conversation?.id ?? null;

            return [
                check.conversations.length === 1 &&
                    conversation.source === 'web_chat' &&
                    conversation.messages.some(
                        (message) =>
                            message.body === visitorText &&
                            message.direction === 'inbound' &&
                            message.provider_id.startsWith('web.in.'),
                    ) &&
                    check.sessions.length === 1 &&
                    check.sessions[0].origin === HOSTS.shop,
                `conversations=${check.conversations.length} status=${conversation?.status} contact="${conversation?.contact}" source=${conversation?.source}; sessions=${JSON.stringify(check.sessions)}`,
            ];
        });

        // 7. The token stays in the iframe.
        await step('token-isolated', 'host JavaScript', async () => {
            const host = await shop.evaluate(() => {
                const iframe = window.__cmRoots[0].querySelector('iframe');
                let error = null;

                try {
                    void iframe.contentWindow.localStorage.length;
                } catch (exception) {
                    error = exception.name;
                }

                return {
                    keys: Object.keys(localStorage),
                    error,
                    src: iframe.src,
                };
            });
            const stored = await frame.evaluate(
                (key) => localStorage.getItem(`cm:v1:${key}`),
                data.shop.key,
            );
            const token = /wvs_[a-z0-9]+_[A-Za-z0-9]+/.exec(stored ?? '')?.[0];

            return [
                host.keys.length === 0 &&
                    host.error === 'SecurityError' &&
                    token !== undefined &&
                    !host.src.includes(token) &&
                    !frame.url().includes(token),
                `host localStorage keys=${host.keys.length}; reading the iframe's storage: ${host.error}; token kept in the iframe=${token !== undefined}; token in a URL=${token ? host.src.includes(token) || frame.url().includes(token) : 'n/a'}`,
            ];
        });

        // 8. Round trip with an agent of Chat Manager.
        const ana = await openPage(browser, {
            context: { viewport: { width: 1440, height: 900 } },
            prefix: '[ana] ',
        });
        await login(ana, USERS.ana);

        const agentReply = async (text) => {
            await ana.locator('#chat-composer').fill(text);
            await ana.locator('#chat-composer').press('Enter');
        };

        await step(
            'agent-round-trip',
            REALTIME ? 'Reverb' : 'polling',
            async () => {
                if (conversationId === null) {
                    return [false, 'no conversation from visitor-message'];
                }

                await ana.goto(`${BASE}/conversations?c=${conversationId}`);
                await ana.waitForLoadState('networkidle');
                const accept = operations(ana).getByRole('button', {
                    name: 'Aceptar',
                    exact: true,
                });

                if ((await accept.count()) > 0) {
                    await accept.click();
                    // The state, not the short-lived toast: the button goes
                    // away and the composer can be used.
                    await expecting('"Aceptar" to go away', ana, () =>
                        accept.waitFor({ state: 'detached', timeout: 20_000 }),
                    );
                    await ana.waitForLoadState('networkidle');
                }

                await expecting('an enabled #chat-composer', ana, () =>
                    ana.waitForSelector('#chat-composer:not([disabled])', {
                        timeout: 20_000,
                    }),
                );
                const visitorSeen =
                    (await ana
                        .locator('[data-message-id]', { hasText: visitorText })
                        .count()) > 0;
                const reply = `Hola, soy Ana. Con gusto te ayudo (${Date.now()})`;
                const since = Date.now();
                await agentReply(reply);
                // Sent from Chat Manager first (the bubble of the agent).
                await expecting("the agent's bubble in Chat Manager", ana, () =>
                    ana
                        .locator('[data-message-id]', { hasText: reply })
                        .waitFor({ timeout: 20_000 }),
                );
                await expecting('the reply in the widget', shop, () =>
                    frame.waitForSelector(
                        `li[data-direction="outbound"]:has-text("${reply}")`,
                        { timeout: REALTIME ? LIVE_BUDGET_MS : 25_000 },
                    ),
                );
                const elapsed = Date.now() - since;
                const signal = shop.frames$.find(
                    (entry) =>
                        entry.t >= since && entry.event === 'webchat.signal',
                );
                const agentName = await frame
                    .locator('li[data-direction="outbound"]', {
                        hasText: reply,
                    })
                    .innerText();

                return [
                    visitorSeen &&
                        (!REALTIME ||
                            (signal !== undefined &&
                                signal.t - since <= elapsed &&
                                elapsed <= LIVE_BUDGET_MS)),
                    `visitor message in Chat Manager=${visitorSeen}; reply in the widget after ${elapsed} ms (${REALTIME ? `webchat.signal ${signal ? `${signal.t - since} ms` : 'not seen'}` : 'polling'}); "${agentName.replace(/\s+/g, ' ').slice(0, 80)}"`,
                ];
            },
            async () => {
                await shot(ana, 'widget-round-trip-ana', { fullPage: false });
                await shot(shop, 'widget-round-trip-shop', { fullPage: false });
            },
        );

        // 9. Reload: same session and history (whatever the thread holds:
        // the visitor's message at least, so it does not depend on step 8).
        await step('reload-keeps-history', 'reload', async () => {
            const before = await frame.locator('li[data-direction]').count();
            shop.reloadedAt$ = Date.now();
            await shop.reload();
            await shop.waitForFunction(
                () => (window.__events ?? []).some((e) => e.name === 'ready'),
                null,
                { timeout: 20_000 },
            );
            await shop.evaluate(() => window.ChatManager('open'));
            frame = await readyFrame(shop);
            await expecting('the thread after the reload', shop, () =>
                frame.locator('li[data-direction]').first().waitFor(),
            );
            await waitFor(
                async () =>
                    (await frame.locator('li[data-direction]').count()) >=
                    before,
                10_000,
            );
            const after = await frame.locator('li[data-direction]').count();
            const check = seedCheck(data.shop.id);

            return [
                after >= before &&
                    after >= 1 &&
                    check.conversations.length === 1 &&
                    check.sessions.length === 1,
                `messages before=${before} after reload=${after}; conversations=${check.conversations.length}; sessions=${check.sessions.length}`,
            ];
        });

        // 10. Network outage: the reply sent meanwhile arrives afterwards.
        await step('reconnect-after-outage', 'offline → online', async () => {
            await shop.context().setOffline(true);
            const reply = `Te escribí mientras no tenías conexión (${Date.now()})`;
            await agentReply(reply);
            await shop.waitForTimeout(3_000);
            await shop.context().setOffline(false);
            const since = Date.now();
            await frame.waitForSelector(
                `li[data-direction="outbound"]:has-text("${reply}")`,
                { timeout: 30_000 },
            );
            const elapsed = Date.now() - since;
            const replies = await frame
                .locator('li[data-direction="outbound"]', { hasText: reply })
                .count();
            const check = seedCheck(data.shop.id);

            return [
                replies === 1 &&
                    check.conversations.length === 1 &&
                    check.sessions.length === 1,
                `reply shown ${elapsed} ms after the network came back, ${replies} time(s); conversations=${check.conversations.length}; sessions=${check.sessions.length}`,
            ];
        });

        // 11. Every width, open: no overflow on the host nor in the iframe.
        await step('widths-without-overflow', WIDTHS.join('/'), async () => {
            const notes = [];

            for (const width of WIDTHS) {
                await shop.setViewportSize({ width, height: 800 });
                await shop.evaluate(() => window.ChatManager('open'));
                await shop.waitForTimeout(400);
                const host = layoutProblems(await layoutReport(shop));
                const state = await widgetState(shop);
                const inner = layoutProblems(await layoutReport(frame));
                const panelInside =
                    state.panel.x >= 0 &&
                    state.panel.x + state.panel.w <= width + 0.5 &&
                    state.panel.y >= 0;
                const fullScreen =
                    width > 480 ||
                    (state.panel.x === 0 &&
                        state.panel.w === width &&
                        state.panel.h === 800);

                if (host || inner || !panelInside || !fullScreen) {
                    notes.push(
                        `${width}: host="${host}" iframe="${inner}" panel=${JSON.stringify(state.panel)}`,
                    );
                }

                await shot(shop, `widget-${width}`, { fullPage: false });
            }

            await shop.setViewportSize({ width: 1280, height: 800 });

            return [
                notes.length === 0,
                notes.join(' | ') ||
                    `${WIDTHS.length} widths: panel inside the viewport (full screen up to 480 px), nothing overflows`,
            ];
        });

        // 12. destroy leaves nothing behind.
        await step('destroy-cleans-up', 'ChatManager destroy', async () => {
            // Sockets of the current document (the ones before the reload
            // belonged to the previous page).
            const sockets = shop.sockets$
                .filter(
                    (entry) =>
                        entry.t >= (shop.reloadedAt$ ?? 0) &&
                        !entry.socket.url().includes(':5173'),
                )
                .map((entry) => entry.socket);
            const openBefore = sockets.filter((socket) => !socket.isClosed());
            await shop.evaluate(() => window.ChatManager('destroy'));
            const closed = await waitFor(
                async () => openBefore.every((socket) => socket.isClosed()),
                5_000,
            );
            const after = await shop.evaluate(() => ({
                nodes: document.querySelectorAll('chat-manager-widget').length,
                listeners: window.__msgListeners,
                frames: window.frames.length,
                isOpen: window.ChatManager('isOpen') ?? null,
            }));

            return [
                after.nodes === 0 &&
                    after.listeners === 0 &&
                    after.frames === 0 &&
                    closed &&
                    (!REALTIME || openBefore.length > 0),
                `${JSON.stringify(after)}; widget WebSockets open before destroy=${openBefore.length} (${openBefore.map((socket) => socket.url().replace(/\?.*/, '')).join(', ')}), all closed after=${closed}`,
            ];
        });
        await ana.context().close();

        // 13. Hostile CSS of the host page.
        await step('hostile-host-css', 'host-hostile.html', async () => {
            const hostile = await hostPage(browser, 'hostil');

            try {
                await hostile.goto(hostUrl('host-hostile.html', data.shop.key));
                await hostile.waitForFunction(
                    () =>
                        (window.__events ?? []).some((e) => e.name === 'ready'),
                    null,
                    { timeout: 20_000 },
                );
                const state = await widgetState(hostile);
                const host = await hostile.evaluate(() => {
                    const element = document.querySelector(
                        'chat-manager-widget',
                    );
                    const style = getComputedStyle(element);

                    return {
                        display: style.display,
                        opacity: style.opacity,
                        z: style.zIndex,
                        transform: style.transform,
                    };
                });
                await shot(hostile, 'widget-hostile', { fullPage: false });

                return [
                    state.launcher.h === 56 &&
                        ['flex', 'inline-flex'].includes(
                            state.launcher.display,
                        ) &&
                        state.launcher.font === '15px' &&
                        host.display === 'block' &&
                        host.opacity === '1' &&
                        host.z === '2147483000',
                    `launcher ${JSON.stringify(state.launcher)}; host element ${JSON.stringify(host)}`,
                ];
            } finally {
                await hostile.context().close();
            }
        });

        // 14. The jQuery adapter.
        await step('jquery-adapter', 'host-jquery.html + stub', async () => {
            const page = await hostPage(browser, 'jquery');

            try {
                await page.goto(hostUrl('host-jquery.html', data.shop.key));
                await page.waitForFunction(
                    () =>
                        (window.__events ?? []).some((e) => e.name === 'ready'),
                    null,
                    { timeout: 20_000 },
                );
                const inBox = await page.evaluate(
                    () =>
                        document.querySelector('#box chat-manager-widget') !==
                        null,
                );
                const adapter = await page.evaluate(() => window.__adapter);
                await page.evaluate(() => $('#box').chatManager('open'));
                const opened = (await widgetState(page)).expanded;
                await page.evaluate(() => $('#box').chatManager('destroy'));
                const after = await page.evaluate(() => ({
                    nodes: document.querySelectorAll('chat-manager-widget')
                        .length,
                    listeners: window.__msgListeners,
                }));

                return [
                    adapter === true &&
                        inBox &&
                        opened === 'true' &&
                        after.nodes === 0 &&
                        after.listeners === 0,
                    `$.fn.chatManager registered=${adapter}; mounted in #box=${inBox}; open → aria-expanded=${opened}; after destroy ${JSON.stringify(after)}`,
                ];
            } finally {
                await page.context().close();
            }
        });

        // 14b. ChatManager('logout'): the site's user signed out, so the
        // session ends on the server, the token is dropped and the next
        // load of the page (same browser) does not show the thread.
        await step('logout-ends-session', "ChatManager('logout')", async () => {
            const page = await hostPage(browser, 'cierre');
            const tokenOf = (child) =>
                child.evaluate(
                    (key) => localStorage.getItem(`cm:v1:${key}`),
                    data.shop.key,
                );

            try {
                await page.goto(hostUrl('host-plain.html', data.shop.key));
                await page.waitForFunction(
                    () =>
                        (window.__events ?? []).some((e) => e.name === 'ready'),
                    null,
                    { timeout: 20_000 },
                );
                await page.evaluate(() => window.ChatManager('open'));
                let child = await readyFrame(page);
                const text = `Antes de cerrar sesión ${Date.now()}`;
                await child.fill('#cm-message', text);
                await child.press('#cm-message', 'Enter');
                await child.waitForSelector('[data-status="sent"]', {
                    timeout: 15_000,
                });
                const before = await tokenOf(child);
                const revokedBefore = seedCheck(data.shop.id).sessions.filter(
                    (session) => session.revoked,
                ).length;

                await page.evaluate(() => window.ChatManager('logout'));
                await expecting('a new session after logout', page, () =>
                    child.waitForFunction(
                        ({ body, key, token }) =>
                            !document.body.innerText.includes(body) &&
                            document.querySelector('[data-phase]')?.dataset
                                .phase === 'ready' &&
                            localStorage.getItem(`cm:v1:${key}`) !== token,
                        { body: text, key: data.shop.key, token: before },
                        { timeout: 15_000 },
                    ),
                );
                const after = await tokenOf(child);
                const revokedAfter = seedCheck(data.shop.id).sessions.filter(
                    (session) => session.revoked,
                ).length;

                await page.reload();
                await page.waitForFunction(
                    () =>
                        (window.__events ?? []).some((e) => e.name === 'ready'),
                    null,
                    { timeout: 20_000 },
                );
                await page.evaluate(() => window.ChatManager('open'));
                child = await readyFrame(page);
                await page.waitForTimeout(1_000);
                const shown = await child
                    .locator('li[data-direction]', { hasText: text })
                    .count();

                return [
                    before !== null &&
                        after !== before &&
                        revokedAfter === revokedBefore + 1 &&
                        shown === 0,
                    `token changed=${before !== null && after !== before}; revoked sessions ${revokedBefore} → ${revokedAfter}; previous message shown after reload=${shown}`,
                ];
            } finally {
                await page.context().close();
            }
        });

        // 14c. identity_mode = required on a shared browser: A writes; the
        // next load without identify shows no thread; B's identity gets a
        // new session without A's messages.
        await step(
            'required-identity-not-resumed',
            'identify A, reload, identify B',
            async () => {
                const page = await hostPage(browser, 'socios');
                const members = data.members;
                const phaseIs = (child, phase) =>
                    expecting(`phase "${phase}"`, page, () =>
                        child.waitForSelector(`[data-phase="${phase}"]`, {
                            timeout: 20_000,
                        }),
                    );
                const load = async () => {
                    await page.waitForFunction(
                        () =>
                            (window.__events ?? []).some(
                                (e) => e.name === 'ready',
                            ),
                        null,
                        { timeout: 20_000 },
                    );
                    await page.evaluate(() => window.ChatManager('open'));
                    await waitFor(
                        async () => widgetFrame(page) !== null,
                        15_000,
                    );

                    return widgetFrame(page);
                };
                const identify = (who) =>
                    page.evaluate(
                        (identity) => window.ChatManager('identify', identity),
                        signIdentity(members.key, members.secret, who, who),
                    );

                try {
                    await page.goto(hostUrl('host-plain.html', members.key));
                    let child = await load();
                    await phaseIs(child, 'identity');
                    await identify('socio-a');
                    await phaseIs(child, 'ready');
                    const text = `Mensaje privado de A ${Date.now()}`;
                    await child.fill('#cm-message', text);
                    await child.press('#cm-message', 'Enter');
                    await child.waitForSelector('[data-status="sent"]', {
                        timeout: 15_000,
                    });

                    await page.reload();
                    child = await load();
                    await phaseIs(child, 'identity');
                    await page.waitForTimeout(1_000);
                    const withoutIdentity = await child
                        .locator('li[data-direction]', { hasText: text })
                        .count();

                    await identify('socio-b');
                    await phaseIs(child, 'ready');
                    await page.waitForTimeout(1_000);
                    const asB = await child
                        .locator('li[data-direction]', { hasText: text })
                        .count();
                    const sessions = seedCheck(members.id).sessions;

                    return [
                        withoutIdentity === 0 &&
                            asB === 0 &&
                            sessions.length === 2 &&
                            sessions.filter((session) => session.revoked)
                                .length === 1,
                        `A's message without identify=${withoutIdentity}, as B=${asB}; sessions=${JSON.stringify(sessions)}`,
                    ];
                } finally {
                    await page.context().close();
                }
            },
        );

        // 15. Origins that are not allowed never mount nor start sessions.
        await step(
            'unauthorized-origins',
            'intruso.test + another widget',
            async () => {
                const intruder = await hostPage(browser, 'intruso', {
                    // The browser reports the refused frame (frame-ancestors).
                    ignoreConsole:
                        /frame-ancestors|Refused to frame|Content Security Policy|El chat no respondió/,
                });
                const otherBrand = await hostPage(browser, 'otra-marca', {
                    ignoreConsole:
                        /frame-ancestors|Refused to frame|Content Security Policy|El chat no respondió/,
                });

                try {
                    await intruder.goto(
                        hostUrl(
                            'host-intruder.html',
                            data.shop.key,
                            HOSTS.intruder,
                        ),
                    );
                    await otherBrand.goto(
                        hostUrl('host-plain.html', data.other.key),
                    );
                    // The loader gives up after 20 s without "ready".
                    const timedOut = await Promise.all(
                        [intruder, otherBrand].map((page) =>
                            page
                                .waitForFunction(
                                    () =>
                                        (window.__events ?? []).some(
                                            (e) =>
                                                e.name === 'error' &&
                                                e.payload?.code ===
                                                    'frame_timeout',
                                        ),
                                    null,
                                    { timeout: 25_000 },
                                )
                                .then(() => true)
                                .catch(() => false),
                        ),
                    );
                    const ready = await Promise.all(
                        [intruder, otherBrand].map(
                            async (page) =>
                                (await events(page, 'ready')).length,
                        ),
                    );
                    const mounted = await Promise.all(
                        [intruder, otherBrand].map(async (page) => {
                            const child = widgetFrame(page);

                            return child
                                ? child
                                      .evaluate(
                                          () =>
                                              document.querySelector(
                                                  '[data-phase]',
                                              ) !== null,
                                      )
                                      .catch(() => false)
                                : false;
                        }),
                    );
                    const sessionsOk = sessionAnswers.filter(
                        (answer) =>
                            ['intruso', 'otra-marca'].includes(answer.label) &&
                            answer.status >= 200 &&
                            answer.status < 300,
                    );
                    const otherSessions = seedCheck(data.other.id).sessions;

                    return [
                        timedOut.every(Boolean) &&
                            ready.every((count) => count === 0) &&
                            mounted.every((value) => value === false) &&
                            sessionsOk.length === 0 &&
                            otherSessions.length === 0,
                        `frame_timeout intruso/otra-marca=${timedOut.join('/')}; ready=${ready.join('/')}; frame UI rendered=${mounted.join('/')}; 2xx session answers=${sessionsOk.length}; sessions of the other widget=${otherSessions.length}`,
                    ];
                } finally {
                    await intruder.context().close();
                    await otherBrand.context().close();
                }
            },
        );

        // 16. Without jQuery, the shop's page has no console error at all.
        await step('no-jquery-no-errors', 'host-plain.html', async () => {
            const own = problems.console.filter((line) =>
                line.startsWith('[tienda] '),
            );
            const pageErrors = problems.pageerror.filter((line) =>
                line.startsWith('[tienda] '),
            );
            const jquery = await shop.evaluate(
                () => typeof window.jQuery === 'undefined',
            );

            return [
                own.length === 0 && pageErrors.length === 0 && jquery,
                `no jQuery on the page=${jquery}; console errors=${own.length}; page errors=${pageErrors.length} ${JSON.stringify([...own, ...pageErrors].slice(0, 3))}`,
            ];
        });
    } finally {
        stopChildren();
        await browser.close();
    }

    recordPageProblems(800);
    printProblems();
    exitWithSummary();
};

run().catch((error) => {
    console.error(error);
    stopChildren();
    process.exit(1);
});
