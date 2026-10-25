/*
 * Phase 5 scenario: personal items, the inbox filter menu, delivery status
 * sync, read-only supervision, attachments, PWA and the responsive matrix,
 * with real sessions in separate Chromium contexts: the Administrador
 * (global), the Ventas supervisor (departments scope) and agents Ana and
 * Beto of E2E Ventas.
 *
 *   a  Ana's private alias: Ana sees it in the list and the header, Beto and
 *      the Administrador see the official name, and the Administrador finds
 *      it in Administración › Elementos personales
 *   b  Ana's chat appearance never reaches Beto's chat
 *   c  "Filtros": two tags with O / Y, applied chips, "Limpiar filtros";
 *      indicators Bandeja general / Pendientes / No leídas
 *   d  row menu "Administrar etiquetas" saves and the row updates
 *   e  delivery statuses (signed webhooks sent → delivered → read) update the
 *      open chat and the inbox preview of two tabs, without reloading
 *   f  the supervisor keeps read-only visibility of a conversation transferred
 *      to Soporte: no operational actions, no composer
 *   g  an agent never opens another department's conversation by URL (404)
 *   h  "Guardar contacto" on a provisional contact
 *   i  Ana's personal quick reply (per-user grant) never reaches Beto's picker
 *   j  the template button is not offered to an agent
 *   k  attachments: a ZIP shows the download link notice; an .exe renamed to
 *      .pdf is refused with a clear message
 *   l  320–1440 px, light and dark: inbox, a conversation, Filtros, the
 *      appearance dialog, the help sheet, Usuarios (compact cards),
 *      Conexiones (channel picker, 4 channels) and an Auditoría detail; no
 *      horizontal page overflow (screenshots of every state)
 *   m  PWA: manifest, service worker, offline page, logout clears caches
 *
 *   E2E_REVERB=1 E2E_PORT=8100 E2E_REVERB_PORT=8161 WHATSAPP_TRANSPORT=simulated \
 *     WHATSAPP_SIMULATE_STATUSES=0 QUEUE_CONNECTION=sync \
 *     TEST_DB_LOCK_WAIT=3600 php tests/e2e/run.php fase5.mjs
 *
 * Seed: tests/e2e/fase5-seed.php (prepare / status). SIMULACIÓN: status
 * webhooks are bodies in the documented WhatsApp Cloud API format, signed
 * with the throwaway app secret of the testing database; replies use the
 * simulated transport. Meta is never contacted.
 */
import { join } from 'node:path';
import {
    BASE,
    HERE,
    SHOTS,
    USERS,
    WIDTHS,
    exitWithSummary,
    launchBrowser,
    listRow,
    login,
    openPage,
    php,
    printProblems,
    problems,
    recordPageProblems,
    shot,
    signedWebhook,
    step,
    waitFor,
} from './lib.mjs';

const SEED = join(HERE, 'fase5-seed.php');
const data = JSON.parse(php(SEED, 'prepare'));
const conversations = data.conversations;
const SUPERVISOR = data.users.supervisor;
const CHAT = 'section[aria-label^="Conversación con"]';

const check = (id, input, page, scenario) =>
    step(id, input, scenario, () => shot(page, `fail-${id}`));

const rowNames = async (page) =>
    (
        await page
            .locator('[data-inbox-item]')
            .evaluateAll((rows) =>
                rows.map((row) => row.getAttribute('aria-label') ?? ''),
            )
    ).map((label) =>
        label
            .replace(/^Pendiente de aceptar: /, '')
            .split(',')[0]
            .trim(),
    );

const rowLabel = (page, name) =>
    listRow(page, name)
        .getAttribute('aria-label')
        .catch(() => null);

const bubble = (page, text) =>
    page.locator('[data-message-id]', { hasText: text });

async function openConversation(page, id) {
    await page.goto(`${BASE}/conversations?c=${id}`);
    await page.locator(CHAT).waitFor();
    await page.waitForLoadState('networkidle');
}

const byName = (a, b) => (a < b ? -1 : a > b ? 1 : 0);

async function sameRows(page, expected, timeout = 8_000) {
    const sorted = [...expected].sort(byName);
    let names = [];
    const ok = await waitFor(async () => {
        names = (await rowNames(page)).sort(byName);

        return names.join('|') === sorted.join('|');
    }, timeout);

    return [ok, names];
}

async function bubbleStatus(page, text) {
    return (
        (await bubble(page, text)
            .locator('span[title]')
            .last()
            .getAttribute('title')
            .catch(() => '')) ?? ''
    );
}

async function webhookStatus(messageId, status) {
    const body = php(SEED, 'status', String(messageId), status).trim();

    return signedWebhook(data.webhook_key, data.app_secret, body);
}

/** No horizontal scroll of the page (document width within the viewport). */
const pageOverflow = (page) =>
    page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        innerWidth: window.innerWidth,
    }));

async function settle(page) {
    await page.waitForLoadState('networkidle').catch(() => {});
    await page
        .waitForFunction(
            () =>
                document
                    .getAnimations()
                    .every((animation) => animation.playState !== 'running'),
            null,
            { timeout: 3_000 },
        )
        .catch(() => {});
    await page.waitForTimeout(150);
}

async function logout(page, name) {
    await page.getByRole('button', { name }).first().click();
    await page.getByRole('menuitem', { name: 'Cerrar sesión' }).click();
    const dialog = page.getByRole('alertdialog').or(page.getByRole('dialog'));
    await dialog.getByText('¿Cerrar sesión?').waitFor();
    const response = page.waitForResponse(
        (candidate) =>
            new URL(candidate.url()).pathname === '/logout' &&
            candidate.request().method() === 'POST',
    );
    await dialog.getByRole('button', { name: 'Cerrar sesión' }).click();
    const answered = await response;
    await page.waitForURL(/\/(login)?$/);
    await page.waitForLoadState('networkidle');

    return answered;
}

const run = async () => {
    const browser = await launchBrowser();
    const session = async (email, prefix) => {
        const page = await openPage(browser, { prefix: `[${prefix}] ` });
        await login(page, email);

        return page;
    };

    try {
        const administrator = await session(USERS.administrator, 'admin');
        const supervisor = await session(SUPERVISOR, 'supervisor');
        const ana = await session(USERS.ana, 'ana');
        const beto = await session(USERS.beto, 'beto');

        await step('seed', 'fase5-seed.php', async () => [
            data.transport === 'simulated' &&
                Object.values(conversations).every(Number.isInteger),
            `transport=${data.transport}; conversations ${JSON.stringify(conversations)}`,
        ]);

        // (a) Ana's private alias.
        const ALIAS = 'Proveedor de cajas E2E';
        const OFFICIAL = 'Cliente Oficial Alias';

        await check(
            'alias-private-to-ana',
            'row menu + keyboard',
            ana,
            async () => {
                await ana.goto(`${BASE}/conversations`);
                await ana.waitForLoadState('networkidle');
                await ana
                    .getByRole('button', {
                        name: `Acciones de la conversación con ${OFFICIAL}`,
                    })
                    .click();
                await ana
                    .getByRole('menuitem', { name: 'Asignar alias personal' })
                    .click();
                const dialog = ana.getByRole('dialog', {
                    name: 'Alias personal',
                });
                await dialog.locator('#contact-alias').fill(ALIAS);
                await dialog
                    .getByRole('button', { name: 'Guardar alias' })
                    .click();
                await dialog.waitFor({ state: 'detached' });
                await listRow(ana, ALIAS).waitFor();
                const rowBadge = await listRow(ana, ALIAS)
                    .locator('[data-inbox-alias]')
                    .count();
                const officialRow = await ana
                    .locator('[data-inbox-item]', { hasText: OFFICIAL })
                    .count();
                await openConversation(ana, conversations.alias);
                const header = (
                    await ana.locator(CHAT).locator('h2').first().innerText()
                ).replace(/\s+/g, ' ');

                await beto.goto(`${BASE}/conversations`);
                await beto.waitForLoadState('networkidle');
                const betoOfficial = await listRow(beto, OFFICIAL).count();
                const betoAlias = await beto.getByText(ALIAS).count();

                await administrator.goto(
                    `${BASE}/conversations?view=all&c=${conversations.alias}`,
                );
                await administrator.locator(CHAT).waitFor();
                await administrator.waitForLoadState('networkidle');
                const adminHeader = (
                    await administrator
                        .locator(CHAT)
                        .locator('h2')
                        .first()
                        .innerText()
                ).replace(/\s+/g, ' ');
                const adminAlias = await administrator.getByText(ALIAS).count();

                await administrator.goto(`${BASE}/admin/personal-items`);
                await administrator.waitForLoadState('networkidle');
                const listed = administrator.getByRole('row', {
                    name: new RegExp(ALIAS),
                });
                const listedText = (
                    await listed
                        .first()
                        .innerText()
                        .catch(() => '')
                ).replace(/\s+/g, ' ');

                return [
                    rowBadge === 1 &&
                        officialRow === 0 &&
                        header.includes(ALIAS) &&
                        header.includes('Alias personal') &&
                        betoOfficial === 1 &&
                        betoAlias === 0 &&
                        adminHeader.includes(OFFICIAL) &&
                        !adminHeader.includes(ALIAS) &&
                        adminAlias === 0 &&
                        listedText.includes('E2E Ana') &&
                        listedText.includes(OFFICIAL),
                    `ana: row badge=${rowBadge}, official rows=${officialRow}, header="${header}"; beto: official rows=${betoOfficial}, alias texts=${betoAlias}; admin: header="${adminHeader}", alias texts=${adminAlias}; Elementos personales row="${listedText}"`,
                ];
            },
        );

        // (b) Ana's chat appearance stays hers.
        await check(
            'appearance-only-for-ana',
            'dialog + mouse',
            ana,
            async () => {
                await openConversation(ana, conversations.delivery);
                await ana
                    .getByRole('button', {
                        name: 'Cambiar los colores de tu chat',
                    })
                    .click();
                const dialog = ana.getByRole('dialog', {
                    name: 'Apariencia del chat',
                });
                await dialog
                    .getByRole('radio', { name: 'Todas mis conversaciones' })
                    .check();
                await dialog.getByRole('button', { name: /Océano/ }).click();
                await dialog
                    .getByRole('button', { name: 'Guardar', exact: true })
                    .click();
                await dialog.waitFor({ state: 'detached' });
                const chatColor = (page) =>
                    page
                        .getByRole('log', {
                            name: /Historial de la conversación/,
                        })
                        .evaluate((log) =>
                            getComputedStyle(log)
                                .getPropertyValue('--cm-chat-bg')
                                .trim()
                                .toLowerCase(),
                        );
                const anaColor = await waitFor(
                    async () => (await chatColor(ana)) === '#eef5fb',
                    5_000,
                );
                await openConversation(beto, conversations.beto);
                const betoColor = await chatColor(beto);

                return [
                    anaColor && betoColor === '',
                    `ana --cm-chat-bg=${await chatColor(ana)}; beto --cm-chat-bg="${betoColor}"`,
                ];
            },
        );

        // (c) The filter menu with two tags.
        await check('filter-menu-tags', 'Filtros + mouse', ana, async () => {
            await ana.goto(`${BASE}/conversations`);
            await ana.waitForLoadState('networkidle');
            const indicators = {
                general: (
                    await ana.locator('[data-inbox-chip="base"]').innerText()
                ).includes('Bandeja general'),
                pending: (
                    await ana.locator('[data-inbox-chip="pending"]').innerText()
                ).includes('Pendientes'),
                unread: (
                    await ana.locator('[data-inbox-unread]').innerText()
                ).includes('No leídas'),
            };
            const before = (await rowNames(ana)).sort(byName);
            await ana.locator('[data-inbox-filters]').first().click();
            const panel = ana.getByRole('dialog');
            await panel
                .getByRole('checkbox', { name: data.tags.urgent })
                .click();
            await panel
                .getByRole('checkbox', { name: data.tags.invoice })
                .click();
            const [any, anyRows] = await sameRows(ana, [
                'Cliente Etiquetas Ambas',
                'Cliente Solo Urgente',
                'Cliente Solo Factura',
            ]);
            await panel
                .getByRole('radio', { name: /Todas las etiquetas \(Y\)/ })
                .check();
            await ana.waitForURL(/tag_match=all/);
            const [all, allRows] = await sameRows(ana, [
                'Cliente Etiquetas Ambas',
            ]);
            await ana.keyboard.press('Escape');
            const chips = await ana
                .getByRole('group', { name: 'Filtros aplicados' })
                .locator('[data-inbox-applied]')
                .allInnerTexts();
            await ana.locator('[data-inbox-clear]').click();
            const [cleared, clearedRows] = await sameRows(ana, before);
            const url = new URL(ana.url()).search;

            return [
                Object.values(indicators).every(Boolean) &&
                    any &&
                    all &&
                    chips.some((chip) =>
                        chip.includes(`Etiqueta: ${data.tags.urgent}`),
                    ) &&
                    chips.some((chip) =>
                        chip.includes(`Etiqueta: ${data.tags.invoice}`),
                    ) &&
                    chips.some((chip) =>
                        chip.includes('Con todas las etiquetas (Y)'),
                    ) &&
                    cleared &&
                    !/tag/.test(url),
                `indicators=${JSON.stringify(indicators)}; O=${anyRows.join('/')}; Y=${allRows.join('/')}; chips=${JSON.stringify(chips)}; cleared=${clearedRows.length} rows url="${url}"`,
            ];
        });

        // (d) Tags from the row menu.
        await check('row-menu-tags', 'row menu + dialog', ana, async () => {
            const name = 'Cliente Sin Etiquetas';
            await ana
                .getByRole('button', {
                    name: `Acciones de la conversación con ${name}`,
                })
                .click();
            await ana
                .getByRole('menuitem', { name: 'Administrar etiquetas' })
                .click();
            const dialog = ana.getByRole('dialog', {
                name: 'Etiquetas de la conversación',
            });
            await dialog
                .getByRole('checkbox', { name: data.tags.wholesale })
                .click();
            await dialog
                .getByRole('button', { name: 'Guardar', exact: true })
                .click();
            await dialog.waitFor({ state: 'detached' });
            const updated = await waitFor(
                async () =>
                    (await listRow(ana, name).innerText()).includes(
                        data.tags.wholesale,
                    ),
                8_000,
            );

            return [updated, `row shows ${data.tags.wholesale}=${updated}`];
        });

        // (e) Delivery statuses in the open chat and two inbox tabs.
        await check(
            'delivery-status-sync',
            'reply + signed status webhooks, two tabs',
            ana,
            async () => {
                const customer = 'Cliente Entregas E2E';
                const text = 'Tu pedido sale hoy (E2E fase 5)';
                await openConversation(ana, conversations.delivery);
                const second = await ana.context().newPage();
                await second.goto(`${BASE}/conversations`);
                await second.waitForLoadState('networkidle');
                await listRow(second, customer).waitFor();

                for (const page of [ana, second]) {
                    await page.evaluate(
                        () => (window.__fase5 = 'same document'),
                    );
                }

                await ana.locator('#chat-composer').click();
                await ana.keyboard.type(text);
                await ana.keyboard.press('Enter');
                await bubble(ana, text).waitFor();
                const id = await bubble(ana, text).getAttribute(
                    'data-message-id',
                );
                const notes = [
                    `after send: "${await bubbleStatus(ana, text)}"`,
                ];
                let ok = true;

                for (const [status, label] of [
                    ['sent', 'Enviado'],
                    ['delivered', 'Entregado'],
                    ['read', 'Leído'],
                ]) {
                    const since = Date.now();
                    const answer = await webhookStatus(id, status);
                    const chat = await waitFor(
                        async () => (await bubbleStatus(ana, text)) === label,
                        8_000,
                    );
                    const rows = [];

                    for (const page of [ana, second]) {
                        rows.push(
                            await waitFor(
                                async () =>
                                    (
                                        (await rowLabel(page, customer)) ?? ''
                                    ).includes(
                                        `último mensaje enviado (${label.toLowerCase()})`,
                                    ),
                                8_000,
                            ),
                        );
                    }

                    ok &&= answer === 200 && chat && rows.every(Boolean);
                    notes.push(
                        `${status}: webhook ${answer}, chat=${chat}, row tab1=${rows[0]} tab2=${rows[1]} (${Date.now() - since} ms)`,
                    );
                }

                const sameDocument = (
                    await Promise.all(
                        [ana, second].map((page) =>
                            page.evaluate(() => window.__fase5),
                        ),
                    )
                ).every((value) => value === 'same document');
                await second.close();

                return [
                    ok && sameDocument,
                    `${notes.join('; ')}; no reload=${sameDocument}`,
                ];
            },
        );

        // (f) Read-only supervision after a transfer to Soporte.
        await check(
            'supervisor-read-only-after-transfer',
            'URL + render',
            supervisor,
            async () => {
                await openConversation(supervisor, conversations.transferred);
                const chat = supervisor.locator(CHAT);
                const header = (
                    await chat.locator('h2').first().innerText()
                ).trim();
                const operational = chat.getByRole('button', {
                    name: /^(Asignar|Transferir|Cerrar conversación|Devolver a la cola|Aceptar)/,
                });
                let visible = await operational.count();
                const more = chat.getByRole('button', {
                    name: 'Más acciones de la conversación',
                });
                let menuItems = [];

                if ((await more.count()) > 0) {
                    await more.click();
                    menuItems = await supervisor
                        .getByRole('menuitem')
                        .allInnerTexts();
                    await supervisor.keyboard.press('Escape');
                    visible += menuItems.filter((item) =>
                        /Asignar|Transferir|Cerrar|Devolver/.test(item),
                    ).length;
                }

                const composer = await supervisor
                    .locator('#chat-composer')
                    .count();
                const notice = (
                    await chat
                        .locator('[role="status"]', {
                            hasText: /responder|permiso/,
                        })
                        .allInnerTexts()
                ).join(' ');
                const timeline = await supervisor.request.get(
                    `${BASE}/conversations/${conversations.transferred}/timeline`,
                    { headers: { Accept: 'application/json' } },
                );
                const foreign = await supervisor.request.get(
                    `${BASE}/conversations/${conversations.support_only}`,
                );

                return [
                    header.includes('Cliente Transferido E2E') &&
                        visible === 0 &&
                        composer === 0 &&
                        notice !== '' &&
                        timeline.status() === 200 &&
                        foreign.status() === 404,
                    `header="${header}"; operational actions=${visible} (menu ${JSON.stringify(menuItems)}); composer=${composer}; notice="${notice.slice(0, 120)}"; timeline=${timeline.status()}; never-in-Ventas conversation=${foreign.status()}`,
                ];
            },
        );

        // (g) Another department's conversation by URL.
        await check('agent-foreign-url-404', 'URL', ana, async () => {
            const statuses = {};

            for (const key of ['transferred', 'support_only']) {
                statuses[key] = (
                    await ana.request.get(
                        `${BASE}/conversations/${conversations[key]}`,
                    )
                ).status();
                statuses[`${key}.timeline`] = (
                    await ana.request.get(
                        `${BASE}/conversations/${conversations[key]}/timeline`,
                        { headers: { Accept: 'application/json' } },
                    )
                ).status();
            }

            await ana.goto(
                `${BASE}/conversations?c=${conversations.support_only}`,
            );
            await ana.waitForLoadState('networkidle');
            const unavailable = await ana
                .getByText('La conversación no está disponible')
                .count();
            const leaked = await ana.getByText('Cliente Solo Soporte').count();

            return [
                Object.values(statuses).every((status) => status === 404) &&
                    unavailable > 0 &&
                    leaked === 0,
                `${JSON.stringify(statuses)}; "no disponible"=${unavailable}; contact name shown=${leaked}`,
            ];
        });

        // (h) "Guardar contacto" on a provisional contact.
        await check(
            'save-provisional-contact',
            'dialog + keyboard',
            administrator,
            async () => {
                await openConversation(
                    administrator,
                    conversations.provisional,
                );
                const show = administrator.getByRole('button', {
                    name: 'Mostrar el panel de detalles',
                });

                if ((await show.count()) > 0) {
                    await show.click();
                }

                const badgeBefore = await administrator
                    .getByText('Contacto no guardado')
                    .count();
                await administrator
                    .getByRole('button', { name: 'Guardar contacto' })
                    .first()
                    .click();
                const dialog = administrator.getByRole('dialog', {
                    name: 'Guardar contacto',
                });
                await dialog
                    .locator('#save-contact-name')
                    .fill('Cliente Guardado E2E');
                await dialog
                    .getByRole('button', { name: 'Guardar contacto' })
                    .click();
                await dialog.waitFor({ state: 'detached' });
                const gone = await waitFor(
                    async () =>
                        (await administrator
                            .getByText('Contacto no guardado')
                            .count()) === 0 &&
                        (await administrator
                            .getByRole('button', { name: 'Guardar contacto' })
                            .count()) === 0,
                    8_000,
                );
                await administrator.goto(`${BASE}/contacts?search=Guardado`);
                await administrator.waitForLoadState('networkidle');
                const listed = await administrator
                    .getByText('Cliente Guardado E2E')
                    .count();

                return [
                    badgeBefore > 0 && gone && listed > 0,
                    `badge before=${badgeBefore}; badge and button gone=${gone}; in the directory=${listed}`,
                ];
            },
        );

        // (i) Personal quick replies.
        await check(
            'personal-quick-reply',
            'form + "/" picker',
            ana,
            async () => {
                await ana.goto(`${BASE}/quick-replies?segment=mine`);
                await ana.waitForLoadState('networkidle');
                await ana
                    .getByRole('button', { name: /^Nueva respuesta/ })
                    .first()
                    .click();
                const dialog = ana.getByRole('dialog');
                await dialog
                    .locator('#quick-reply-title')
                    .fill('Mi despedida E2E');
                await dialog.locator('#quick-reply-shortcut').fill('anae2e');
                await dialog
                    .locator('#quick-reply-body')
                    .fill('Gracias por tu compra, que tengas buen día.');
                await dialog
                    .getByRole('button', { name: 'Crear respuesta' })
                    .click();
                await dialog.waitFor({ state: 'detached' });
                await ana.getByText('Mi despedida E2E').first().waitFor();

                const options = async (page, query) => {
                    await page.locator('#chat-composer').click();
                    await page.locator('#chat-composer').fill('');
                    await page.keyboard.type(`/${query}`);
                    await page.waitForTimeout(800);
                    const texts = await page
                        .getByRole('listbox', {
                            name: 'Respuestas rápidas disponibles',
                        })
                        .getByRole('option')
                        .allInnerTexts()
                        .catch(() => []);
                    await page.locator('#chat-composer').fill('');

                    return texts.map((text) => text.replace(/\s+/g, ' '));
                };

                await openConversation(ana, conversations.delivery);
                const anaOptions = await options(ana, 'anae2e');
                await openConversation(beto, conversations.beto);
                const betoOptions = await options(beto, 'anae2e');
                const betoCorporate = await options(beto, 'ventase2e');

                return [
                    anaOptions.some((text) =>
                        text.includes('Mi despedida E2E'),
                    ) &&
                        betoOptions.every(
                            (text) => !text.includes('Mi despedida E2E'),
                        ) &&
                        betoCorporate.some((text) =>
                            text.includes('Saludo Ventas E2E'),
                        ),
                    `ana /anae2e=${JSON.stringify(anaOptions)}; beto /anae2e=${JSON.stringify(betoOptions)}; beto /ventase2e=${JSON.stringify(betoCorporate)}`,
                ];
            },
        );

        // (j) Templates are not an agent's tool.
        await check('no-template-button-for-agent', 'render', ana, async () => {
            await openConversation(ana, conversations.closed_window);
            const button = await ana
                .getByRole('button', { name: 'Enviar plantilla' })
                .count();
            const composer = await ana.locator('#chat-composer').count();
            const notice = (
                await ana
                    .locator(CHAT)
                    .locator('[role="status"]')
                    .allInnerTexts()
            ).join(' ');

            return [
                button === 0 && composer === 0,
                `template button=${button}; free-form composer=${composer}; notice="${notice.replace(/\s+/g, ' ').slice(0, 160)}"`,
            ];
        });

        // (k) Attachments: ZIP as a link, a renamed executable refused.
        await check(
            'attachments-zip-and-renamed-exe',
            'file chooser',
            ana,
            async () => {
                await openConversation(ana, conversations.delivery);
                const input = ana.locator('input[type="file"]').first();
                await input.setInputFiles({
                    name: 'respaldo-e2e.zip',
                    mimeType: 'application/zip',
                    buffer: Buffer.concat([
                        Buffer.from([0x50, 0x4b, 0x05, 0x06]),
                        Buffer.alloc(18),
                    ]),
                });
                const linkNotice = ana
                    .locator(CHAT)
                    .locator('[role="status"]', {
                        hasText: 'Se enviará como enlace de descarga seguro',
                    });
                const zipNotice = await linkNotice
                    .waitFor({ timeout: 5_000 })
                    .then(() => true)
                    .catch(() => false);
                await ana
                    .getByRole('button', { name: /^Quitar el archivo adjunto/ })
                    .click();

                const executable = Buffer.concat([
                    Buffer.from('MZ', 'latin1'),
                    Buffer.from([
                        0x90, 0, 3, 0, 0, 0, 4, 0, 0, 0, 0xff, 0xff, 0, 0,
                    ]),
                    Buffer.alloc(64),
                ]);
                await input.setInputFiles({
                    name: 'factura-e2e.pdf',
                    mimeType: 'application/pdf',
                    buffer: executable,
                });
                const messagesBefore = await ana
                    .locator('[data-message-id]')
                    .count();
                await ana
                    .getByRole('button', { name: 'Enviar', exact: true })
                    .click();
                const refusal = ana.locator(CHAT).locator('[role="alert"]', {
                    hasText: /no corresponde|no se puede enviar/,
                });
                const refused = await refusal
                    .first()
                    .waitFor({ timeout: 8_000 })
                    .then(() => true)
                    .catch(() => false);
                const message = refused
                    ? (await refusal.first().innerText()).replace(/\s+/g, ' ')
                    : (
                          await ana
                              .locator(CHAT)
                              .locator('[role="alert"]')
                              .allInnerTexts()
                      ).join(' | ');
                await ana.waitForTimeout(500);
                const messagesAfter = await ana
                    .locator('[data-message-id]')
                    .count();
                await ana
                    .getByRole('button', { name: /^Quitar el archivo adjunto/ })
                    .click()
                    .catch(() => {});

                return [
                    zipNotice && refused && messagesAfter === messagesBefore,
                    `zip link notice=${zipNotice}; renamed exe refused=${refused} "${message.slice(0, 160)}"; messages ${messagesBefore}→${messagesAfter}`,
                ];
            },
        );

        // (l) Responsive matrix with the Administrador.
        const adminState = await administrator.context().storageState();

        for (const scheme of ['light', 'dark']) {
            for (const width of WIDTHS) {
                await step(
                    `responsive-${width}-${scheme}`,
                    `${width}px ${scheme}`,
                    async () => {
                        const phone = width <= 430;
                        const page = await openPage(browser, {
                            context: {
                                viewport: { width, height: phone ? 844 : 900 },
                                colorScheme: scheme,
                                hasTouch: phone,
                                isMobile: phone,
                                storageState: adminState,
                            },
                            prefix: `[${width}${scheme}] `,
                        });
                        const notes = [];
                        const measure = async (name) => {
                            await settle(page);
                            const { scrollWidth, innerWidth } =
                                await pageOverflow(page);
                            await shot(page, `${width}-${scheme}-${name}`, {
                                fullPage: false,
                            });

                            if (scrollWidth > innerWidth) {
                                notes.push(
                                    `${name}: scrollWidth ${scrollWidth} > ${innerWidth}`,
                                );
                            }
                        };
                        const attempt = async (name, run) => {
                            try {
                                await run();
                                await measure(name);
                            } catch (error) {
                                notes.push(
                                    `${name}: ${String(error).split('\n')[0]}`,
                                );
                                await shot(
                                    page,
                                    `${width}-${scheme}-${name}-error`,
                                    { fullPage: false },
                                );
                            }

                            await page.keyboard.press('Escape').catch(() => {});
                        };

                        try {
                            await page.goto(`${BASE}/conversations`);
                            const dark = await page.evaluate(() =>
                                document.documentElement.classList.contains(
                                    'dark',
                                ),
                            );

                            if (dark !== (scheme === 'dark')) {
                                notes.push(`dark class=${dark}`);
                            }

                            await attempt('inbox', async () => {
                                await page
                                    .locator('[data-inbox-filters]:visible')
                                    .first()
                                    .waitFor();
                            });
                            await attempt('filtros', async () => {
                                await page
                                    .locator('[data-inbox-filters]:visible')
                                    .first()
                                    .click();
                                await page
                                    .getByRole('dialog')
                                    .first()
                                    .waitFor();
                            });
                            await attempt('ayuda', async () => {
                                await page
                                    .getByRole('button', {
                                        name: '¿Cómo funciona este módulo?',
                                    })
                                    .first()
                                    .click();
                                await page
                                    .getByRole('dialog')
                                    .getByText('¿Cómo funciona este módulo?')
                                    .first()
                                    .waitFor();
                            });
                            await attempt('conversacion', async () => {
                                await page.goto(
                                    `${BASE}/conversations?c=${conversations.delivery}`,
                                );
                                await page.locator(CHAT).waitFor();
                            });
                            await attempt('apariencia', async () => {
                                await page
                                    .getByRole('button', {
                                        name: 'Cambiar los colores de tu chat',
                                    })
                                    .click();
                                await page
                                    .getByRole('dialog', {
                                        name: 'Apariencia del chat',
                                    })
                                    .waitFor();
                            });
                            await attempt('usuarios', async () => {
                                await page.goto(`${BASE}/admin/users`);
                                await page
                                    .getByText('Activos', { exact: true })
                                    .first()
                                    .waitFor();
                                // Compact cards: two per row on phones, short.
                                const cards = await page
                                    .getByText('Activos', { exact: true })
                                    .first()
                                    .evaluate((label) => {
                                        const grid = label.closest('.grid');

                                        return [...(grid?.children ?? [])].map(
                                            (card) => {
                                                const rect =
                                                    card.getBoundingClientRect();

                                                return {
                                                    top: Math.round(rect.top),
                                                    height: Math.round(
                                                        rect.height,
                                                    ),
                                                };
                                            },
                                        );
                                    });
                                const firstRow = cards.filter(
                                    (card) => card.top === cards[0]?.top,
                                ).length;

                                if (
                                    cards.length === 0 ||
                                    firstRow < 2 ||
                                    cards.some((card) => card.height > 160)
                                ) {
                                    notes.push(
                                        `usuarios: stat cards ${JSON.stringify(cards)}`,
                                    );
                                }
                            });
                            await attempt('conexiones', async () => {
                                await page.goto(`${BASE}/connections`);
                                await page
                                    .getByRole('button', {
                                        name: 'Nueva conexión',
                                    })
                                    .first()
                                    .click();
                                const picker = page.getByRole('dialog', {
                                    name: 'Nueva conexión',
                                });
                                await picker.waitFor();
                                const channels = await picker
                                    .locator('[data-channel]')
                                    .evaluateAll((links) =>
                                        links.map(
                                            (link) => link.dataset.channel,
                                        ),
                                    );

                                if (channels.length !== 4) {
                                    notes.push(
                                        `conexiones: channels ${JSON.stringify(channels)}`,
                                    );
                                }
                            });
                            await attempt('auditoria', async () => {
                                await page.goto(`${BASE}/admin/audit`);
                                await page
                                    .getByRole('button', {
                                        name: /^Ver detalle:/,
                                    })
                                    .first()
                                    .click();
                                await page
                                    .getByRole('dialog')
                                    .first()
                                    .waitFor();
                            });
                        } finally {
                            await page.context().close();
                        }

                        return [
                            notes.length === 0,
                            notes.join(' | ') ||
                                '8 states without horizontal overflow',
                        ];
                    },
                );
            }
        }

        // (m) PWA.
        await step('pwa-manifest', 'GET /manifest.webmanifest', async () => {
            const response = await administrator.request.get(
                `${BASE}/manifest.webmanifest`,
            );
            const manifest = await response.json().catch(() => null);
            const sizes = (manifest?.icons ?? []).map((icon) => icon.sizes);

            return [
                response.status() === 200 &&
                    manifest?.name === 'Chat Manager' &&
                    manifest?.display === 'standalone' &&
                    typeof manifest?.start_url === 'string' &&
                    sizes.includes('192x192') &&
                    sizes.includes('512x512'),
                `status ${response.status()} ${response.headers()['content-type']}; name=${manifest?.name}; display=${manifest?.display}; start_url=${manifest?.start_url}; icons=${JSON.stringify(sizes)}`,
            ];
        });

        // Offline on purpose: the failed navigations and the real-time
        // socket closing while disconnected are expected there.
        const pwa = await openPage(browser, {
            prefix: '[pwa] ',
            ignoreConsole:
                /status of (403|404|409|422|429)|ERR_INTERNET_DISCONNECTED/,
        });
        await login(pwa, USERS.administrator);

        await check(
            'pwa-service-worker-offline',
            'offline context',
            pwa,
            async () => {
                await pwa.goto(`${BASE}/dashboard`);
                const scope = await pwa.evaluate(() =>
                    Promise.race([
                        navigator.serviceWorker.ready.then(
                            (registration) => registration.scope,
                        ),
                        new Promise((resolve) =>
                            setTimeout(() => resolve(null), 15_000),
                        ),
                    ]),
                );
                const precached = await waitFor(
                    () =>
                        pwa.evaluate(
                            async () =>
                                (await caches.match('/offline.html')) !==
                                undefined,
                        ),
                    15_000,
                );
                await pwa.context().setOffline(true);
                let title = '';

                try {
                    await pwa.goto(`${BASE}/conversations`);
                    title = await pwa.title();
                } catch (error) {
                    title = `navigation failed: ${String(error).split('\n')[0]}`;
                } finally {
                    await pwa.context().setOffline(false);
                }

                // pusher-js throws while its socket closes offline; only those
                // page errors of this offline context are discarded.
                const offlineSocket = problems.pageerror.filter((line) =>
                    line.startsWith(
                        '[pwa] Error: WebSocket is already in CLOSING or CLOSED state',
                    ),
                );
                problems.pageerror = problems.pageerror.filter(
                    (line) => !offlineSocket.includes(line),
                );

                return [
                    scope === `${BASE}/` &&
                        precached &&
                        title === 'Sin conexión - Chat Manager',
                    `scope=${scope}; offline page precached=${precached}; offline navigation title="${title}"; socket errors while offline (expected, discarded)=${offlineSocket.length}`,
                ];
            },
        );

        await check('pwa-logout-clears-caches', 'logout', pwa, async () => {
            await pwa.goto(`${BASE}/dashboard`);
            await pwa.waitForLoadState('networkidle');
            const marked = await pwa.evaluate(async () => {
                const names = (await caches.keys()).filter((name) =>
                    name.startsWith('chat-manager-static-'),
                );

                for (const name of names) {
                    await (
                        await caches.open(name)
                    ).put('/e2e-marker', new Response('marker'));
                }

                return names;
            });
            const response = await logout(pwa, /E2E Administrador/);
            const headers = await response.allHeaders();
            await pwa.waitForTimeout(1_000);
            const marker = await pwa.evaluate(async () => {
                for (const name of await caches.keys()) {
                    if (await (await caches.open(name)).match('/e2e-marker')) {
                        return name;
                    }
                }

                return null;
            });

            return [
                marked.length > 0 &&
                    marker === null &&
                    /cache/.test(headers['clear-site-data'] ?? ''),
                `caches before=${JSON.stringify(marked)}; marker left in=${marker}; Clear-Site-Data=${headers['clear-site-data']}; landed on ${new URL(pwa.url()).pathname}`,
            ];
        });
    } finally {
        await browser.close();
    }

    if (SHOTS) {
        console.log(`\nscreenshots: ${SHOTS}`);
    }

    recordPageProblems(800);
    printProblems();
    exitWithSummary();
};

run().catch((error) => {
    console.error(error);
    process.exit(1);
});
