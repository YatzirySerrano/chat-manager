/*
 * Internal chat sending and resilience (2026-11-11). Ana (desktop, mouse) and
 * Beto (tablet 768 px, touch) of tests/e2e/increment-b-seed.php share the
 * group «E2E Envío» of tests/e2e/chat-interno-envio-seed.php (40 messages;
 * the newest quotes the first, which is not on the first page).
 *
 *   tc-realtime            Ana writes, Beto sees it live (WebSocket signal)
 *   tc-idempotent-retry    Ana's first send reaches the server but its
 *                          response is lost: the composer keeps the draft and
 *                          says it could not confirm; sending again answers
 *                          the stored message (200, replayed): one bubble for
 *                          Ana and for Beto
 *   tc-reconnect-refresh   Beto's WebSocket is cut; Ana deletes a message
 *                          Beto is looking at; when Beto reconnects the
 *                          bubble turns into «Mensaje eliminado» without
 *                          reloading
 *   tc-jump-keeps-query    jumping to the unloaded quote keeps the list's
 *                          search and filter in the URL
 *   tc-touch-tablet-768    on the touch tablet the message actions are
 *                          visible without hover; no horizontal scroll
 *   tc-touch-phone-390     on a 390 px phone the list's «Vista previa» is
 *                          visible and opens the preview; no horizontal scroll
 *   tc-mouse-hover         with a mouse the actions stay hidden until hover
 *
 *   E2E_REVERB=1 E2E_PORT=8140 PLAYWRIGHT_MODULE=… \
 *     php tests/e2e/run.php chat-interno-envio.mjs
 */
import { join } from 'node:path';
import {
    BASE,
    HERE,
    REVERB_PORT,
    USERS,
    exitWithSummary,
    launchBrowser,
    login,
    openPage,
    php,
    printProblems,
    recordPageProblems,
    shot,
    startWorker,
    step,
    stopChildren,
    waitFor,
} from './lib.mjs';

const COMPOSER = 'Escribe un mensaje interno…';
const UNCERTAIN = 'No se pudo confirmar si tu mensaje se envió';

const history = (page) =>
    page.locator('section[aria-label^="Chat: "] [role="log"]');
const bubbles = (page, text) =>
    history(page).locator('[id^="team-message-"]', { hasText: text });

async function send(page, text) {
    await page.getByPlaceholder(COMPOSER).click();
    await page.keyboard.type(text);
    await page.keyboard.press('Enter');
}

const opacityOf = (locator) =>
    locator.evaluate((element) => getComputedStyle(element).opacity);

const horizontalScroll = (page) =>
    page.evaluate(
        () =>
            document.scrollingElement.scrollWidth >
            document.documentElement.clientWidth + 1,
    );

const main = async () => {
    if (!REVERB_PORT) {
        console.error('Run with E2E_REVERB=1 through tests/e2e/run.php.');
        process.exit(2);
    }

    startWorker();
    const seeded = JSON.parse(
        php(join(HERE, 'chat-interno-envio-seed.php')).trim(),
    );
    const threadUrl = `${BASE}/team-chat/threads/${seeded.group}`;
    const browser = await launchBrowser();

    /*
     * Beto's WebSocket goes through a route the scenario controls: while
     * `betoOffline` is set, open sockets are closed and new ones refused, so
     * the signals of that time never reach him (as on a real outage).
     */
    let betoOffline = false;
    const betoSockets = new Set();

    try {
        const ana = await openPage(browser, {
            context: { viewport: { width: 1440, height: 900 } },
            prefix: '[ana] ',
            ignoreConsole: /status of (403|404|409|422|429)|ERR_FAILED/,
        });
        const beto = await openPage(browser, {
            context: {
                viewport: { width: 768, height: 1024 },
                hasTouch: true,
                isMobile: true,
            },
            prefix: '[beto] ',
            ignoreConsole:
                /status of (403|404|409|422|429)|WebSocket connection to|WebSocket is closed/,
        });

        await beto.context().routeWebSocket(
            (url) => Number(url.port) === REVERB_PORT,
            (socket) => {
                if (betoOffline) {
                    socket.close();

                    return;
                }

                socket.connectToServer();
                betoSockets.add(socket);
            },
        );

        await Promise.all([login(ana, USERS.ana), login(beto, USERS.beto)]);
        const query = '?filter=groups&search=Env';
        await Promise.all([
            ana.goto(`${threadUrl}${query}`),
            beto.goto(threadUrl),
        ]);
        await Promise.all([history(ana).waitFor(), history(beto).waitFor()]);
        // Both sessions subscribed to the thread channel.
        await ana.waitForTimeout(2_000);

        await step('tc-realtime', 'keyboard + WebSocket', async () => {
            const since = Date.now();
            await send(ana, 'Hola Beto, mensaje en vivo');
            await bubbles(beto, 'Hola Beto, mensaje en vivo').waitFor({
                timeout: 10_000,
            });
            const elapsed = Date.now() - since;

            return [elapsed < 8_000, `Beto saw it after ${elapsed} ms`];
        });

        await step('tc-idempotent-retry', 'lost response + Enter', async () => {
            const text = 'Mensaje con respuesta perdida';
            const postUrl = `/team-chat/threads/${seeded.group}/messages`;
            const statuses = [];
            let lost = false;

            await ana.route(
                (url) => url.pathname === postUrl,
                async (route) => {
                    if (route.request().method() !== 'POST') {
                        return route.fallback();
                    }

                    const response = await route.fetch();
                    statuses.push(response.status());

                    if (!lost) {
                        // Stored on the server; the browser never hears it.
                        lost = true;

                        return route.abort('failed');
                    }

                    const body = await response.json();
                    statuses.push(body.replayed);

                    return route.fulfill({ response });
                },
            );

            await send(ana, text);
            await ana.getByText(UNCERTAIN).waitFor();
            const draft = await ana.getByPlaceholder(COMPOSER).inputValue();
            await shot(ana, 'chat-interno-envio-uncertain');

            await ana.getByPlaceholder(COMPOSER).click();
            await ana.keyboard.press('Enter');
            await waitFor(
                async () =>
                    (await ana.getByPlaceholder(COMPOSER).inputValue()) === '',
                10_000,
            );
            await ana.unroute((url) => url.pathname === postUrl);
            await bubbles(beto, text).first().waitFor({ timeout: 10_000 });
            await ana.waitForTimeout(2_000);

            const anaCount = await bubbles(ana, text).count();
            const betoCount = await bubbles(beto, text).count();
            const errorGone = (await ana.getByText(UNCERTAIN).count()) === 0;

            return [
                draft === text &&
                    statuses[0] === 201 &&
                    statuses[1] === 200 &&
                    statuses[2] === true &&
                    anaCount === 1 &&
                    betoCount === 1 &&
                    errorGone,
                `draft kept="${draft}", statuses=${JSON.stringify(statuses)}, bubbles ana=${anaCount} beto=${betoCount}, error cleared=${errorGone}`,
            ];
        });

        await step('tc-reconnect-refresh', 'WebSocket cut', async () => {
            const text = 'Este mensaje se borrará sin conexión';
            await send(ana, text);
            await bubbles(beto, text).waitFor({ timeout: 10_000 });

            betoOffline = true;

            for (const socket of betoSockets) {
                await socket.close().catch(() => {});
            }

            betoSockets.clear();
            await beto.waitForTimeout(1_000);

            // By id: once deleted, the bubble no longer contains its text.
            const ownId = await bubbles(ana, text).getAttribute('id');
            const own = ana.locator(`#${ownId}`);
            await own.hover();
            await own
                .getByRole('button', { name: 'Acciones del mensaje' })
                .click();
            await ana.getByRole('menuitem', { name: 'Eliminar' }).click();
            await ana.getByRole('button', { name: 'Eliminar mensaje' }).click();
            await own.getByText('Mensaje eliminado').waitFor();

            // Offline: the deletion's signal never reached Beto.
            await beto.waitForTimeout(2_000);
            const staleWhileOffline = (await bubbles(beto, text).count()) === 1;

            const since = Date.now();
            betoOffline = false;
            const betoBubble = beto.locator(`#${ownId}`);
            await betoBubble
                .getByText('Mensaje eliminado')
                .waitFor({ timeout: 30_000 });
            const elapsed = Date.now() - since;
            const textGone = (await bubbles(beto, text).count()) === 0;
            await shot(beto, 'chat-interno-envio-reconnected');

            return [
                staleWhileOffline && textGone,
                `stale while offline=${staleWhileOffline}; «Mensaje eliminado» ${elapsed} ms after the connection came back, text gone=${textGone}`,
            ];
        });

        await step('tc-jump-keeps-query', 'quote click', async () => {
            await history(ana)
                .getByRole('button', { name: /Primer mensaje citado/ })
                .first()
                .click();
            await ana.waitForURL(/around=/);
            const url = new URL(ana.url());
            await bubbles(ana, 'Primer mensaje citado más tarde')
                .first()
                .waitFor();

            return [
                url.searchParams.get('filter') === 'groups' &&
                    url.searchParams.get('search') === 'Env' &&
                    url.searchParams.get('around') === String(seeded.quoted),
                `URL after the jump: ${url.search}`,
            ];
        });

        await step('tc-touch-tablet-768', 'hasTouch, no hover', async () => {
            await beto.mouse.move(0, 0);
            const bubble = bubbles(beto, 'Hola Beto, mensaje en vivo');
            const opacities = await Promise.all([
                opacityOf(bubble.getByRole('button', { name: 'Reaccionar' })),
                opacityOf(
                    bubble.getByRole('button', {
                        name: 'Acciones del mensaje',
                    }),
                ),
            ]);
            await bubble.getByRole('button', { name: 'Reaccionar' }).tap();
            const reactions = await beto
                .getByRole('button', { name: 'Reaccionar con 👍' })
                .isVisible();
            await beto.keyboard.press('Escape');
            const scroll = await horizontalScroll(beto);
            await shot(beto, 'chat-interno-envio-tablet-768');

            return [
                opacities.every((value) => value === '1') &&
                    reactions &&
                    !scroll,
                `opacities=${JSON.stringify(opacities)}, picker opened by tap=${reactions}, horizontal scroll=${scroll}`,
            ];
        });

        await step('tc-touch-phone-390', 'hasTouch 390 px', async () => {
            const phone = await openPage(browser, {
                context: {
                    viewport: { width: 390, height: 844 },
                    hasTouch: true,
                    isMobile: true,
                },
                prefix: '[beto-phone] ',
            });
            await login(phone, USERS.beto);
            await phone.goto(`${BASE}/team-chat`);
            const preview = phone.locator('[data-thread-preview]').first();
            await preview.waitFor();
            const opacity = await opacityOf(preview);
            await preview.tap();
            const dialog = await phone
                .getByRole('dialog')
                .first()
                .waitFor({ timeout: 5_000 })
                .then(() => true)
                .catch(() => false);
            await shot(phone, 'chat-interno-envio-phone-390');
            await phone.keyboard.press('Escape');
            const scroll = await horizontalScroll(phone);

            await phone.goto(threadUrl);
            await history(phone).waitFor();
            const actions = await opacityOf(
                bubbles(phone, 'Hola Beto, mensaje en vivo').getByRole(
                    'button',
                    { name: 'Acciones del mensaje' },
                ),
            ).catch(() => 'missing');
            const threadScroll = await horizontalScroll(phone);

            return [
                opacity === '1' &&
                    dialog &&
                    !scroll &&
                    actions === '1' &&
                    !threadScroll,
                `preview button opacity=${opacity}, preview opened=${dialog}, list scroll=${scroll}; thread actions opacity=${actions}, thread scroll=${threadScroll}`,
            ];
        });

        await step('tc-mouse-hover', 'desktop mouse', async () => {
            await ana.goto(threadUrl);
            await history(ana).waitFor();
            const bubble = bubbles(ana, 'Hola Beto, mensaje en vivo');
            const button = bubble.getByRole('button', {
                name: 'Acciones del mensaje',
            });
            await ana.mouse.move(5, 5);
            await ana.waitForTimeout(300);
            const hidden = await opacityOf(button);
            await bubble.hover();
            await ana.waitForTimeout(300);
            const shown = await opacityOf(button);

            return [
                hidden === '0' && shown === '1',
                `without hover=${hidden}, on hover=${shown}`,
            ];
        });
    } finally {
        await browser.close();
        stopChildren();
    }

    printProblems();
    recordPageProblems();
    exitWithSummary();
};

main().catch((error) => {
    console.error(error);
    stopChildren();
    process.exit(1);
});
