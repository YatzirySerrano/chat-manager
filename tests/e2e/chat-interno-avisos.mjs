/*
 * Internal chat alerts in real browsers (Fase 5.2.1): when a colleague
 * writes, the recipient gets the chat updated live, the unread counter, a
 * toast with the sender, a safe preview, «Abrir» and a close button, the
 * notification in the bell and a soft sound when the preference is on.
 * No toast nor sound for the chat open and visible (in this tab or in
 * another tab of the same browser), for one's own messages, or twice for
 * the same message; a single sound after a reconnection.
 *
 *   E2E_REVERB=1 WHATSAPP_TRANSPORT=simulated \
 *     php tests/e2e/run.php chat-interno-avisos.mjs
 *
 * Real Reverb, a real queue worker (started here), real mouse and keyboard.
 * The sound is counted by spying the Web Audio oscillators (lib.mjs
 * installAlertSpy): one chime is the tones created within 100 ms; no real
 * audio output is needed.
 */
import { join } from 'node:path';
import {
    BASE,
    HERE,
    LIVE_BUDGET_MS,
    USERS,
    alertCounts,
    downIndicator,
    frameSince,
    installAlertSpy,
    launchBrowser,
    liveIndicator,
    login as signIn,
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
    toastsSince,
    waitFor,
} from './lib.mjs';

const SEED = join(HERE, 'chat-interno-avisos-seed.php');
const sessions = [];

const step = (id, scenario) =>
    runStep(id, null, scenario, () =>
        Promise.all(
            sessions
                .filter((page) => !page.isClosed())
                .map((page) => shot(page, `${id}-${page.session}`)),
        ),
    );

const shot = (page, name) =>
    saveShot(page, `chat-interno-avisos-${name}`, { fullPage: false });

const composer = (page) => page.getByPlaceholder('Escribe un mensaje interno…');
const chatLog = (page) =>
    page.locator('section[aria-label^="Chat: "] [role="log"]');
const toastWith = (page, text) =>
    page.locator('[data-sonner-toast]', { hasText: text });
const sidebarBadge = (page, count) =>
    page.locator(`[data-sidebar="menu-badge"][aria-label="${count} sin leer"]`);

async function session(browser, name, options = {}) {
    const page = await openRealtimeSession(browser, name, {
        trackAlerts: true,
        trackToasts: true,
        ...options,
    });
    sessions.push(page);

    return page;
}

/** A second tab of the same browser profile (shares its storage). */
async function secondTab(page, name) {
    const tab = await page.context().newPage();
    await tab.addInitScript(installAlertSpy);
    await tab.addInitScript(() => {
        window.__toastLog = [];
        const seen = new WeakSet();
        new MutationObserver(() => {
            for (const toast of document.querySelectorAll(
                '[data-sonner-toast]',
            )) {
                const text = toast.textContent.trim();

                if (text && !seen.has(toast)) {
                    seen.add(toast);
                    window.__toastLog.push({ t: Date.now(), text });
                }
            }
        }).observe(document, { childList: true, subtree: true });
    });
    tab.session = name;
    tab.frames$ = [];
    tab.on('websocket', (socket) => {
        socket.on('framereceived', ({ payload }) => {
            try {
                const frame = JSON.parse(String(payload));
                tab.frames$.push({ t: Date.now(), event: frame.event });
            } catch {
                // Not JSON.
            }
        });
    });
    sessions.push(tab);

    return tab;
}

/**
 * A full load resets the page's user activation, which browsers require
 * before playing audio: a click on an empty spot of the header activates
 * it again, like the user's first click would.
 */
async function goto(page, path) {
    await page.goto(`${BASE}${path}`);
    await liveIndicator(page).waitFor({ timeout: 15_000 });
    const box = await page.locator('header').first().boundingBox();

    if (box) {
        await page.mouse.click(
            box.x + box.width * 0.55,
            box.y + box.height / 2,
        );
    }
}

async function send(page, text) {
    await composer(page).click();
    await page.keyboard.type(text);
    await page.keyboard.press('Enter');
    await chatLog(page).getByText(text, { exact: true }).waitFor();
}

const chimes = async (page) => (await alertCounts(page)).chimes;

async function bellCount(page) {
    const label = await page
        .getByRole('button', { name: /^Notificaciones/ })
        .first()
        .getAttribute('aria-label');
    const match = /(\d+) sin leer/.exec(label ?? '');

    return match ? Number(match[1]) : 0;
}

/** Waits for the `.notification` signal (or `timeout`) and then `settleMs`. */
async function settled(
    page,
    since,
    settleMs = 2_500,
    timeout = LIVE_BUDGET_MS,
) {
    const signalled = await waitFor(
        () => frameSince(page, 'notification', since) !== undefined,
        timeout,
        100,
    );
    await page.waitForTimeout(settleMs);

    return signalled;
}

const main = async () => {
    requireRealtimeEnvironment();
    const worker = startWorker();
    const threads = JSON.parse(php(SEED));
    const browser = await launchBrowser();

    try {
        const ana = await session(browser, 'ana');
        const beto = await session(browser, 'beto');

        await Promise.all([
            signIn(ana, USERS.ana, { settle: false }),
            signIn(beto, USERS.beto, { settle: false }),
        ]);
        await Promise.all([
            goto(ana, `/team-chat/threads/${threads.direct}`),
            goto(beto, '/conversations'),
        ]);

        // 1. B in another module: toast with sender and preview, one soft
        // sound, the bell and the sidebar counter go up; X closes the toast
        // and the notification stays in the bell.
        await step('aviso-otro-modulo', async () => {
            const bellBefore = await bellCount(beto);
            const chimesBefore = await chimes(beto);
            const anaChimes = await chimes(ana);
            const since = Date.now();
            await send(ana, 'Hola Beto, ¿revisas el pedido 1042?');
            const toast = toastWith(beto, 'Mensaje nuevo de E2E Ana');
            await toast.waitFor({ timeout: LIVE_BUDGET_MS + 4_000 });
            const elapsed = Date.now() - since;
            const text = (await toast.innerText()).replace(/\s+/g, ' ');
            const hasOpen = await toast
                .getByRole('button', { name: 'Abrir' })
                .count();
            await sidebarBadge(beto, 1).waitFor({ timeout: LIVE_BUDGET_MS });
            await beto.waitForTimeout(1_500);
            const sounds = (await chimes(beto)) - chimesBefore;
            const bell = await bellCount(beto);
            await shot(beto, 'toast-1440');
            await toast
                .getByRole('button', { name: 'Cerrar notificación' })
                .click();
            await toast.waitFor({ state: 'detached', timeout: 5_000 });
            const bellAfterClose = await bellCount(beto);
            const ownSounds = (await chimes(ana)) - anaChimes;

            return [
                elapsed <= LIVE_BUDGET_MS &&
                    text.includes('¿revisas el pedido 1042?') &&
                    hasOpen === 1 &&
                    sounds === 1 &&
                    bell === bellBefore + 1 &&
                    bellAfterClose === bell &&
                    ownSounds === 0,
                `toast after ${elapsed} ms: "${text}"; «Abrir» ${hasOpen}; chimes ${sounds}; bell ${bellBefore}→${bell} (after X: ${bellAfterClose}); sidebar "1 sin leer"; sender's own chimes ${ownSounds}`,
            ];
        });

        // 2. A second message replaces the toast (folded count); «Abrir»
        // opens the chat and its notification is read.
        await step('aviso-abrir', async () => {
            const since = Date.now();
            await send(ana, 'Es urgente, gracias');
            const toast = toastWith(beto, '2 mensajes nuevos de E2E Ana');
            await toast.waitFor({ timeout: LIVE_BUDGET_MS + 4_000 });
            const toasts = await beto.locator('[data-sonner-toast]').count();
            const bellBefore = await bellCount(beto);
            await toast.getByRole('button', { name: 'Abrir' }).click();
            await beto.waitForURL(
                new RegExp(`/team-chat/threads/${threads.direct}`),
            );
            await chatLog(beto)
                .getByText('Es urgente, gracias', { exact: true })
                .waitFor();
            const read = await waitFor(
                async () => (await bellCount(beto)) === bellBefore - 1,
                LIVE_BUDGET_MS,
            );
            const badgeGone = await waitFor(
                async () => (await sidebarBadge(beto, 1).count()) === 0,
                LIVE_BUDGET_MS,
            );

            return [
                toasts === 1 && read && badgeGone,
                `${Date.now() - since} ms; toasts on screen: ${toasts}; chat opened; its notification read (bell ${bellBefore}→${await bellCount(beto)}): ${read}; sidebar badge gone: ${badgeGone}`,
            ];
        });

        // 3. The chat open and visible: the message appears, no toast, no sound.
        await step('hilo-abierto-sin-aviso', async () => {
            const chimesBefore = await chimes(beto);
            const since = Date.now();
            await send(ana, 'Te dejo el folio aquí mismo');
            await chatLog(beto)
                .getByText('Te dejo el folio aquí mismo', { exact: true })
                .waitFor({ timeout: LIVE_BUDGET_MS });
            const shownMs = Date.now() - since;
            await beto.waitForTimeout(4_000);
            const toasts = await toastsSince(beto, since);
            const sounds = (await chimes(beto)) - chimesBefore;
            const visibility = await beto.evaluate(
                () => document.visibilityState,
            );

            return [
                visibility === 'visible' && toasts.length === 0 && sounds === 0,
                `message shown after ${shownMs} ms (${visibility}); toasts ${toasts.length}; chimes ${sounds}; bell ${await bellCount(beto)}`,
            ];
        });

        // 3b. The chat open in a window that is visible but not focused (next
        // to another app): the message sounds and stays unread, without a
        // toast (the chat is on screen); it is read once the window gets the
        // focus back. Focus loss is simulated (hasFocus + blur).
        await step('hilo-visible-sin-foco', async () => {
            await beto.evaluate(() => {
                document.hasFocus = () => false;
                window.dispatchEvent(new Event('blur'));
            });
            const chimesBefore = await chimes(beto);
            const bellBefore = await bellCount(beto);
            const since = Date.now();
            await send(ana, 'Mientras atiendes otra ventana');
            await chatLog(beto)
                .getByText('Mientras atiendes otra ventana', { exact: true })
                .waitFor({ timeout: LIVE_BUDGET_MS });
            await settled(beto, since);
            const sounds = (await chimes(beto)) - chimesBefore;
            const toasts = await toastsSince(beto, since, /otra ventana/);
            const bellUnfocused = await bellCount(beto);
            await beto.evaluate(() => {
                delete document.hasFocus;
                window.dispatchEvent(new Event('focus'));
            });
            const readBack = await waitFor(
                async () => (await bellCount(beto)) === bellBefore,
                LIVE_BUDGET_MS,
            );

            return [
                sounds === 1 &&
                    toasts.length === 0 &&
                    bellUnfocused === bellBefore + 1 &&
                    readBack,
                `chimes ${sounds}; toasts ${toasts.length}; bell ${bellBefore}→${bellUnfocused} while unfocused, back to ${bellBefore} after focus: ${readBack}`,
            ];
        });

        // 4. Another chat open: the message of the first one is announced.
        await step('otro-hilo-con-aviso', async () => {
            await goto(beto, `/team-chat/threads/${threads.group}`);
            const chimesBefore = await chimes(beto);
            const since = Date.now();
            await send(ana, 'Cuando puedas, revisa el privado');
            const toast = toastWith(beto, 'Mensaje nuevo de E2E Ana');
            const shown = await toast
                .waitFor({ timeout: LIVE_BUDGET_MS + 4_000 })
                .then(
                    () => true,
                    () => false,
                );
            await beto.waitForTimeout(1_500);
            const sounds = (await chimes(beto)) - chimesBefore;
            const text = shown
                ? (await toast.innerText()).replace(/\s+/g, ' ')
                : '';

            return [
                shown && text.includes('revisa el privado') && sounds === 1,
                `${Date.now() - since} ms; toast "${text}"; chimes ${sounds}`,
            ];
        });

        // 5. One's own message never sounds nor announces.
        await step('mensaje-propio-sin-sonido', async () => {
            const chimesBefore = await chimes(beto);
            const since = Date.now();
            await send(beto, 'Ya voy con eso');
            await beto.waitForTimeout(4_000);
            const toasts = (await toastsSince(beto, since)).filter((entry) =>
                /mensaje|E2E Beto/i.test(entry.text),
            );
            const sounds = (await chimes(beto)) - chimesBefore;

            return [
                toasts.length === 0 && sounds === 0,
                `toasts ${toasts.length}; chimes ${sounds}`,
            ];
        });

        // 6. Sound off in the preferences: the toast still shows, silently.
        await step('sonido-desactivado', async () => {
            await goto(beto, '/settings/notifications');
            const toggle = beto.getByRole('switch', {
                name: 'Mensajes del chat interno: sonido',
            });
            const wasOn =
                (await toggle.getAttribute('aria-checked')) === 'true';
            await toggle.click();
            await beto
                .getByRole('button', { name: 'Guardar preferencias' })
                .click();
            await beto.waitForTimeout(1_000);
            await goto(beto, '/conversations');
            const chimesBefore = await chimes(beto);
            const since = Date.now();
            await send(ana, 'Aviso sin sonido');
            const shown = await toastWith(beto, 'Aviso sin sonido')
                .waitFor({ timeout: LIVE_BUDGET_MS + 4_000 })
                .then(
                    () => true,
                    () => false,
                );
            await beto.waitForTimeout(1_500);
            const silent = (await chimes(beto)) - chimesBefore;

            await goto(beto, '/settings/notifications');
            await toggle.click();
            await beto
                .getByRole('button', { name: 'Guardar preferencias' })
                .click();
            await beto.waitForTimeout(1_000);
            await goto(beto, '/conversations');
            const chimesOn = await chimes(beto);
            await send(ana, 'Aviso con sonido otra vez');
            await toastWith(beto, 'Aviso con sonido otra vez')
                .waitFor({ timeout: LIVE_BUDGET_MS + 4_000 })
                .catch(() => {});
            await beto.waitForTimeout(1_500);
            const loud = (await chimes(beto)) - chimesOn;

            return [
                wasOn && shown && silent === 0 && loud === 1,
                `default on: ${wasOn}; off → toast ${shown}, chimes ${silent} (${Date.now() - since} ms); on again → chimes ${loud}`,
            ];
        });

        // 7. Two tabs of the same browser: one sound in total; the chat open
        // in one tab silences the other one too.
        const betoTab = await secondTab(beto, 'beto-tab2');

        // 7a. A tab just opened (no click yet: its audio is blocked) never
        // takes the sound away from the tab in use; it says, once, how to
        // enable it.
        await betoTab.goto(`${BASE}/conversations`);
        await liveIndicator(betoTab).waitFor({ timeout: 15_000 });

        await step('pestana-sin-gesto-no-roba-el-sonido', async () => {
            // Not checked with page.evaluate: Playwright evaluates with a
            // user gesture, which would unlock the tab. The audio context is
            // only created by real interactions, so the hint below proves
            // the tab's sound was still blocked.
            const usedBefore = await chimes(beto);
            const newBefore = await chimes(betoTab);
            const since = Date.now();
            await send(ana, 'Mensaje con una pestaña sin clic');
            await toastWith(beto, 'Mensaje con una pestaña sin clic')
                .waitFor({ timeout: LIVE_BUDGET_MS + 4_000 })
                .catch(() => {});
            await beto.waitForTimeout(2_000);
            const used = (await chimes(beto)) - usedBefore;
            const fresh = (await chimes(betoTab)) - newBefore;
            const hint = await toastsSince(
                betoTab,
                since,
                /Sonido de avisos en pausa/,
            );

            return [
                used === 1 && fresh === 0 && hint.length === 1,
                `new tab without a click; chimes in the tab in use ${used}, in the new tab ${fresh}; "sonido en pausa" hint in the new tab ${hint.length}`,
            ];
        });

        await goto(betoTab, '/conversations');

        await step('dos-pestanas-un-sonido', async () => {
            const before = (await chimes(beto)) + (await chimes(betoTab));
            const since = Date.now();
            await send(ana, 'Mensaje para las dos pestañas');
            const both = await Promise.all(
                [beto, betoTab].map((page) =>
                    toastWith(page, 'Mensaje para las dos pestañas')
                        .waitFor({ timeout: LIVE_BUDGET_MS + 4_000 })
                        .then(
                            () => true,
                            () => false,
                        ),
                ),
            );
            await beto.waitForTimeout(2_000);
            const sounds =
                (await chimes(beto)) + (await chimes(betoTab)) - before;

            return [
                both.every(Boolean) && sounds === 1,
                `toast in both tabs: ${both.join('/')}; chimes in total ${sounds} (${Date.now() - since} ms)`,
            ];
        });

        await step('dos-pestanas-hilo-abierto-en-otra', async () => {
            await goto(betoTab, `/team-chat/threads/${threads.direct}`);
            const before = (await chimes(beto)) + (await chimes(betoTab));
            const since = Date.now();
            await send(ana, 'Lo ves en la otra pestaña');
            await chatLog(betoTab)
                .getByText('Lo ves en la otra pestaña', { exact: true })
                .waitFor({ timeout: LIVE_BUDGET_MS });
            await beto.waitForTimeout(4_000);
            const toasts = [
                ...(await toastsSince(beto, since, /otra pestaña/)),
                ...(await toastsSince(betoTab, since, /otra pestaña/)),
            ];
            const sounds =
                (await chimes(beto)) + (await chimes(betoTab)) - before;

            return [
                toasts.length === 0 && sounds === 0,
                `message shown in tab 2; toasts in either tab ${toasts.length}; chimes ${sounds}`,
            ];
        });

        // 8. Reconnection: what arrived while the WebSocket was down is
        // recovered with a single sound, and the sidebar counter (another
        // module) catches up without navigating.
        await goto(beto, `/team-chat/threads/${threads.group}`);
        await goto(beto, '/conversations');
        await goto(betoTab, '/conversations');

        await step('reconexion-un-sonido', async () => {
            const badgeBefore = await beto
                .locator('[data-sidebar="menu-badge"]')
                .count();
            const before = (await chimes(beto)) + (await chimes(betoTab));
            await stopReverb();
            await Promise.all([
                downIndicator(beto).waitFor({ timeout: 30_000 }),
                downIndicator(betoTab).waitFor({ timeout: 30_000 }),
            ]);
            await send(ana, 'Durante la caída uno');
            await send(ana, 'Durante la caída dos');
            // Reverb is down: no live indicator to wait for.
            await ana.goto(`${BASE}/team-chat/threads/${threads.group}`);
            await composer(ana).waitFor();
            await send(ana, 'Durante la caída en el grupo');
            // The worker stores the notifications (their hints fail).
            await beto.waitForTimeout(5_000);
            const silentDown =
                (await chimes(beto)) + (await chimes(betoTab)) - before;
            const since = Date.now();
            await startReverb();
            await Promise.all([
                liveIndicator(beto).waitFor({ timeout: 60_000 }),
                liveIndicator(betoTab).waitFor({ timeout: 60_000 }),
            ]);
            const reconnectedMs = Date.now() - since;
            const badge = await sidebarBadge(beto, 3)
                .waitFor({ timeout: LIVE_BUDGET_MS })
                .then(
                    () => Date.now() - since,
                    () => null,
                );
            await beto.waitForTimeout(3_000);
            const sounds =
                (await chimes(beto)) + (await chimes(betoTab)) - before;
            const toasts = await toastsSince(beto, since, /caída/);

            return [
                badgeBefore === 0 &&
                    silentDown === 0 &&
                    badge !== null &&
                    sounds === 1 &&
                    toasts.length >= 1,
                `badge before ${badgeBefore}; chimes while down ${silentDown}; reconnected after ${reconnectedMs} ms; sidebar "3 sin leer" ${badge === null ? 'not shown' : `after ${badge} ms`}; chimes after reconnecting ${sounds}; toasts ${toasts.length}`,
            ];
        });

        await betoTab.close();
        await goto(ana, `/team-chat/threads/${threads.direct}`);

        // 9. The toast at phone and desktop widths, light and dark.
        const state = await beto.context().storageState();

        for (const scheme of ['light', 'dark']) {
            for (const width of [390, 1280]) {
                await step(`toast-${scheme}-${width}`, async () => {
                    const phone = width <= 430;
                    const page = await session(
                        browser,
                        `beto-${scheme}-${width}`,
                        {
                            context: {
                                viewport: { width, height: phone ? 844 : 900 },
                                colorScheme: scheme,
                                hasTouch: phone,
                                isMobile: phone,
                                storageState: state,
                            },
                        },
                    );

                    try {
                        await goto(page, '/dashboard');
                        const text = `Vista ${scheme} a ${width} px`;
                        await send(ana, text);
                        const toast = toastWith(page, text);
                        await toast.waitFor({
                            timeout: LIVE_BUDGET_MS + 4_000,
                        });
                        await page.waitForTimeout(600);
                        const box = await toast.boundingBox();
                        const close = await toast
                            .getByRole('button', {
                                name: 'Cerrar notificación',
                            })
                            .boundingBox();
                        const open = await toast
                            .getByRole('button', { name: 'Abrir' })
                            .boundingBox();
                        const overflow = await page.evaluate(
                            () =>
                                document.documentElement.scrollWidth -
                                document.documentElement.clientWidth,
                        );
                        const dark = await page.evaluate(() =>
                            document.documentElement.classList.contains('dark'),
                        );
                        await shot(page, `toast-${scheme}-${width}`);
                        const inside =
                            box !== null &&
                            box.x >= 0 &&
                            box.x + box.width <= width + 1;
                        const tappable =
                            close !== null &&
                            open !== null &&
                            close.width >= 20 &&
                            open.height >= 24;

                        return [
                            inside &&
                                tappable &&
                                overflow <= 0 &&
                                dark === (scheme === 'dark'),
                            `toast ${box ? `${Math.round(box.x)}+${Math.round(box.width)}px` : 'missing'} in ${width}px; close ${close ? `${Math.round(close.width)}px` : 'missing'}, «Abrir» ${open ? `${Math.round(open.height)}px high` : 'missing'}; page overflow ${overflow}; dark ${dark}`,
                        ];
                    } finally {
                        await page.context().close();
                    }
                });
            }
        }

        record(
            'worker-running',
            null,
            worker.exitCode === null,
            `queue worker ${worker.exitCode === null ? 'still running' : `exited ${worker.exitCode}`}`,
        );
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
