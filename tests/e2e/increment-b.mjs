/*
 * Browser scenarios of increment B (conversation engine and list UI).
 *
 * Real Chromium, real mouse and keyboard. Run them through the runner, which
 * rebuilds the testing database (chatmanager_testing), starts and stops the
 * local server and never creates other databases:
 *
 *   php tests/e2e/run.php increment-b.mjs
 *
 * Customer messages are simulated through tests/e2e/increment-b-seed.php
 * (documented WhatsApp Cloud API webhook format); Meta is never contacted.
 *
 * Environment (set by the runner):
 *   E2E_BASE_URL       server under test (default http://127.0.0.1:8020)
 *   APP_ENV=testing    so the seed script uses .env.testing
 *   PLAYWRIGHT_MODULE  where to load Playwright from (default "playwright")
 *   E2E_SCREENSHOTS    optional directory for screenshots
 */
import {
    BASE,
    USERS,
    applyInboxFilters,
    chromium,
    exitWithSummary,
    inbound,
    listRow,
    login,
    openConversation,
    openPage,
    operations,
    overflow,
    record,
    recordPageProblems,
    runOperation,
    shot,
    toastText,
} from './lib.mjs';

const ACCEPT_OUTCOME =
    /Aceptaste la conversación|Otra persona ya aceptó esta conversación/;

const newPage = (browser, options = {}) =>
    openPage(browser, { context: options, ignoreConsole: /status of 404/ });

async function waitToastsGone(page) {
    await page
        .locator('[data-sonner-toast]')
        .first()
        .waitFor({ state: 'detached', timeout: 15_000 })
        .catch(() => {});
}

/*
 * Increment C rebuilt the inbox as one three-panel page (/conversations?c=ID):
 * list rows are buttons that open the chat, and the operations live in the
 * chat header (main action as a button, the rest in "Más acciones"). The
 * shared helpers (lib.mjs) follow that layout; the behavior checked is the
 * same.
 */

const run = async () => {
    const browser = await chromium.launch();

    try {
        // 1. Navigation: conversations module present, global module search gone.
        const ana = await newPage(browser);
        await login(ana, USERS.ana);
        const nav = ana.getByRole('link', { name: 'Conversaciones' }).first();
        record(
            'nav-conversations',
            'render',
            (await nav.count()) > 0,
            'sidebar link visible for agents',
        );
        record(
            'no-module-search',
            'render',
            (await ana
                .getByRole('button', { name: /Buscar módulo/ })
                .count()) === 0,
            '"Buscar módulo" removed',
        );

        await nav.click();
        await ana.waitForURL(/\/conversations$/);
        await ana.waitForLoadState('networkidle');

        // 1b. Agents get a personal inbox: their own conversations and the
        // queue of their department, without the "Solo mías" switch.
        // Selector update (increment C): the queue tab is now labelled
        // "Pendientes" and the agent filter lives in the "Filtros" popover.
        // Selector update (phase 4): the inbox opens on "Bandeja general"
        // and "Pendientes" is the general inbox with status=pending
        // (?view=queue is an alias).
        // Selector update (Fase 5): the always visible indicators are
        // "Bandeja general", "Pendientes" and "No leídas"; "Mis
        // conversaciones" is an option of "Vista" in the "Filtros" panel.
        const indicators = ana.getByRole('navigation', { name: 'Bandejas' });
        const generalView = indicators.getByRole('button', {
            name: /^Bandeja general/,
        });
        const queueView = indicators.getByRole('button', {
            name: /^Pendientes/,
        });
        const unreadView = indicators.getByRole('button', {
            name: /^No leídas/,
        });
        await ana.getByRole('button', { name: /^Filtros/ }).click();
        const agentFilter = await ana.locator('#inbox-agent').count();
        const departmentFilter = await ana.locator('#inbox-department').count();
        const mineOption = await ana
            .getByRole('radio', { name: 'Mis conversaciones', exact: true })
            .count();
        await ana.keyboard.press('Escape');
        record(
            'agent-personal-inbox',
            'render',
            (await generalView.count()) === 1 &&
                (await generalView.getAttribute('aria-pressed')) === 'true' &&
                (await queueView.count()) === 1 &&
                (await unreadView.count()) === 1 &&
                mineOption === 1 &&
                (await ana
                    .getByRole('switch', { name: 'Solo mis conversaciones' })
                    .count()) === 0 &&
                departmentFilter === 1 &&
                agentFilter === 0,
            `"Bandeja general" by default + "Pendientes" + "No leídas", "Mis conversaciones" in Filtros, no "Solo mías" nor agent filter (agent=${agentFilter})`,
        );
        await queueView.click();
        await ana.waitForURL(/status=pending/);
        await ana.waitForLoadState('networkidle');
        await ana.evaluate(() => (window.__sameDocument = true));

        // 2. A simulated customer message appears without reloading.
        const [{ conversation: firstId, outcome }] = inbound(
            '5215511112222',
            'Cliente E2E Anuncio',
            'Hola, vi su anuncio',
        );
        record(
            'inbound-creates-conversation',
            'webhook (simulated)',
            outcome === 'created',
            `conversation ${firstId}`,
        );
        const row = listRow(ana, 'Cliente E2E Anuncio');
        await row.waitFor({ timeout: 25_000 }).catch(() => {});
        const sameDocument = await ana.evaluate(
            () => window.__sameDocument === true,
        );
        record(
            'queue-refreshes-itself',
            'polling',
            (await row.count()) > 0 && sameDocument,
            'appeared within 25 s, no reload',
        );
        await shot(ana, 'b01_queue_agent');

        // 2b. Manual availability was removed (Fase 5): an active agent of
        // the department can accept right away, with no switch or banner.
        // Flow update (increment C): Accept moved from the row to the chat
        // header; the row opens the chat in the same page.
        await row.click();
        await ana.waitForURL(new RegExp(`c=${firstId}`));
        const acceptButton = operations(ana).getByRole('button', {
            name: 'Aceptar',
            exact: true,
        });
        await acceptButton.waitFor();
        record(
            'no-availability-gate',
            'render',
            (await ana.getByText('Estás como No disponible').count()) === 0 &&
                (await ana
                    .getByRole('switch', { name: /Disponible/ })
                    .count()) === 0 &&
                !(await acceptButton.isDisabled()),
            'no availability switch nor banner; Accept enabled',
        );

        // 3. Two agents accept at the same time: exactly one wins.
        const beto = await newPage(browser);
        await login(beto, USERS.beto);
        await beto.goto(`${BASE}/conversations`);
        await Promise.all([
            openConversation(ana, firstId),
            openConversation(beto, firstId),
        ]);
        await Promise.all([
            operations(ana)
                .getByRole('button', { name: 'Aceptar', exact: true })
                .click(),
            operations(beto)
                .getByRole('button', { name: 'Aceptar', exact: true })
                .click(),
        ]);
        const [anaToast, betoToast] = await Promise.all([
            toastText(ana, ACCEPT_OUTCOME),
            toastText(beto, ACCEPT_OUTCOME),
        ]);
        const winners = [anaToast, betoToast].filter((text) =>
            /Aceptaste la conversación/.test(text),
        ).length;
        const losers = [anaToast, betoToast].filter((text) =>
            /Otra persona ya aceptó esta conversación/.test(text),
        ).length;
        record(
            'simultaneous-accept',
            'mouse x2',
            winners === 1 && losers === 1,
            `ana="${anaToast}" beto="${betoToast}"`,
        );
        const winner = /Aceptaste/.test(anaToast) ? ana : beto;
        await winner.waitForLoadState('networkidle');
        await shot(winner, 'b02_accepted');

        // 4. Internal note, typed with the keyboard, never shown as a message.
        // Flow update (increment C): notes are written in the composer's
        // "Nota interna" mode and shown inline, styled apart from messages.
        await waitToastsGone(winner);
        await winner
            .getByRole('button', { name: 'Nota interna', exact: true })
            .click();
        await winner.locator('#chat-composer').click();
        await winner.keyboard.type('Pide factura a nombre de su empresa');
        await winner.getByRole('button', { name: 'Guardar nota' }).click();
        const note = winner.getByRole('article', {
            name: /Nota interna de/,
        });
        await winner
            .getByText('Pide factura a nombre de su empresa')
            .first()
            .waitFor();
        const inBubble = await winner
            .locator('[data-message-id]', { hasText: 'Pide factura' })
            .count();
        record(
            'internal-note',
            'keyboard',
            inBubble === 0 &&
                ((await note.count()) > 0 ||
                    (await winner
                        .locator('[aria-label^="Nota interna de"]')
                        .count()) > 0),
            `note shown as internal note, message bubbles with it=${inBubble}`,
        );

        // 5. "En espera" is retired (Fase 5): the conversation stays "En
        // atención" and no operation offers to put it on hold or resume it.
        const stillOpen = (await winner.getByText('En atención').count()) > 0;
        const directHold = await operations(winner)
            .getByRole('button', { name: /Poner en espera|Retomar/ })
            .count();
        await winner
            .getByRole('button', { name: 'Más acciones de la conversación' })
            .click();
        await winner.getByRole('menu').waitFor();
        const menuHold = await winner
            .getByRole('menuitem', { name: /Poner en espera|Retomar/ })
            .count();
        await winner.keyboard.press('Escape');
        await winner.getByRole('menu').waitFor({ state: 'detached' });
        record(
            'no-hold-operation',
            'mouse + Esc',
            stillOpen && directHold === 0 && menuHold === 0,
            `open; hold/resume offered: direct=${directHold} menu=${menuHold}`,
        );

        // 6. Transfer to another department's queue with a reason.
        await waitToastsGone(winner);
        await runOperation(winner, 'Transferir');
        await winner.locator('select#transfer-department').waitFor();
        await winner
            .locator('select#transfer-department')
            .selectOption({ label: 'E2E Soporte' });
        await winner
            .locator('textarea#transfer-reason')
            .fill('Consulta técnica');
        await winner
            .getByRole('dialog')
            .getByRole('button', { name: 'Transferir' })
            .click();
        // The list the agent last looked at is restored (without the chat).
        await winner.waitForURL(
            (url) =>
                url.pathname === '/conversations' && !url.searchParams.has('c'),
        );
        const transferToast = await toastText(winner, /transferida/);
        record(
            'transfer-to-queue',
            'mouse',
            /transferida a «Pendientes» de E2E Soporte/.test(transferToast),
            transferToast,
        );

        // 7. The previous agent no longer reaches it by URL.
        const response = await winner.goto(`${BASE}/conversations/${firstId}`);
        await winner.goto(`${BASE}/conversations?c=${firstId}`);
        const unavailable =
            (await winner
                .getByText('La conversación no está disponible')
                .count()) > 0;
        record(
            'direct-url-denied',
            'url',
            response?.status() === 404 && unavailable,
            `status ${response?.status()}; ?c= shows not available=${unavailable}`,
        );

        // 8. The receiving department's agent accepts it with the keyboard.
        const carla = await newPage(browser);
        await login(carla, USERS.carla);
        await carla.goto(`${BASE}/conversations?status=pending`);
        await carla.waitForLoadState('networkidle');
        await listRow(carla, 'Cliente E2E Anuncio').focus();
        await carla.keyboard.press('Enter');
        await carla.waitForURL(new RegExp(`c=${firstId}`));
        const accept = operations(carla).getByRole('button', {
            name: 'Aceptar',
            exact: true,
        });
        await accept.focus();
        await carla.keyboard.press('Enter');
        await toastText(carla, /Aceptaste la conversación/);
        await carla.waitForLoadState('networkidle');
        record(
            'accept-from-list',
            'keyboard',
            (await carla.getByText('En atención').count()) > 0,
            'Enter on the row, Enter on Aceptar',
        );

        // 9. Administrator: the details panel preference persists.
        // Flow update (increment C): the cards view was replaced by the
        // three-panel inbox; the per-user layout preference now is the
        // collapsible details panel.
        const admin = await newPage(browser);
        await login(admin, USERS.admin);
        await openConversation(admin, firstId, 'status=all');
        const detailsToggle = admin.getByRole('button', {
            name: 'Ocultar el panel de detalles',
        });
        await detailsToggle.click();
        await admin.reload();
        await admin.waitForLoadState('networkidle');
        const collapsed =
            (await admin
                .getByRole('button', { name: 'Mostrar el panel de detalles' })
                .count()) > 0 &&
            (await admin
                .getByRole('complementary', {
                    name: 'Información del cliente y de la conversación',
                })
                .count()) === 0;
        await admin
            .getByRole('button', { name: 'Mostrar el panel de detalles' })
            .click();
        record(
            'cards-view-persists',
            'mouse + reload',
            collapsed,
            'details panel hidden after reload (layout preference kept)',
        );
        await shot(admin, 'b03_admin_inbox');

        // 9b. Filters survive opening a conversation and reloading.
        // Phase 4: the inbox opens on "Bandeja general"; every authorized
        // conversation (Carla's included) is the "Todas" view (view=all).
        await admin.goto(`${BASE}/conversations?view=all&status=all`);
        await admin.waitForLoadState('networkidle');
        await admin.getByRole('button', { name: /^Filtros/ }).click();
        await admin
            .locator('#inbox-department')
            .selectOption({ label: 'E2E Soporte' });
        // Phase 5.1: the panel edits a draft applied with «Aplicar».
        await applyInboxFilters(admin);
        await admin.waitForURL(/department=/);
        await admin.waitForLoadState('networkidle');
        const filteredUrl = new URL(admin.url());
        await listRow(admin, 'Cliente E2E Anuncio').click();
        await admin.waitForURL(new RegExp(`c=${firstId}`));
        await admin.reload();
        await admin.waitForLoadState('networkidle');
        const back = new URL(admin.url());
        await admin.getByRole('button', { name: /^Filtros/ }).click();
        const selected = await admin.locator('#inbox-department').inputValue();
        await admin.keyboard.press('Escape');
        record(
            'filters-survive-navigation',
            'mouse',
            back.searchParams.get('department') ===
                filteredUrl.searchParams.get('department') &&
                back.searchParams.get('status') === 'all' &&
                selected === filteredUrl.searchParams.get('department'),
            `${filteredUrl.search} -> ${back.search}`,
        );

        await openConversation(admin, firstId);
        await runOperation(admin, 'Cerrar conversación');
        await admin
            .locator('textarea#conversation-reason')
            .fill('Caso resuelto');
        await admin
            .getByRole('dialog')
            .getByRole('button', { name: 'Cerrar conversación' })
            .click();
        await operations(admin)
            .getByRole('button', { name: 'Reabrir' })
            .waitFor();
        await operations(admin)
            .getByRole('button', { name: 'Reabrir' })
            .click();
        await operations(admin)
            .getByRole('button', { name: 'Reabrir' })
            .waitFor({ state: 'detached' });
        await admin.waitForLoadState('networkidle');
        // The operational history is shown inline in the chat timeline.
        const history = await admin
            .getByRole('log', { name: /Historial de la conversación/ })
            .innerText();
        record(
            'close-reopen-history',
            'mouse',
            ['Aceptada', 'Transferida', 'Cerrada', 'Reabierta'].every((label) =>
                history.includes(label),
            ) && (await admin.getByText(/Ventana abierta/).count()) > 0,
            'timeline complete; window still open (not extended)',
        );
        await shot(admin, 'b04_admin_detail');

        // 10. Stale data: a second tab acting on the old version is told so.
        const staleTab = await admin.context().newPage();
        await openConversation(staleTab, firstId);
        // Fase 5: "Poner en espera" is retired; the admin's change that makes
        // the other tab stale is returning it to the queue. The second tab
        // opens its confirmation first: with E2E_REVERB=1 its screen follows
        // the change live, so only a decision already in progress can be
        // stale (without Reverb the whole tab is).
        await runOperation(staleTab, 'Devolver a Pendientes');
        await staleTab.getByRole('dialog').waitFor();
        await runOperation(admin, 'Devolver a Pendientes');
        await admin
            .getByRole('dialog')
            .getByRole('button', { name: 'Devolver a Pendientes' })
            .click();
        await admin.getByRole('dialog').waitFor({ state: 'detached' });
        await admin.waitForLoadState('networkidle');
        await staleTab
            .getByRole('dialog')
            .getByRole('button', { name: 'Devolver a Pendientes' })
            .click();
        const staleToast = await toastText(
            staleTab,
            /cambió mientras la consultabas|No tienes permiso/,
        );
        record(
            'stale-decision-rejected',
            'two tabs',
            /cambió mientras la consultabas|No tienes permiso/.test(staleToast),
            staleToast,
        );

        // 11. Settings and department distribution configuration.
        await admin.goto(`${BASE}/admin/settings`);
        await admin
            .getByRole('switch', { name: 'Motivo obligatorio al transferir' })
            .click();
        await admin
            .getByRole('button', { name: 'Guardar configuración' })
            .click();
        const settingsToast = await toastText(
            admin,
            /Configuración guardada|No había cambios/,
        );
        await admin.goto(`${BASE}/departments`);
        await admin
            .getByRole('link', { name: /Ver.*E2E Ventas/ })
            .first()
            .click();
        await admin.getByText('Distribución de conversaciones').waitFor();
        await admin.getByText('Asignación automática', { exact: true }).click();
        await admin
            .getByRole('button', { name: 'Guardar distribución' })
            .click();
        const distributionToast = await toastText(admin, /Distribución/);
        record(
            'configuration',
            'mouse',
            /actualizada|guardad/i.test(settingsToast) &&
                /Distribución actualizada/.test(distributionToast),
            `${settingsToast} / ${distributionToast}`,
        );

        // 12. Phone width and dark mode without horizontal overflow.
        const phone = await newPage(browser, {
            viewport: { width: 390, height: 844 },
            colorScheme: 'dark',
        });
        await login(phone, USERS.carla);
        await phone.goto(`${BASE}/conversations`);
        await phone.waitForLoadState('networkidle');
        const listOverflow = await overflow(phone);
        await shot(phone, 'b05_phone_dark_list');
        await openConversation(phone, firstId);
        const detailOverflow = await overflow(phone);
        const dark = await phone.evaluate(() =>
            document.documentElement.classList.contains('dark'),
        );
        await shot(phone, 'b06_phone_dark_detail');
        record(
            'responsive-dark',
            '390px dark',
            listOverflow <= 0 && detailOverflow <= 0 && dark,
            `overflow list=${listOverflow} detail=${detailOverflow} dark=${dark}`,
        );
    } finally {
        await browser.close();
    }

    recordPageProblems(400);
    exitWithSummary();
};

run().catch((error) => {
    console.error(error);
    process.exit(1);
});
