/*
 * Chat previews, the control of new conversations and attention alerts, on
 * top of tests/e2e/atencion-seed.php (E2E Ana blocked by a customer waiting
 * 30 minutes, a queued conversation, an internal chat with two unread
 * messages from Beto).
 *
 *   preview-right-click     right click on an inbox row opens the preview
 *                           with the latest messages, without opening the chat
 *   preview-keeps-unread    closing it, the row keeps its unread counter (also
 *                           after reloading) and no read request was sent
 *   accept-blocked          the notice explains the block and «Aceptar» of
 *                           the queued row is disabled
 *   notice-opens-chat       the notice opens the waiting chat, which is read
 *                           as before (the counter clears)
 *   preview-long-press      on a phone (touch), holding a row opens the
 *                           preview and does not open the chat
 *   team-preview            the same in the internal chat: preview, counter
 *                           kept, no read request
 *   alert-send              the administrator sees Ana blocked in
 *                           «Seguimiento de atención» and sends her an urgent
 *                           alert from the composer
 *   alert-received          Ana finds it pending after loading a page (no
 *                           real-time in this run) and acknowledges it
 *   alert-history           the history shows it shown and confirmed
 *   preview-appearance      the preview paints the chat with E2E Ana's own
 *                           colors of that conversation (pink)
 *   composer-sound          choosing a sound in the composer plays it at once,
 *                           the next choice cuts the previous one, «Sin
 *                           sonido» plays nothing, and nothing is sent
 *   composer-everyone       «Todos» lists by name exactly the agents of the
 *                           table; a rose background is previewed
 *   alert-live-sound        (with Reverb) a page whose only interaction was
 *                           the login form sounds a live alert without any
 *                           further click (the cause of the silent alerts)
 *   alert-color-contrast    the received alert has the chosen background and
 *                           readable text, light and dark
 *   alert-blocked-sound     a freshly opened page (no interaction: audio
 *                           blocked) shows the alert with «Activar sonido»;
 *                           while hidden it is not counted as shown, once
 *                           visible it is; the button plays its sound
 *   bell-alert              (with Reverb) a new alert reaches two tabs of
 *                           E2E Ana at once: floating card and bell entry,
 *                           badge +1, one sound in total; opening the bell
 *                           or expanding the message acknowledges nothing;
 *                           «Entendido» in the bell clears the card, the
 *                           badge and the other tab, and it never returns
 *   bell-offline            an alert sent while E2E Ana is signed out is in
 *                           the bell when she signs in again, on a phone in
 *                           dark mode (fits the screen, readable); the
 *                           floating «Entendido» clears the bell too
 *   dashboard-permission    without «Ver el panel principal» the menu has no
 *                           «Panel principal» (expanded, collapsed, phone)
 *                           and /dashboard leads to the conversations
 *   layout-{width}-{scheme} «Seguimiento de atención» at 390 and 1280 px,
 *                           light and dark: no horizontal overflow
 *
 * SIMULACIÓN: nothing is sent to Meta.
 *
 *   E2E_REVERB=1 E2E_PORT=8166 TEST_DB_LOCK_WAIT=3600 php tests/e2e/run.php atencion.mjs
 */
import { join } from 'node:path';
import {
    BASE,
    HERE,
    REVERB_PORT,
    USERS,
    alertCounts,
    exitWithSummary,
    installAlertSpy,
    launchBrowser,
    login,
    openPage,
    php,
    printProblems,
    recordPageProblems,
    shot,
    step,
    toastText,
    watchProblems,
} from './lib.mjs';

const data = (() => {
    try {
        return JSON.parse(php(join(HERE, 'atencion-seed.php'), 'prepare'));
    } catch (error) {
        console.error(String(error.stderr ?? error.stdout ?? error));
        process.exit(1);
    }
})();

/** Read requests (conversations and internal chat) sent by the page. */
function trackReads(page) {
    const reads = [];
    page.on('request', (request) => {
        if (
            request.method() === 'POST' &&
            new URL(request.url()).pathname.endsWith('/read')
        ) {
            reads.push(request.url());
        }
    });

    return reads;
}

const row = (page, text) =>
    page.locator('[data-inbox-item]', { hasText: text });

async function rowLabel(page, text) {
    return (await row(page, text).getAttribute('aria-label')) ?? '';
}

/** Counts oscillator stop() calls: a cut sound stops its tones early. */
function installStopSpy() {
    window.__stops = 0;
    // oxlint-disable-next-line typescript/unbound-method -- re-bound with .apply
    const stop = OscillatorNode.prototype.stop;
    OscillatorNode.prototype.stop = function (...args) {
        window.__stops++;

        return stop.apply(this, args);
    };
}

/** WCAG contrast ratio of two CSS rgb() colors. */
function contrastIn(page, selector) {
    return page.evaluate((sel) => {
        const element = document.querySelector(sel);
        const style = getComputedStyle(element);
        const parse = (value) => {
            const probe = document.createElement('span');
            probe.style.color = value;
            document.body.append(probe);
            const rgb = getComputedStyle(probe).color;
            probe.remove();
            const canvas = document.createElement('canvas');
            canvas.width = canvas.height = 1;
            const context = canvas.getContext('2d');
            context.fillStyle = rgb;
            context.fillRect(0, 0, 1, 1);

            return [...context.getImageData(0, 0, 1, 1).data].slice(0, 3);
        };
        const luminance = ([r, g, b]) =>
            [r, g, b]
                .map((c) => c / 255)
                .map((c) =>
                    c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4,
                )
                .reduce(
                    (sum, c, index) =>
                        sum + c * [0.2126, 0.7152, 0.0722][index],
                    0,
                );
        const bg = parse(style.backgroundColor);
        const fg = parse(style.color);
        const [light, dark] = [luminance(bg), luminance(fg)].sort(
            (a, b) => b - a,
        );

        return {
            ratio: Math.round(((light + 0.05) / (dark + 0.05)) * 100) / 100,
            bg: bg.join(','),
            fg: fg.join(','),
        };
    }, selector);
}

/**
 * Init script: navigator.userActivation as a browser that blocks audio
 * after opening the page, until a real (trusted) click or key on it.
 * Playwright keeps the activation across goto(), so a freshly opened page
 * would otherwise never be "blocked".
 */
function installStrictActivation() {
    let active = false;
    const activate = (event) => {
        if (event.isTrusted) {
            active = true;
        }
    };

    for (const type of ['pointerdown', 'keydown', 'click', 'touchend']) {
        window.addEventListener(type, activate, { capture: true });
    }

    Object.defineProperty(navigator, 'userActivation', {
        configurable: true,
        get: () => ({ hasBeenActive: active, isActive: active }),
    });
}

/** Whether two lists hold the same names, in any order. */
function sameNames(first, second) {
    const sorted = (list) =>
        [...list].sort((a, b) => a.localeCompare(b, 'es')).join('|');

    return sorted(first) === sorted(second);
}

/** Simulates the tab going to the background or coming back. */
function setVisibility(page, state) {
    return page.evaluate((value) => {
        Object.defineProperty(document, 'visibilityState', {
            configurable: true,
            get: () => value,
        });
        Object.defineProperty(document, 'hidden', {
            configurable: true,
            get: () => value === 'hidden',
        });
        document.dispatchEvent(new Event('visibilitychange'));
    }, state);
}

/** Number on the bell (0 without a badge). */
async function bellBadge(page) {
    const badge = page.locator('[data-notification-badge]');

    return (await badge.count()) ? Number((await badge.innerText()).trim()) : 0;
}

function bellButton(page) {
    return page.getByRole('button', { name: /^Notificaciones/ }).first();
}

/** The person's pending alerts as the server has them. */
function serverAlerts(page) {
    return page.evaluate(() =>
        fetch('/my-alerts', { headers: { Accept: 'application/json' } }).then(
            (response) => response.json(),
        ),
    );
}

/** Acknowledges, from the floating cards, every alert pending on the page. */
async function acknowledgeAll(page) {
    const button = page.locator('[data-attention-alert-acknowledge]').first();

    while (await button.count()) {
        await button.click();
        await page.waitForTimeout(400);
    }
}

/** Sends an alert to E2E Ana from the agents table. */
async function alertAna(admin, alert) {
    await admin.reload();
    await admin
        .locator('[data-attention-agent]', { hasText: 'E2E Ana' })
        .getByRole('button', { name: 'Alertar' })
        .click();
    await sendFromComposer(admin, alert);
}

/** Sends an alert from the composer already open on the admin page. */
async function sendFromComposer(admin, { title, body, priority, color }) {
    await admin.locator('#alert-title').fill(title);
    await admin.locator('#alert-body').fill(body);
    await admin.getByRole('radio', { name: new RegExp(priority) }).click();

    if (color) {
        await admin.locator(`[data-alert-color="${color}"]`).click();
    }

    await admin.getByRole('button', { name: 'Enviar alerta' }).click();
    await admin.locator('#alert-title').waitFor({ state: 'detached' });
}

const run = async () => {
    const browser = await launchBrowser();

    try {
        const ana = await openPage(browser, { prefix: '[ana] ' });
        await login(ana, USERS.ana);
        const anaState = await ana.context().storageState();
        const reads = trackReads(ana);

        await step('seed', 'atencion-seed.php', async () => [
            data.waiting > 0 && data.queued > 0 && data.thread > 0,
            JSON.stringify(data),
        ]);

        await ana.goto(`${BASE}/conversations`);
        await row(ana, data.waiting_contact).waitFor();
        await ana.waitForLoadState('networkidle');
        reads.length = 0;

        await step(
            'preview-right-click',
            'right click on the waiting row',
            async () => {
                await row(ana, data.waiting_contact).click({ button: 'right' });
                const dialog = ana.locator('[data-chat-preview]');
                await dialog.waitFor();
                await ana.locator('[data-chat-preview-item]').first().waitFor();
                const text = (await dialog.innerText()).replace(/\s+/g, ' ');

                return [
                    text.includes('Sigo esperando') &&
                        text.includes('no marca los mensajes como leídos') &&
                        !ana.url().includes('c='),
                    text.slice(0, 200),
                ];
            },
            () => shot(ana, 'atencion-preview'),
        );

        await step('preview-appearance', 'pink colors of E2E Ana', async () => {
            const chat = await ana
                .locator(
                    '[data-chat-preview] [aria-label="Mensajes recientes"]',
                )
                .evaluate(
                    (element) => getComputedStyle(element).backgroundColor,
                );
            const bubble = await ana
                .locator('[data-chat-preview-item] article')
                .first()
                .evaluate(
                    (element) => getComputedStyle(element).backgroundColor,
                );
            const toRgb = (hex) =>
                `rgb(${[1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16)).join(', ')})`;

            return [
                chat === toRgb(data.colors.chat_bg) &&
                    bubble === toRgb(data.colors.received_bg),
                `chat=${chat} bubble=${bubble}`,
            ];
        });

        await step('preview-keeps-unread', 'close the preview', async () => {
            await ana.keyboard.press('Escape');
            await ana
                .locator('[data-chat-preview]')
                .waitFor({ state: 'hidden' });
            await ana.waitForTimeout(1_500);
            const before = await rowLabel(ana, data.waiting_contact);
            await ana.reload();
            await row(ana, data.waiting_contact).waitFor();
            await ana.waitForLoadState('networkidle');
            const after = await rowLabel(ana, data.waiting_contact);

            return [
                reads.length === 0 &&
                    before.includes('2 mensajes sin leer') &&
                    after.includes('2 mensajes sin leer'),
                `reads=${reads.length} before="${before.slice(0, 80)}" after="${after.slice(0, 80)}"`,
            ];
        });

        await step('accept-blocked', 'queued row and notice', async () => {
            const notice = ana.locator('[data-accept-block]');
            await notice.waitFor();
            const accept = row(ana, 'Cliente Nuevo')
                .locator('xpath=..')
                .locator('[data-inbox-accept]');
            const disabled = await accept.isDisabled();

            return [
                disabled &&
                    (await notice.innerText()).includes(
                        'Tienes conversaciones pendientes de atención',
                    ),
                `disabled=${disabled}`,
            ];
        });

        await step(
            'notice-opens-chat',
            'waiting chat from the notice',
            async () => {
                await ana
                    .locator('[data-accept-block-conversation]')
                    .first()
                    .click();
                await ana.waitForURL(
                    (url) => url.searchParams.get('c') === String(data.waiting),
                );
                await ana.waitForTimeout(2_500);
                await ana.goto(`${BASE}/conversations`);
                await row(ana, data.waiting_contact).waitFor();
                const label = await rowLabel(ana, data.waiting_contact);

                return [
                    reads.length > 0 && !label.includes('sin leer'),
                    `reads=${reads.length} label="${label.slice(0, 80)}"`,
                ];
            },
        );

        await step(
            'preview-long-press',
            'phone, hold the queued row',
            async () => {
                const phone = await openPage(browser, {
                    context: {
                        viewport: { width: 390, height: 844 },
                        hasTouch: true,
                        isMobile: true,
                        storageState: anaState,
                    },
                    prefix: '[phone] ',
                });

                try {
                    await phone.goto(`${BASE}/conversations`);
                    const target = row(phone, 'Cliente Nuevo');
                    await target.waitFor();
                    await phone.waitForLoadState('networkidle');
                    const box = await target.boundingBox();
                    await target.evaluate(
                        (element, point) =>
                            element.dispatchEvent(
                                new PointerEvent('pointerdown', {
                                    pointerType: 'touch',
                                    isPrimary: true,
                                    bubbles: true,
                                    clientX: point.x,
                                    clientY: point.y,
                                }),
                            ),
                        { x: box.x + 20, y: box.y + 20 },
                    );
                    await phone.waitForTimeout(800);
                    await target.evaluate((element) => {
                        element.dispatchEvent(
                            new PointerEvent('pointerup', {
                                pointerType: 'touch',
                                isPrimary: true,
                                bubbles: true,
                            }),
                        );
                        element.click();
                    });
                    await phone.locator('[data-chat-preview]').waitFor();
                    await phone.waitForTimeout(500);
                    const opened = phone.url().includes('c=');
                    await shot(phone, 'atencion-preview-phone');

                    return [!opened, `url=${phone.url()}`];
                } finally {
                    await phone.context().close();
                }
            },
        );

        await step(
            'team-preview',
            `thread ${data.thread}`,
            async () => {
                await ana.goto(`${BASE}/team-chat`);
                const link = ana.locator(
                    `a[href*="/team-chat/threads/${data.thread}"]`,
                );
                await link.waitFor();
                await ana.waitForLoadState('networkidle');
                const readsBefore = reads.length;
                await link.click({ button: 'right' });
                const dialog = ana.locator('[data-chat-preview]');
                await dialog.waitFor();
                await ana.locator('[data-chat-preview-item]').first().waitFor();
                const text = (await dialog.innerText()).replace(/\s+/g, ' ');
                await ana.keyboard.press('Escape');
                await ana.waitForTimeout(1_500);
                await ana.reload();
                await link.waitFor();
                const badge = await link
                    .locator('[aria-label="2 sin leer"]')
                    .count();

                return [
                    text.includes('pedido 4521') &&
                        reads.length === readsBefore &&
                        badge === 1 &&
                        !ana.url().includes('/threads/'),
                    `badge=${badge} reads=${reads.length - readsBefore} ${text.slice(0, 120)}`,
                ];
            },
            () => shot(ana, 'atencion-team-preview'),
        );

        const admin = await openPage(browser, {
            prefix: '[admin] ',
            trackToasts: true,
        });
        await login(admin, USERS.superadmin);

        await step(
            'alert-send',
            'urgent alert to E2E Ana',
            async () => {
                await admin.goto(`${BASE}/attention`);
                const agent = admin.locator('[data-attention-agent]', {
                    hasText: 'E2E Ana',
                });
                await agent.waitFor();
                const blocked = (await agent.innerText()).includes(
                    'No puede aceptar nuevas',
                );
                await agent.getByRole('button', { name: 'Alertar' }).click();
                await admin.locator('#alert-title').fill('Clientes esperando');
                await admin
                    .locator('#alert-body')
                    .fill('Responde a tus clientes pendientes, por favor.');
                await admin.getByRole('radio', { name: /Urgente/ }).click();
                await admin
                    .getByRole('button', { name: 'Enviar alerta' })
                    .click();
                const toast = await toastText(
                    admin,
                    'Alerta enviada a 1 agente.',
                );

                return [
                    blocked &&
                        toast.includes('Alerta enviada a 1 agente.') &&
                        !toast.startsWith('no toast'),
                    `blocked=${blocked} toast=${toast}`,
                ];
            },
            () => shot(admin, 'atencion-alert-send'),
        );

        await step(
            'alert-received',
            'E2E Ana loads a page',
            async () => {
                await ana.goto(`${BASE}/team-chat`);
                const card = ana.locator('[data-attention-alert]');
                await card.waitFor();
                const priority = await card.getAttribute('data-priority');
                const text = (await card.innerText()).replace(/\s+/g, ' ');
                await shot(ana, 'atencion-alert-received');
                await card
                    .locator('[data-attention-alert-acknowledge]')
                    .click();
                await card.waitFor({ state: 'detached' });
                await ana.reload();
                await ana.waitForLoadState('networkidle');
                const again = await ana
                    .locator('[data-attention-alert]')
                    .count();

                return [
                    priority === 'urgent' &&
                        text.includes('Clientes esperando') &&
                        text.includes('Urgente') &&
                        again === 0,
                    `again=${again} ${text.slice(0, 160)}`,
                ];
            },
            () => shot(ana, 'atencion-alert-received-failed'),
        );

        await step(
            'alert-history',
            'history of the administrator',
            async () => {
                await admin.reload();
                const entry = admin.locator('[data-attention-history]').first();
                await entry.waitFor();
                const text = (await entry.innerText()).replace(/\s+/g, ' ');

                return [
                    text.includes('1/1 mostradas') &&
                        text.includes('1/1 confirmadas'),
                    text.slice(0, 200),
                ];
            },
        );

        await step(
            'composer-sound',
            'choose siren, then chime, then none',
            async () => {
                await admin.addInitScript(installStopSpy);
                await admin.reload();
                await admin.evaluate(installStopSpy);
                await admin.evaluate(() => {
                    window.__tones = 0;
                    // oxlint-disable-next-line typescript/unbound-method -- re-bound with .apply
                    const create = BaseAudioContext.prototype.createOscillator;
                    BaseAudioContext.prototype.createOscillator = function (
                        ...args
                    ) {
                        window.__tones++;

                        return create.apply(this, args);
                    };
                });
                const historyBefore = await admin
                    .locator('[data-attention-history]')
                    .count();
                const agent = admin.locator('[data-attention-agent]', {
                    hasText: 'E2E Ana',
                });
                await agent.getByRole('button', { name: 'Alertar' }).click();
                const choose = async (label) => {
                    await admin
                        .getByRole('combobox', { name: 'Sonido' })
                        .click();
                    await admin.getByRole('option', { name: label }).click();
                    await admin.waitForTimeout(400);

                    return admin.evaluate(() => ({
                        tones: window.__tones,
                        stops: window.__stops,
                    }));
                };
                const siren = await choose('Tono largo (3 segundos)');
                const chime = await choose('Campanilla breve');
                const none = await choose('Sin sonido');
                const sent = await admin
                    .locator('[data-attention-history]')
                    .count();
                // Siren: 12 tones (each scheduled to stop: 12). Choosing the
                // chime cuts the 12 tones of the siren (12 more stops) and
                // plays its 2; «Sin sonido» plays nothing.
                const ok =
                    siren.tones === 12 &&
                    chime.tones - siren.tones === 2 &&
                    chime.stops - siren.stops >= 12 + 2 &&
                    none.tones === chime.tones &&
                    sent === historyBefore;

                return [
                    ok,
                    `siren=${JSON.stringify(siren)} chime=${JSON.stringify(chime)} none=${JSON.stringify(none)} history=${historyBefore}->${sent}`,
                ];
            },
            () => shot(admin, 'atencion-composer-sound-failed'),
        );

        await step(
            'composer-everyone',
            '«Todos» lists the agents by name',
            async () => {
                await admin.locator('[data-alert-everyone]').click();
                const box = admin.locator('[data-alert-everyone-recipients]');
                await box.waitFor();
                const more = box.getByRole('button', {
                    name: /Ver los \d+ destinatarios/,
                });

                if (await more.count()) {
                    await more.click();
                }

                const names = (
                    await box
                        .locator('[data-alert-recipient-name]')
                        .allInnerTexts()
                ).map((name) => name.trim());
                const table = (
                    await admin
                        .locator(
                            '[data-attention-agent] [data-attention-agent-name]',
                        )
                        .allInnerTexts()
                ).map((name) => name.trim());
                const listedAgents = await admin
                    .locator('[data-attention-agent]')
                    .count();
                await admin.locator('[data-alert-color="rose"]').click();
                // Back to the priority's own sound for the general alert.
                await admin.getByRole('combobox', { name: 'Sonido' }).click();
                await admin
                    .getByRole('option', { name: 'Según la prioridad' })
                    .click();
                const preview = await admin
                    .locator('aside [data-attention-alert]')
                    .getAttribute('data-color');
                await shot(admin, 'atencion-composer-everyone');

                return [
                    names.length === listedAgents &&
                        names.includes('E2E Ana') &&
                        new Set(names).size === names.length &&
                        (table.length === 0 || sameNames(table, names)) &&
                        preview === 'rose',
                    `names=${names.join(', ')} agents=${listedAgents} preview=${preview}`,
                ];
            },
            () => shot(admin, 'atencion-composer-everyone-failed'),
        );

        // E2E Ana signs in on a new page; typing in the login form is her
        // only interaction with it.
        const live = await openPage(browser, {
            trackAlerts: true,
            prefix: '[ana-live] ',
        });
        await login(live, USERS.ana);
        await live.goto(`${BASE}/team-chat`).catch(() => undefined);
        await live.waitForLoadState('networkidle');

        await step(
            'alert-live-sound',
            'urgent general alert, no click after signing in',
            async () => {
                const activated = await live.evaluate(
                    () => navigator.userActivation?.hasBeenActive ?? null,
                );
                const before = await alertCounts(live);
                await sendFromComposer(admin, {
                    title: 'Aviso general',
                    body: 'Atiendan a los clientes en espera.',
                    priority: 'Urgente',
                    color: 'rose',
                });
                const card = live.locator('[data-attention-alert]', {
                    hasText: 'Aviso general',
                });
                await card.waitFor({ timeout: REVERB_PORT ? 10_000 : 75_000 });
                await live.waitForTimeout(1_500);
                const after = await alertCounts(live);
                const blocked = await live
                    .locator('[data-attention-sound-blocked]')
                    .count();
                await shot(live, 'atencion-alert-live');

                // Navigating with goto() reloads the page: no activation is
                // left, so the honest outcome is «Activar sonido».
                const expectSound = activated === true;

                return [
                    expectSound
                        ? after.tones - before.tones === 12 && blocked === 0
                        : after.tones === before.tones && blocked === 1,
                    `activated=${activated} tones=${after.tones - before.tones} blocked=${blocked} reverb=${REVERB_PORT}`,
                ];
            },
            () => shot(live, 'atencion-alert-live-failed'),
        );

        await step(
            'alert-color-contrast',
            'rose background, light and dark',
            async () => {
                const selector = '[data-attention-alert][data-color="rose"]';
                const light = await contrastIn(live, selector);
                const dark = await openPage(browser, {
                    context: { colorScheme: 'dark', storageState: anaState },
                    prefix: '[ana-dark] ',
                });

                try {
                    await dark.goto(`${BASE}/team-chat`);
                    await dark.locator(selector).first().waitFor();
                    const darkResult = await contrastIn(dark, selector);
                    await shot(dark, 'atencion-alert-dark');

                    return [
                        light.ratio >= 4.5 &&
                            darkResult.ratio >= 4.5 &&
                            light.bg !== '255,255,255' &&
                            light.bg !== darkResult.bg,
                        `light=${JSON.stringify(light)} dark=${JSON.stringify(darkResult)}`,
                    ];
                } finally {
                    await dark.context().close();
                }
            },
        );

        await step(
            'alert-blocked-sound',
            'fresh page, hidden then visible, «Activar sonido»',
            async () => {
                const fresh = await openPage(browser, {
                    trackAlerts: true,
                    context: { storageState: anaState },
                    prefix: '[ana-fresh] ',
                });
                await fresh.addInitScript(installStrictActivation);

                try {
                    // Earlier alerts are acknowledged on another page.
                    await live.goto(`${BASE}/team-chat`);
                    for (const button of await live
                        .locator('[data-attention-alert-acknowledge]')
                        .all()) {
                        await button.click();
                    }
                    await fresh.goto(`${BASE}/team-chat`);
                    await fresh.waitForLoadState('networkidle');
                    await live.context().close();
                    // Every page of E2E Ana in the background: nobody sees it.
                    await setVisibility(fresh, 'hidden');
                    await setVisibility(ana, 'hidden');
                    const before = await alertCounts(fresh);

                    await admin.reload();
                    const agent = admin.locator('[data-attention-agent]', {
                        hasText: 'E2E Ana',
                    });
                    await agent
                        .getByRole('button', { name: 'Alertar' })
                        .click();
                    await sendFromComposer(admin, {
                        title: 'Revisa la cola',
                        body: 'Hay clientes nuevos esperando.',
                        priority: 'Importante',
                    });

                    const card = fresh.locator('[data-attention-alert]', {
                        hasText: 'Revisa la cola',
                    });

                    if (!REVERB_PORT) {
                        await setVisibility(fresh, 'visible');
                    }

                    await card.waitFor({
                        timeout: REVERB_PORT ? 10_000 : 75_000,
                    });
                    await fresh.waitForTimeout(1_000);
                    await admin.reload();
                    const hiddenEntry = (
                        await admin
                            .locator('[data-attention-history]')
                            .first()
                            .innerText()
                    ).replace(/\s+/g, ' ');

                    await setVisibility(fresh, 'visible');
                    await fresh.waitForTimeout(1_500);
                    await admin.reload();
                    const visibleEntry = (
                        await admin
                            .locator('[data-attention-history]')
                            .first()
                            .innerText()
                    ).replace(/\s+/g, ' ');

                    const notice = fresh.locator(
                        '[data-attention-sound-blocked]',
                    );
                    await notice.waitFor();
                    const silent = await alertCounts(fresh);
                    // The most pressing held sound plays: the siren (12
                    // tones) if an urgent alert is also pending, else the
                    // bell of «Importante» (4).
                    const expectedTones = (await fresh
                        .locator(
                            '[data-attention-alert][data-priority="urgent"]',
                        )
                        .count())
                        ? 12
                        : 4;
                    await shot(fresh, 'atencion-alert-blocked');
                    await fresh
                        .locator('[data-attention-enable-sound]')
                        .click();
                    await fresh.waitForTimeout(1_500);
                    const played = await alertCounts(fresh);
                    const noticeLeft = await notice.count();

                    return [
                        (!REVERB_PORT ||
                            hiddenEntry.includes('0/1 mostradas')) &&
                            visibleEntry.includes('1/1 mostradas') &&
                            silent.tones === before.tones &&
                            played.tones - silent.tones === expectedTones &&
                            noticeLeft === 0,
                        `hidden="${hiddenEntry.slice(0, 120)}" visible="${visibleEntry.slice(0, 120)}" tones ${before.tones}/${silent.tones}/${played.tones} notice=${noticeLeft}`,
                    ];
                } finally {
                    await setVisibility(ana, 'visible').catch(() => undefined);
                    await fresh.context().close();
                }
            },
            () => shot(admin, 'atencion-alert-blocked-failed'),
        );

        await step(
            'bell-alert',
            'important violet alert with a long message, two tabs',
            async () => {
                const tabA = await openPage(browser, {
                    trackAlerts: true,
                    prefix: '[bell-a] ',
                });
                await login(tabA, USERS.ana);
                const tabB = await tabA.context().newPage();
                await tabB.addInitScript(installAlertSpy);
                watchProblems(
                    tabB,
                    /status of (403|404|409|422|429)/,
                    '[bell-b] ',
                );
                const acknowledgements = [];
                tabA.on('request', (request) => {
                    if (request.url().includes('/acknowledge')) {
                        acknowledgements.push(request.url());
                    }
                });

                try {
                    await tabA.goto(`${BASE}/contacts`);
                    await tabA.waitForLoadState('networkidle');
                    await acknowledgeAll(tabA);
                    await tabB.goto(`${BASE}/contacts`);
                    await tabA.waitForLoadState('networkidle');
                    await tabB.waitForLoadState('networkidle');
                    acknowledgements.length = 0;
                    const badgeBefore = [
                        await bellBadge(tabA),
                        await bellBadge(tabB),
                    ];
                    const tonesBefore =
                        (await alertCounts(tabA)).tones +
                        (await alertCounts(tabB)).tones;
                    const title = 'Revisa la campana';
                    const body = `${'Hay conversaciones esperando una respuesta desde hace varios minutos; por favor atiéndelas en orden de llegada. '.repeat(4)}Gracias.`;

                    await alertAna(admin, {
                        title,
                        body,
                        priority: 'Importante',
                        color: 'violet',
                    });

                    const cardA = tabA.locator('[data-attention-alert]', {
                        hasText: title,
                    });
                    await cardA.waitFor({
                        timeout: REVERB_PORT ? 10_000 : 75_000,
                    });
                    await tabB
                        .locator('[data-attention-alert]', { hasText: title })
                        .waitFor({ timeout: REVERB_PORT ? 10_000 : 75_000 });
                    await tabA.waitForTimeout(2_000);
                    const badgeAfter = [
                        await bellBadge(tabA),
                        await bellBadge(tabB),
                    ];
                    const tones =
                        (await alertCounts(tabA)).tones +
                        (await alertCounts(tabB)).tones -
                        tonesBefore;

                    await bellButton(tabA).click();
                    const entry = tabA.locator('[data-bell-attention-alert]', {
                        hasText: title,
                    });
                    await entry.waitFor();
                    const entries = await entry.count();
                    const entryColor = await entry.getAttribute('data-color');
                    const entryText = (await entry.innerText()).replace(
                        /\s+/g,
                        ' ',
                    );
                    const bodyBox = entry.locator('[data-bell-attention-body]');
                    const folded = await bodyBox.evaluate(
                        (element) =>
                            element.scrollHeight > element.clientHeight,
                    );
                    await entry.locator('[data-bell-attention-expand]').click();
                    const unfolded = await bodyBox.evaluate(
                        (element) =>
                            element.scrollHeight <= element.clientHeight + 1,
                    );
                    const menuOverflow = await tabA
                        .locator('[data-bell-attention]')
                        .evaluate(
                            (element) =>
                                element.scrollWidth - element.clientWidth,
                        );
                    await shot(tabA, 'atencion-bell-open');
                    // Closed and opened again: still pending, nothing sent.
                    await tabA.keyboard.press('Escape');
                    await bellButton(tabA).click();
                    await entry.waitFor();
                    const pendingAfterOpen = (
                        await serverAlerts(tabA)
                    ).data.filter((alert) => alert.title === title);
                    const ackBefore = acknowledgements.length;
                    const tonesAfterOpen =
                        (await alertCounts(tabA)).tones +
                        (await alertCounts(tabB)).tones -
                        tonesBefore;

                    await entry
                        .locator('[data-bell-attention-acknowledge]')
                        .click();
                    await entry.waitFor({ state: 'detached' });
                    await cardA.waitFor({ state: 'detached' });
                    await tabB
                        .locator('[data-attention-alert]', { hasText: title })
                        .waitFor({
                            state: 'detached',
                            timeout: REVERB_PORT ? 10_000 : 75_000,
                        });
                    await tabB.waitForTimeout(500);
                    const badgeAck = [
                        await bellBadge(tabA),
                        await bellBadge(tabB),
                    ];
                    await tabB.reload();
                    await tabB.waitForLoadState('networkidle');
                    const back = await tabB
                        .locator('[data-attention-alert]', { hasText: title })
                        .count();
                    await bellButton(tabB).click();
                    await tabB.waitForTimeout(600);
                    const backInBell = await tabB
                        .locator('[data-bell-attention-alert]', {
                            hasText: title,
                        })
                        .count();
                    const serverLeft = (await serverAlerts(tabA)).data.filter(
                        (alert) => alert.title === title,
                    ).length;
                    await admin.reload();
                    const history = (
                        await admin
                            .locator('[data-attention-history]', {
                                hasText: title,
                            })
                            .first()
                            .innerText()
                    ).replace(/\s+/g, ' ');

                    const ok =
                        badgeAfter[0] === badgeBefore[0] + 1 &&
                        badgeAfter[1] === badgeBefore[1] + 1 &&
                        tones === 4 &&
                        tonesAfterOpen === tones &&
                        entries === 1 &&
                        entryColor === 'violet' &&
                        entryText.includes('De Administración del sistema') &&
                        entryText.includes('Pendiente de confirmar') &&
                        entryText.includes('Importante') &&
                        folded &&
                        unfolded &&
                        menuOverflow <= 0 &&
                        pendingAfterOpen.length === 1 &&
                        pendingAfterOpen[0].acknowledged_at === null &&
                        ackBefore === 0 &&
                        acknowledgements.length === 1 &&
                        badgeAck[0] === badgeBefore[0] &&
                        badgeAck[1] === badgeBefore[1] &&
                        back === 0 &&
                        backInBell === 0 &&
                        serverLeft === 0 &&
                        history.includes('1/1 confirmadas');

                    return [
                        ok,
                        `badge ${badgeBefore.join()}->${badgeAfter.join()}->${badgeAck.join()} tones=${tones}/${tonesAfterOpen} entries=${entries} color=${entryColor} folded=${folded}/${unfolded} overflow=${menuOverflow} pending=${JSON.stringify(pendingAfterOpen.map((a) => a.acknowledged_at))} acks=${ackBefore}/${acknowledgements.length} back=${back}/${backInBell} server=${serverLeft} history="${history.slice(0, 120)}" text="${entryText.slice(0, 80)}"`,
                    ];
                } finally {
                    await tabA.context().close();
                }
            },
            () => shot(admin, 'atencion-bell-alert-failed'),
        );

        await step(
            'bell-offline',
            'alert sent while signed out, phone, dark mode',
            async () => {
                // Every page of E2E Ana closed or in the background.
                await setVisibility(ana, 'hidden');
                const title = 'Mientras no estabas';
                await alertAna(admin, {
                    title,
                    body: 'Revisa tus conversaciones pendientes.',
                    priority: 'Informativa',
                    color: 'amber',
                });

                const phone = await openPage(browser, {
                    context: {
                        viewport: { width: 390, height: 844 },
                        colorScheme: 'dark',
                        hasTouch: true,
                        isMobile: true,
                    },
                    prefix: '[bell-phone] ',
                });

                try {
                    await login(phone, USERS.ana);
                    await phone.goto(`${BASE}/contacts`);
                    await phone.waitForLoadState('networkidle');
                    const badge = await bellBadge(phone);
                    await bellButton(phone).click();
                    const entry = phone.locator('[data-bell-attention-alert]', {
                        hasText: title,
                    });
                    await entry.waitFor();
                    await phone.waitForTimeout(400);
                    // The bell lists the alert: no floating copy on top.
                    const floatingWhileOpen = await phone
                        .locator('[data-attention-alerts]')
                        .count();
                    const box = await phone
                        .locator('[data-bell-attention]')
                        .boundingBox();
                    const contrast = await contrastIn(
                        phone,
                        '[data-bell-attention-alert][data-color="amber"]',
                    );
                    const overflow = await phone.evaluate(
                        () =>
                            document.documentElement.scrollWidth -
                            document.documentElement.clientWidth,
                    );
                    await shot(phone, 'atencion-bell-phone-dark');
                    await phone.keyboard.press('Escape');
                    // Acknowledged from the floating card this time.
                    await phone
                        .locator('[data-attention-alert]', { hasText: title })
                        .locator('[data-attention-alert-acknowledge]')
                        .click();
                    await phone
                        .locator('[data-attention-alert]', { hasText: title })
                        .waitFor({ state: 'detached' });
                    await bellButton(phone).click();
                    await phone.waitForTimeout(600);
                    const left = await entry.count();
                    const badgeAfter = await bellBadge(phone);

                    return [
                        badge >= 1 &&
                            box !== null &&
                            box.x >= 0 &&
                            box.x + box.width <= 390 &&
                            contrast.ratio >= 4.5 &&
                            overflow <= 0 &&
                            left === 0 &&
                            floatingWhileOpen === 0 &&
                            badgeAfter === badge - 1,
                        `floating=${floatingWhileOpen} badge=${badge}->${badgeAfter} box=${JSON.stringify(box)} contrast=${JSON.stringify(contrast)} overflow=${overflow} left=${left}`,
                    ];
                } finally {
                    await setVisibility(ana, 'visible').catch(() => undefined);
                    await phone.context().close();
                }
            },
            () => shot(admin, 'atencion-bell-offline-failed'),
        );

        await step(
            'dashboard-permission',
            'Agent role without «Ver el panel principal»',
            async () => {
                php(join(HERE, 'atencion-seed.php'), 'dashboard', 'off');
                const results = [];

                try {
                    for (const viewport of [
                        { width: 1280, height: 900, collapse: false },
                        { width: 1280, height: 900, collapse: true },
                        { width: 390, height: 844, collapse: false },
                    ]) {
                        const page = await openPage(browser, {
                            context: {
                                viewport: {
                                    width: viewport.width,
                                    height: viewport.height,
                                },
                                storageState: anaState,
                            },
                            prefix: '[ana-nav] ',
                        });

                        try {
                            await page.goto(`${BASE}/dashboard`);
                            await page.waitForLoadState('networkidle');
                            const landed = new URL(page.url()).pathname;
                            // A page with the regular header (the inbox has
                            // its own) to fold or open the menu.
                            await page.goto(`${BASE}/contacts`);
                            await page.waitForLoadState('networkidle');

                            if (viewport.width < 768 || viewport.collapse) {
                                const trigger = page
                                    .locator('[data-sidebar="trigger"]:visible')
                                    .first();
                                await trigger
                                    .click({ timeout: 5_000 })
                                    .catch(async (error) => {
                                        await shot(page, 'atencion-nav-debug');
                                        throw new Error(
                                            `${error.message} url=${page.url()} triggers=${await page.locator('[data-sidebar="trigger"]').count()}`,
                                        );
                                    });
                                await page.waitForTimeout(400);
                                await shot(
                                    page,
                                    `atencion-nav-${viewport.width}-${viewport.collapse ? 'collapsed' : 'open'}`,
                                );
                            }

                            const panel = await page
                                .locator('a[href$="/dashboard"]')
                                .count();
                            const conversations = await page
                                .locator('a[href$="/conversations"]')
                                .count();
                            results.push({
                                width: viewport.width,
                                collapsed: viewport.collapse,
                                landed,
                                panel,
                                conversations,
                            });
                        } finally {
                            await page.context().close();
                        }
                    }
                } finally {
                    php(join(HERE, 'atencion-seed.php'), 'dashboard', 'on');
                }

                await admin.goto(`${BASE}/dashboard`);
                const adminPanel = await admin
                    .locator('a[href$="/dashboard"]')
                    .count();

                return [
                    results.every(
                        (item) =>
                            item.landed === '/conversations' &&
                            item.panel === 0 &&
                            item.conversations > 0,
                    ) &&
                        adminPanel > 0 &&
                        new URL(admin.url()).pathname === '/dashboard',
                    `${JSON.stringify(results)} admin=${adminPanel}`,
                ];
            },
        );

        const adminState = await admin.context().storageState();

        for (const width of [390, 1280]) {
            for (const scheme of ['light', 'dark']) {
                await step(
                    `layout-${width}-${scheme}`,
                    `${width}px ${scheme}`,
                    async () => {
                        const page = await openPage(browser, {
                            context: {
                                viewport: { width, height: 900 },
                                colorScheme: scheme,
                                storageState: adminState,
                            },
                            prefix: `[${width}${scheme}] `,
                        });

                        try {
                            await page.goto(`${BASE}/attention`);
                            await page
                                .locator('[data-attention-agent]')
                                .first()
                                .waitFor();
                            const overflow = await page.evaluate(
                                () =>
                                    document.documentElement.scrollWidth -
                                    document.documentElement.clientWidth,
                            );
                            await shot(page, `atencion-${width}-${scheme}`);

                            return [overflow <= 0, `overflow=${overflow}`];
                        } finally {
                            await page.context().close();
                        }
                    },
                );
            }
        }
    } finally {
        await browser.close();
    }

    recordPageProblems();
    printProblems();
    exitWithSummary();
};

run().catch((error) => {
    console.error(error);
    process.exit(1);
});
