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
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_MODULE ?? 'playwright');

const BASE = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8020';
const SEED = join(
    dirname(fileURLToPath(import.meta.url)),
    'increment-b-seed.php',
);
const SHOTS = process.env.E2E_SCREENSHOTS ?? null;
const PASSWORD = 'e2e-password';
const USERS = {
    admin: 'e2e-admin@example.test',
    ana: 'e2e-ana@example.test',
    beto: 'e2e-beto@example.test',
    carla: 'e2e-carla@example.test',
};

if (SHOTS) {
    mkdirSync(SHOTS, { recursive: true });
}

const ACCEPT_OUTCOME =
    /Aceptaste la conversación|Otra persona ya aceptó esta conversación/;
const results = [];
const problems = { console: [], pageerror: [], server: [] };

function record(id, input, ok, note = '') {
    results.push({ id, input, ok, note });
    console.log(`${ok ? 'PASSED' : 'FAILED'} | ${id} | ${input} | ${note}`);
}

function inbound(waId, name, text) {
    return JSON.parse(
        execFileSync('php', [SEED, 'inbound', waId, name, text], {
            env: process.env,
            encoding: 'utf8',
        }),
    );
}

async function newPage(browser, options = {}) {
    const context = await browser.newContext({
        viewport: { width: 1366, height: 900 },
        locale: 'es-MX',
        ...options,
    });
    const page = await context.newPage();
    page.setDefaultTimeout(15_000);
    page.on('console', (message) => {
        if (
            message.type() === 'error' &&
            !/status of 404/.test(message.text())
        ) {
            problems.console.push(`${message.text()} @ ${page.url()}`);
        }
    });
    page.on('pageerror', (error) => problems.pageerror.push(String(error)));
    page.on('response', (response) => {
        if (response.status() >= 500) {
            problems.server.push(`${response.status()} ${response.url()}`);
        }
    });

    return page;
}

async function login(page, email) {
    await page.goto(`${BASE}/login`);
    await page.locator('#email').click();
    await page.keyboard.type(email);
    await page.locator('#password').click();
    await page.keyboard.type(PASSWORD);
    await page.keyboard.press('Enter');
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
    await page.waitForLoadState('networkidle');
}

async function shot(page, name) {
    if (SHOTS) {
        await page.screenshot({
            path: join(SHOTS, `${name}.png`),
            fullPage: true,
        });
    }
}

/**
 * Text of the toast matching the expected outcome. Several toasts can be on
 * screen at once (newest first), so the expected one is looked up by its text;
 * when it never shows, the visible toasts are returned to explain the failure.
 */
async function toastText(page, expected) {
    const toast = page
        .locator('[data-sonner-toast]', { hasText: expected })
        .first();

    try {
        await toast.waitFor({ timeout: 8_000 });

        return (await toast.innerText()).replace(/\s+/g, ' ');
    } catch {
        const visible = await page
            .locator('[data-sonner-toast]')
            .allInnerTexts();

        return `no toast matching ${expected}; visible: ${JSON.stringify(visible)}`;
    }
}

async function waitToastsGone(page) {
    await page
        .locator('[data-sonner-toast]')
        .first()
        .waitFor({ state: 'detached', timeout: 15_000 })
        .catch(() => {});
}

const overflow = (page) =>
    page.evaluate(
        () => document.documentElement.scrollWidth - window.innerWidth,
    );

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
        const row = ana
            .getByRole('link', { name: 'Cliente E2E Anuncio' })
            .first();
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

        // 3. Two agents accept at the same time: exactly one wins.
        const beto = await newPage(browser);
        await login(beto, USERS.beto);
        await Promise.all([
            ana.goto(`${BASE}/conversations/${firstId}`),
            beto.goto(`${BASE}/conversations/${firstId}`),
        ]);
        await Promise.all([
            ana.waitForLoadState('networkidle'),
            beto.waitForLoadState('networkidle'),
        ]);
        await Promise.all([
            ana.getByRole('button', { name: 'Aceptar' }).click(),
            beto.getByRole('button', { name: 'Aceptar' }).click(),
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
        await waitToastsGone(winner);
        await winner.locator('#new-note').click();
        await winner.keyboard.type('Pide factura a nombre de su empresa');
        await winner.getByRole('button', { name: 'Agregar nota' }).click();
        await winner
            .getByText('Pide factura a nombre de su empresa')
            .first()
            .waitFor();
        const messagesText = await winner
            .getByRole('list', { name: 'Mensajes' })
            .innerText();
        record(
            'internal-note',
            'keyboard',
            !messagesText.includes('Pide factura'),
            'note listed apart from messages',
        );

        // 5. Hold (Escape cancels first), then resume.
        await winner.getByRole('button', { name: 'Poner en espera' }).click();
        await winner.getByRole('dialog').waitFor();
        await winner.keyboard.press('Escape');
        await winner.getByRole('dialog').waitFor({ state: 'detached' });
        const stillOpen = (await winner.getByText('En atención').count()) > 0;
        await winner.getByRole('button', { name: 'Poner en espera' }).click();
        await winner.locator('#conversation-reason').click();
        await winner.keyboard.type('Esperando comprobante de pago');
        await winner
            .getByRole('dialog')
            .getByRole('button', { name: 'Poner en espera' })
            .click();
        await winner
            .getByText(/Esperando comprobante de pago/)
            .first()
            .waitFor();
        const onHold = (await winner.getByText('En espera').count()) > 0;
        await winner.getByRole('button', { name: 'Retomar' }).click();
        await winner.getByRole('button', { name: 'Poner en espera' }).waitFor();
        record(
            'hold-and-resume',
            'mouse + keyboard + Esc',
            stillOpen && onHold,
            'Esc kept state; hold with reason; resumed',
        );

        // 6. Transfer to another department's queue with a reason.
        await winner.getByRole('button', { name: 'Transferir' }).click();
        await winner.locator('#transfer-department').waitFor();
        await winner
            .locator('#transfer-department')
            .selectOption({ label: 'E2E Soporte' });
        await winner.locator('#transfer-reason').fill('Consulta técnica');
        await winner
            .getByRole('dialog')
            .getByRole('button', { name: 'Transferir' })
            .click();
        await winner.waitForURL(/\/conversations$/);
        const transferToast = await toastText(winner, /transferida/);
        record(
            'transfer-to-queue',
            'mouse',
            /transferida a la cola de E2E Soporte/.test(transferToast),
            transferToast,
        );

        // 7. The previous agent no longer reaches it by URL.
        const response = await winner.goto(`${BASE}/conversations/${firstId}`);
        record(
            'direct-url-denied',
            'url',
            response?.status() === 404,
            `status ${response?.status()}`,
        );

        // 8. The receiving department's agent accepts it from the list with the keyboard.
        const carla = await newPage(browser);
        await login(carla, USERS.carla);
        await carla.goto(`${BASE}/conversations`);
        const accept = carla.getByRole('button', {
            name: 'Aceptar Cliente E2E Anuncio',
        });
        await accept.focus();
        await carla.keyboard.press('Enter');
        await carla.waitForURL(new RegExp(`/conversations/${firstId}$`));
        record(
            'accept-from-list',
            'keyboard',
            (await carla.getByText('En atención').count()) > 0,
            'Enter on the row action',
        );

        // 9. Administrator: cards view persists, close and reopen, history.
        const admin = await newPage(browser);
        await login(admin, USERS.admin);
        await admin.goto(`${BASE}/conversations?status=all`);
        await admin.getByRole('button', { name: 'Vista de tarjetas' }).click();
        await admin.reload();
        await admin.waitForLoadState('networkidle');
        const cards =
            (await admin
                .getByRole('button', { name: 'Vista de tarjetas' })
                .getAttribute('aria-pressed')) === 'true' &&
            (await admin.locator('article').count()) > 0;
        record(
            'cards-view-persists',
            'mouse + reload',
            cards,
            'cards kept after reload',
        );
        await shot(admin, 'b03_admin_cards');

        // 9b. Filters survive opening a conversation and returning through the breadcrumb.
        await admin
            .getByRole('combobox', { name: 'Filtrar por departamento' })
            .selectOption({ label: 'E2E Soporte' });
        await admin.waitForURL(/department=/);
        await admin.waitForLoadState('networkidle');
        const filteredUrl = new URL(admin.url());
        await admin
            .locator('article')
            .getByRole('link', { name: 'Cliente E2E Anuncio' })
            .first()
            .click();
        await admin.waitForURL(new RegExp(`/conversations/${firstId}$`));
        await admin
            .getByRole('navigation', { name: /breadcrumb/i })
            .getByRole('link', { name: 'Conversaciones' })
            .click();
        await admin.waitForURL(/\/conversations\?/);
        const back = new URL(admin.url());
        record(
            'filters-survive-navigation',
            'mouse',
            back.searchParams.get('department') ===
                filteredUrl.searchParams.get('department') &&
                back.searchParams.get('status') === 'all' &&
                (await admin
                    .getByRole('combobox', { name: 'Filtrar por departamento' })
                    .inputValue()) ===
                    filteredUrl.searchParams.get('department'),
            `${filteredUrl.search} -> ${back.search}`,
        );

        await admin.goto(`${BASE}/conversations/${firstId}`);
        await admin.getByRole('button', { name: 'Cerrar' }).click();
        await admin.locator('#conversation-reason').fill('Caso resuelto');
        await admin
            .getByRole('dialog')
            .getByRole('button', { name: 'Cerrar conversación' })
            .click();
        await admin.getByRole('button', { name: 'Reabrir' }).waitFor();
        await admin.getByRole('button', { name: 'Reabrir' }).click();
        await admin
            .getByRole('button', { name: 'Devolver a la cola' })
            .waitFor();
        const history = await admin
            .getByRole('list', { name: 'Historial operativo' })
            .innerText();
        record(
            'close-reopen-history',
            'mouse',
            [
                'Aceptada',
                'Puesta en espera',
                'Atención retomada',
                'Transferida',
                'Cerrada',
                'Reabierta',
            ].every((label) => history.includes(label)) &&
                (await admin.getByText('Ventana abierta').count()) > 0,
            'timeline complete; window still open (not extended)',
        );
        await shot(admin, 'b04_admin_detail');

        // 10. Stale data: a second tab acting on the old version is told so.
        const staleTab = await admin.context().newPage();
        await staleTab.goto(`${BASE}/conversations/${firstId}`);
        await admin.getByRole('button', { name: 'Poner en espera' }).click();
        await admin
            .getByRole('dialog')
            .getByRole('button', { name: 'Poner en espera' })
            .click();
        await admin.getByRole('button', { name: 'Retomar' }).waitFor();
        await staleTab
            .getByRole('button', { name: 'Devolver a la cola' })
            .click();
        await staleTab
            .getByRole('dialog')
            .getByRole('button', { name: 'Devolver a la cola' })
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
        const listOverflow = await overflow(phone);
        await shot(phone, 'b05_phone_dark_list');
        await phone.goto(`${BASE}/conversations/${firstId}`);
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

    record(
        'no-page-errors',
        'console',
        problems.pageerror.length === 0 && problems.console.length === 0,
        JSON.stringify(problems.pageerror.concat(problems.console)).slice(
            0,
            400,
        ),
    );
    record(
        'no-server-errors',
        'network',
        problems.server.length === 0,
        problems.server.join(', '),
    );

    const failed = results.filter((result) => !result.ok);
    console.log(
        `\n${results.length - failed.length}/${results.length} scenarios passed`,
    );
    process.exit(failed.length === 0 ? 0 : 1);
};

run().catch((error) => {
    console.error(error);
    process.exit(1);
});
