/*
 * Browser scenarios of the finalization (increments C, D, E, F): login,
 * dashboard, navigation and permissions per role, agent and administrator
 * inboxes, signed inbound webhooks, accept, replies through the simulated
 * WhatsApp transport with status ticks, quick replies, attachments, notes,
 * transfer, close and reopen, failed message retry, templates when the 24 h
 * window is closed, internal team chat, reports with filters, an Excel
 * export, the templates and system pages, the notifications bell, dark mode
 * and the phone layout.
 *
 * SIMULACIÓN: Meta is never contacted. Customer messages and delivery
 * statuses are webhook bodies in the documented WhatsApp Cloud API format,
 * signed with a throwaway app secret of the testing database and posted to
 * the local server; replies go through WHATSAPP_TRANSPORT=simulated.
 *
 * Real Chromium, real mouse and keyboard. Run it through the runner, which
 * rebuilds the testing database (chatmanager_testing) and starts the server:
 *
 *   WHATSAPP_TRANSPORT=simulated QUEUE_CONNECTION=sync \
 *       php tests/e2e/run.php finalizacion.mjs
 *
 * (optionally with E2E_REVERB=1 to also run a Reverb server). Environment set
 * by the runner: E2E_BASE_URL, APP_ENV=testing, PLAYWRIGHT_MODULE, optional
 * E2E_SCREENSHOTS (screenshots of failed steps and downloaded files).
 */
import { createHmac } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { mkdirSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT_MODULE ?? 'playwright');

const BASE = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8020';
const HERE = dirname(fileURLToPath(import.meta.url));
const SEED = join(HERE, 'finalizacion-seed.php');
const SHOTS = process.env.E2E_SCREENSHOTS ?? null;
const PASSWORD = 'e2e-password';
const USERS = {
    superadmin: 'e2e-admin@example.test',
    administrator: 'e2e-administrador@example.test',
    ana: 'e2e-ana@example.test',
    beto: 'e2e-beto@example.test',
    carla: 'e2e-carla@example.test',
};
const PNG =
    'iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAIAAABLbSncAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFElEQVQImWOU64liwAaYsIoOWgkAz+QBFArsdHsAAAAASUVORK5CYII=';

if (
    process.env.WHATSAPP_TRANSPORT !== 'simulated' ||
    process.env.QUEUE_CONNECTION !== 'sync'
) {
    console.error(
        'Run with WHATSAPP_TRANSPORT=simulated QUEUE_CONNECTION=sync (see the header).',
    );
    process.exit(2);
}

if (SHOTS) {
    mkdirSync(SHOTS, { recursive: true });
}

const results = [];
const problems = { console: [], pageerror: [], server: [] };
const pages = new Set();

function record(id, input, ok, note = '') {
    results.push({ id, ok, note });
    console.log(`${ok ? 'PASSED' : 'FAILED'} | ${id} | ${input} | ${note}`);
}

async function step(id, input, page, scenario) {
    try {
        const [ok, note] = await scenario();
        record(id, input, ok, note);

        if (!ok) {
            await failureShot(page, id);
        }
    } catch (error) {
        record(id, input, false, String(error).split('\n')[0]);
        await failureShot(page, id);
    }
}

async function failureShot(page, id) {
    if (SHOTS && page && !page.isClosed()) {
        await page
            .screenshot({ path: join(SHOTS, `fail-${id}.png`), fullPage: true })
            .catch(() => {});
    }
}

function seed(...args) {
    return execFileSync('php', [SEED, ...args], {
        env: process.env,
        encoding: 'utf8',
    });
}

const data = JSON.parse(seed('setup'));

/** Posts a webhook body signed like Meta does (X-Hub-Signature-256). */
async function webhook(body, secret = data.app_secret) {
    const signature = createHmac('sha256', secret).update(body).digest('hex');
    const response = await fetch(
        `${BASE}/webhooks/whatsapp/${data.webhook_key}`,
        {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Hub-Signature-256': `sha256=${signature}`,
            },
            body,
        },
    );

    return response.status;
}

async function newPage(browser, options = {}) {
    const context = await browser.newContext({
        viewport: { width: 1366, height: 900 },
        locale: 'es-MX',
        acceptDownloads: true,
        ...options,
    });
    const page = await context.newPage();
    // Every toast shown on the page, also the ones already gone, to tell a
    // missing toast from one that disappeared before it was read.
    await page.addInitScript(() => {
        window.__toasts = [];
        new MutationObserver(() => {
            for (const toast of document.querySelectorAll(
                '[data-sonner-toast]',
            )) {
                const text = toast.textContent.trim();

                if (text && !window.__toasts.includes(text)) {
                    window.__toasts.push(text);
                }
            }
        }).observe(document, { childList: true, subtree: true });
    });
    page.setDefaultTimeout(15_000);
    pages.add(page);
    page.on('console', (message) => {
        if (
            message.type() === 'error' &&
            !/status of (403|404|409|422|429)/.test(message.text()) &&
            // Reverb is optional: without it the page falls back to polling.
            !/WebSocket/.test(message.text())
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
    await page
        .waitForURL((url) => !url.pathname.startsWith('/login'))
        .catch(async () => {
            const typed = await page.locator('#email').inputValue();
            const errors = await page
                .locator('[role="alert"], .text-destructive, p.text-red-600')
                .allInnerTexts();

            throw new Error(
                `login of ${email} stayed on /login (email field="${typed}", errors=${JSON.stringify(errors)})`,
            );
        });
    await page.waitForLoadState('networkidle');
}

async function toastText(page, expected) {
    const toast = page
        .locator('[data-sonner-toast]', { hasText: expected })
        .first();

    try {
        await toast.waitFor({ timeout: 10_000 });

        return (await toast.innerText()).replace(/\s+/g, ' ');
    } catch {
        const visible = await page
            .locator('[data-sonner-toast]')
            .allInnerTexts();

        const seen = await page
            .evaluate(() => window.__toasts ?? [])
            .catch(() => []);

        return `no toast matching ${expected}; visible: ${JSON.stringify(visible)}; seen: ${JSON.stringify(seen.slice(-4))}`;
    }
}

const overflow = (page) =>
    page.evaluate(
        () => document.documentElement.scrollWidth - window.innerWidth,
    );

const listRow = (page, name) =>
    page.locator('[data-inbox-item]', { hasText: name }).first();

const operations = (page) =>
    page.getByRole('group', { name: 'Operaciones de la conversación' });

const bubble = (page, text) =>
    page.locator('[data-message-id]', { hasText: text }).last();

async function runOperation(page, label) {
    const direct = operations(page).getByRole('button', {
        name: label,
        exact: true,
    });

    if ((await direct.count()) > 0) {
        await direct.click();

        return;
    }

    await page
        .getByRole('button', { name: 'Más acciones de la conversación' })
        .click();
    await page.getByRole('menuitem', { name: label, exact: true }).click();
}

async function openConversation(page, id) {
    await page.goto(`${BASE}/conversations?c=${id}`);
    await page.waitForLoadState('networkidle');
}

async function becomeAvailable(page) {
    const toggle = page.getByRole('switch', {
        name: 'Disponible para aceptar y recibir conversaciones',
    });

    if ((await toggle.getAttribute('aria-checked')) !== 'true') {
        await toggle.click();
        await toastText(page, /Ahora estás disponible/);
        await page.waitForLoadState('networkidle');
    }

    return (await toggle.getAttribute('aria-checked')) === 'true';
}

/** Accessible status of an outbound bubble ("Entregado", "Leído", …). */
async function waitStatus(page, text, expected, timeout = 30_000) {
    const deadline = Date.now() + timeout;
    let status = '';

    while (Date.now() < deadline) {
        status = await bubble(page, text)
            .locator('span[title]')
            .last()
            .getAttribute('title')
            .catch(() => '');

        if (expected.test(status ?? '')) {
            return status;
        }

        await page.waitForTimeout(1_000);
    }

    return status;
}

async function sendReply(page, text) {
    await page.getByRole('tab', { name: 'Responder al cliente' }).click();
    await page.locator('#chat-composer').click();
    await page.keyboard.type(text);
    await page.keyboard.press('Enter');
    await bubble(page, text).waitFor();
}

const run = async () => {
    const browser = await chromium.launch();
    const customer = 'Cliente Final E2E';
    let finalId = null;

    try {
        const ana = await newPage(browser);

        // Login, dashboard and navigation of an agent.
        await step('login', 'keyboard', ana, async () => {
            await login(ana, USERS.ana);

            return [new URL(ana.url()).pathname === '/dashboard', ana.url()];
        });

        await step('dashboard-agent', 'render', ana, async () => {
            const heading = await ana
                .getByRole('heading', { name: /^Hola,/ })
                .count();

            return [heading > 0, `greeting headings=${heading}`];
        });

        await step('navigation-by-role-agent', 'render', ana, async () => {
            const link = (name) =>
                ana.getByRole('link', { name, exact: true }).count();
            const present = {
                conversaciones: await link('Conversaciones'),
                chat: await link('Chat interno'),
            };
            const absent = {
                usuarios: await link('Usuarios'),
                roles: await link('Roles y permisos'),
                auditoria: await link('Auditoría'),
                sistema: await link('Sistema'),
                conexiones: await link('Conexiones'),
            };

            return [
                Object.values(present).every((count) => count > 0) &&
                    Object.values(absent).every((count) => count === 0),
                JSON.stringify({ present, absent }),
            ];
        });

        await step('agent-cannot-reach-admin-pages', 'url', ana, async () => {
            const statuses = {};

            for (const path of [
                '/admin/users',
                '/admin/roles',
                '/admin/audit',
                '/admin/system',
                '/admin/settings',
                '/connections',
                '/templates',
            ]) {
                const response = await ana.request.get(`${BASE}${path}`, {
                    maxRedirects: 0,
                });
                statuses[path] = response.status();
            }

            return [
                Object.values(statuses).every((status) =>
                    [403, 404].includes(status),
                ),
                JSON.stringify(statuses),
            ];
        });

        await step(
            'agent-sees-only-allowed-conversations',
            'url + search',
            ana,
            async () => {
                await ana.goto(`${BASE}/conversations`);
                await ana.waitForLoadState('networkidle');
                const own =
                    (await listRow(ana, 'Cliente Reintento').count()) > 0 &&
                    (await listRow(ana, 'Cliente Ventana Cerrada').count()) > 0;
                await ana
                    .getByRole('searchbox', { name: 'Buscar conversaciones' })
                    .fill('Soporte Privado');
                await ana.waitForURL(/search=/);
                await ana.waitForLoadState('networkidle');
                const found = await listRow(
                    ana,
                    'Cliente Soporte Privado',
                ).count();
                const direct = await ana.request.get(
                    `${BASE}/conversations/${data.conversations.private}`,
                    { maxRedirects: 0 },
                );
                await openConversation(ana, data.conversations.private);
                const hidden =
                    (await ana
                        .getByText('La conversación no está disponible')
                        .count()) > 0;

                return [
                    own && found === 0 && direct.status() === 404 && hidden,
                    `own=${own} searchHits=${found} direct=${direct.status()} ?c=hidden=${hidden}`,
                ];
            },
        );

        // A customer writes: signed webhook (simulated) reaches the queue.
        await step(
            'inbound-signed-webhook',
            'webhook (simulated)',
            ana,
            async () => {
                await ana.goto(`${BASE}/conversations?view=queue`);
                await ana.waitForLoadState('networkidle');
                const body = seed(
                    'message',
                    '5215511117777',
                    customer,
                    'Hola, quiero cotizar',
                ).trim();
                const forged = await webhook(body, 'not-the-app-secret');
                const accepted = await webhook(body);
                const row = listRow(ana, customer);
                await row.waitFor({ timeout: 30_000 });

                return [
                    forged >= 400 && accepted === 200,
                    `bad signature=${forged} signed=${accepted}; row appeared in the queue without reload`,
                ];
            },
        );

        await step('accept', 'mouse', ana, async () => {
            await becomeAvailable(ana);
            await listRow(ana, customer).click();
            await ana.waitForURL(/c=\d+/);
            finalId = Number(new URL(ana.url()).searchParams.get('c'));
            await operations(ana)
                .getByRole('button', { name: 'Aceptar', exact: true })
                .click();
            const toast = await toastText(ana, /Aceptaste/);
            await ana.getByText('En atención').first().waitFor();

            return [/Aceptaste la conversación/.test(toast), toast];
        });

        await step(
            'send-simulated-with-status-ticks',
            'keyboard + webhook (simulated)',
            ana,
            async () => {
                const text = 'Con gusto te ayudo con la cotización';
                await sendReply(ana, text);
                const simulated =
                    (await bubble(ana, text).getByText('Simulación').count()) >
                    0;
                const delivered = await waitStatus(
                    ana,
                    text,
                    /Entregado|Leído/,
                );
                const id = await bubble(ana, text).getAttribute(
                    'data-message-id',
                );
                const status = await webhook(seed('status', id, 'read').trim());
                const read = await waitStatus(ana, text, /Leído/);

                return [
                    simulated &&
                        /Entregado|Leído/.test(delivered) &&
                        status === 200 &&
                        read === 'Leído',
                    `badge=${simulated} after send="${delivered}" read webhook=${status} -> "${read}"`,
                ];
            },
        );

        await step('quick-reply-slash', 'keyboard', ana, async () => {
            await ana.locator('#chat-composer').click();
            await ana.keyboard.type('/saludo');
            const list = ana.getByRole('listbox', {
                name: 'Respuestas rápidas disponibles',
            });
            await list.getByRole('option').first().waitFor();
            await ana.keyboard.press('Enter');
            const value = await ana.locator('#chat-composer').inputValue();
            await ana.keyboard.press('Enter');
            await bubble(ana, 'gracias por escribirnos').waitFor();

            return [
                value.includes(`Hola ${customer}, gracias por escribirnos.`),
                `inserted="${value.trim()}"`,
            ];
        });

        await step(
            'attachment-upload-preview',
            'file + mouse',
            ana,
            async () => {
                const dir = SHOTS ?? mkdtempSync(join(tmpdir(), 'e2e-'));
                const file = join(dir, 'foto-e2e.png');
                writeFileSync(file, Buffer.from(PNG, 'base64'));
                await ana.locator('input[type="file"]').setInputFiles(file);
                const preview = ana.getByAltText(
                    'Vista previa del archivo adjunto',
                );
                await preview.waitFor();
                await ana.locator('#chat-composer').click();
                await ana.keyboard.type('Foto del producto');
                await ana
                    .getByRole('button', { name: 'Enviar', exact: true })
                    .click();
                const sent = bubble(ana, 'Foto del producto');
                await sent.waitFor();
                const image = sent.getByRole('link', { name: /Abrir imagen/ });
                await image.waitFor();
                const href = await image.getAttribute('href');
                const download = await ana.request.get(
                    new URL(href, BASE).href,
                );

                return [
                    download.status() === 200 &&
                        download.headers()['content-type'] === 'image/png' &&
                        download.headers()['x-content-type-options'] ===
                            'nosniff',
                    `preview shown; served ${download.status()} ${download.headers()['content-type']}`,
                ];
            },
        );

        await step('internal-note', 'keyboard', ana, async () => {
            await ana.getByRole('tab', { name: 'Nota interna' }).click();
            await ana.locator('#chat-composer').click();
            await ana.keyboard.type('Cliente pide factura');
            await ana.getByRole('button', { name: 'Guardar nota' }).click();
            await ana
                .locator('[aria-label^="Nota interna de"]', {
                    hasText: 'Cliente pide factura',
                })
                .waitFor();
            const asMessage = await ana
                .locator('[data-message-id]', {
                    hasText: 'Cliente pide factura',
                })
                .count();

            return [asMessage === 0, `shown as note; as message=${asMessage}`];
        });

        await step('transfer', 'mouse + keyboard', ana, async () => {
            await runOperation(ana, 'Transferir');
            await ana
                .locator('select#transfer-department')
                .selectOption({ label: 'E2E Soporte' });
            await ana
                .locator('textarea#transfer-reason')
                .fill('Lo atiende soporte');
            await ana
                .getByRole('dialog')
                .getByRole('button', { name: 'Transferir' })
                .click();
            const toast = await toastText(ana, /transferida/);

            return [/E2E Soporte/.test(toast), toast];
        });

        const carla = await newPage(browser);

        await step('close', 'mouse + keyboard', carla, async () => {
            await login(carla, USERS.carla);
            await carla.goto(`${BASE}/conversations?view=queue`);
            await becomeAvailable(carla);
            await openConversation(carla, finalId);
            await operations(carla)
                .getByRole('button', { name: 'Aceptar', exact: true })
                .click();
            await toastText(carla, /Aceptaste/);
            await runOperation(carla, 'Cerrar conversación');
            await carla
                .locator('textarea#conversation-reason')
                .fill('Cotización enviada');
            await carla
                .getByRole('dialog')
                .getByRole('button', { name: 'Cerrar conversación' })
                .click();
            await carla.getByRole('dialog').waitFor({ state: 'detached' });
            await carla.waitForLoadState('networkidle');
            const status = await carla
                .getByRole('region', { name: `Conversación con ${customer}` })
                .locator('header')
                .first()
                .innerText();

            return [/Cerrada/.test(status), status.replace(/\s+/g, ' ')];
        });

        const administrator = await newPage(browser);

        await step('reopen', 'mouse', administrator, async () => {
            await login(administrator, USERS.administrator);
            await openConversation(administrator, finalId);
            await operations(administrator)
                .getByRole('button', { name: 'Reabrir' })
                .click();
            await operations(administrator)
                .getByRole('button', { name: 'Reabrir' })
                .waitFor({ state: 'detached' });
            await administrator.waitForLoadState('networkidle');
            const history = await administrator
                .getByRole('log', { name: /Historial de la conversación/ })
                .innerText();

            return [
                ['Aceptada', 'Transferida', 'Cerrada', 'Reabierta'].every(
                    (label) => history.includes(label),
                ),
                'timeline shows accepted, transferred, closed, reopened',
            ];
        });

        await step('failed-message-retry', 'mouse', ana, async () => {
            await openConversation(ana, data.conversations.retry);
            const failed = bubble(ana, 'Te confirmo tu pedido');
            const reason = (await failed.innerText()).replace(/\s+/g, ' ');
            await failed.getByRole('button', { name: 'Reintentar' }).click();
            const status = await waitStatus(
                ana,
                'Te confirmo tu pedido',
                /Entregado|Leído|Enviado|Aceptado/,
            );

            return [
                data.failed_message.status === 'failed' &&
                    /Entregado|Leído|Enviado|Aceptado/.test(status),
                `seeded ${data.failed_message.status}/${data.failed_message.error}; "${reason.slice(0, 80)}" -> "${status}"`,
            ];
        });

        await step(
            'template-when-window-closed',
            'mouse + keyboard',
            ana,
            async () => {
                await openConversation(ana, data.conversations.closed);
                const blocked =
                    (await ana.locator('#chat-composer').count()) === 0;
                await ana
                    .getByRole('button', { name: 'Enviar plantilla' })
                    .click();
                const dialog = ana.getByRole('dialog');
                await dialog
                    .getByRole('option', { name: new RegExp(data.template) })
                    .click();
                await dialog
                    .locator('input[id^="template-"]')
                    .first()
                    .fill('Ana');
                await dialog
                    .getByRole('button', { name: 'Enviar plantilla' })
                    .click();
                await dialog.waitFor({ state: 'detached' });
                await bubble(ana, 'retomamos tu solicitud').waitFor();

                return [
                    blocked,
                    `free-form composer hidden=${blocked}; template sent`,
                ];
            },
        );

        await step('admin-inbox', 'render', administrator, async () => {
            await administrator.goto(`${BASE}/conversations`);
            await administrator.waitForLoadState('networkidle');
            const all = await administrator
                .getByRole('button', { name: /^Todas/ })
                .count();
            await administrator
                .getByRole('button', { name: /^Filtros/ })
                .click();
            const agentFilter = await administrator
                .locator('#inbox-agent')
                .count();
            await administrator.keyboard.press('Escape');
            const both =
                (await listRow(
                    administrator,
                    'Cliente Soporte Privado',
                ).count()) > 0 &&
                (await listRow(administrator, 'Cliente Reintento').count()) > 0;
            const hasUsers = await administrator
                .getByRole('link', { name: 'Usuarios', exact: true })
                .count();

            return [
                all > 0 && agentFilter === 1 && both && hasUsers > 0,
                `views Todas=${all} agentFilter=${agentFilter} bothDepartments=${both} usersLink=${hasUsers}`,
            ];
        });

        await step('reports-with-filters', 'mouse', administrator, async () => {
            await administrator.goto(`${BASE}/reports`);
            await administrator.waitForLoadState('networkidle');
            const period = administrator
                .locator('label', { hasText: /^Periodo/ })
                .locator('select');
            const options = await period
                .locator('option')
                .evaluateAll((items) => items.map((item) => item.value));
            const current = await period.inputValue();
            const next = options.find(
                (value) => value !== current && value !== 'custom',
            );
            await period.selectOption(next);
            await administrator.waitForURL((url) => url.search.includes(next));
            await administrator.waitForLoadState('networkidle');
            await administrator
                .locator('label', { hasText: /^Departamento/ })
                .locator('select')
                .selectOption({ label: 'E2E Ventas' });
            await administrator.waitForURL(/department/);
            await administrator.waitForLoadState('networkidle');

            return [
                (await administrator
                    .getByRole('heading', { name: 'Reportes operativos' })
                    .count()) > 0,
                new URL(administrator.url()).search,
            ];
        });

        await step('export-xlsx', 'mouse', administrator, async () => {
            await administrator
                .getByRole('button', { name: 'Exportar reporte' })
                .click();
            const [file] = await Promise.all([
                administrator.waitForEvent('download', { timeout: 60_000 }),
                administrator
                    .getByRole('menuitem', { name: 'Excel (.xlsx)' })
                    .click(),
            ]);
            const path = SHOTS
                ? join(SHOTS, 'reporte.xlsx')
                : await file.path();

            if (SHOTS) {
                await file.saveAs(path);
            }

            const zip = readFileSync(path).subarray(0, 2).toString() === 'PK';

            return [zip, file.suggestedFilename()];
        });

        const superadmin = await newPage(browser);

        await step('templates-page', 'render', superadmin, async () => {
            await login(superadmin, USERS.superadmin);
            await superadmin.goto(`${BASE}/templates`);
            await superadmin.waitForLoadState('networkidle');
            const listed = await superadmin.getByText(data.template).count();

            return [listed > 0, `${data.template} listed=${listed}`];
        });

        await step('admin-system-page', 'render', superadmin, async () => {
            const response = await superadmin.goto(`${BASE}/admin/system`);
            await superadmin.waitForLoadState('networkidle');
            const heading = await superadmin
                .getByRole('heading', { name: 'Sistema', exact: true })
                .count();
            const simulated = await superadmin.getByText(/simulaci/i).count();

            return [
                response?.status() === 200 && heading > 0,
                `status=${response?.status()} simulation labelled=${simulated > 0}`,
            ];
        });

        const beto = await newPage(browser);

        await step('team-chat-direct', 'mouse + keyboard', ana, async () => {
            await ana.goto(`${BASE}/team-chat`);
            await ana.waitForLoadState('networkidle');
            const single = ana.getByRole('button', { name: 'Nuevo chat' });

            if ((await single.count()) > 0) {
                await single.first().click();
            } else {
                await ana
                    .getByRole('button', { name: 'Nuevo', exact: true })
                    .click();
                await ana
                    .getByRole('menuitem', { name: 'Chat privado' })
                    .click();
            }

            const dialog = ana.getByRole('dialog');
            await dialog.getByPlaceholder('Buscar por nombre').fill('Beto');
            await dialog.getByRole('option', { name: /E2E Beto/ }).click();
            await dialog.waitFor({ state: 'detached' });
            await ana.getByPlaceholder('Escribe un mensaje interno…').click();
            await ana.keyboard.type('Hola Beto, ¿me apoyas con un cliente?');
            await ana.keyboard.press('Enter');
            await ana
                .getByText('Hola Beto, ¿me apoyas con un cliente?')
                .first()
                .waitFor();
            await login(beto, USERS.beto);
            await beto.goto(`${BASE}/team-chat`);
            await beto.waitForLoadState('networkidle');
            const listed = await beto
                .getByRole('navigation', { name: 'Lista de chats' })
                .getByText('E2E Ana')
                .count();

            return [listed > 0, `Beto sees the chat with Ana=${listed > 0}`];
        });

        await step('notifications-bell', 'mouse', beto, async () => {
            await beto.goto(`${BASE}/dashboard`);
            await beto.waitForLoadState('networkidle');
            const bell = beto.getByRole('button', { name: /^Notificaciones/ });
            const label = await bell.getAttribute('aria-label');
            await bell.click();
            const item = await beto.getByText(/E2E Ana|mensaje/i).count();

            return [
                /sin leer/.test(label ?? '') && item > 0,
                `bell="${label}" items mentioning the message=${item}`,
            ];
        });

        await step('dark-mode-toggle', 'mouse + reload', ana, async () => {
            await ana.goto(`${BASE}/dashboard`);
            await ana
                .getByRole('button', { name: 'Cambiar a modo oscuro' })
                .click();
            const dark = await ana.evaluate(() =>
                document.documentElement.classList.contains('dark'),
            );
            await ana.reload();
            await ana.waitForLoadState('networkidle');
            const kept = await ana.evaluate(() =>
                document.documentElement.classList.contains('dark'),
            );
            await ana
                .getByRole('button', { name: 'Cambiar a modo claro' })
                .click();
            const light = await ana.evaluate(
                () => !document.documentElement.classList.contains('dark'),
            );

            return [
                dark && kept && light,
                `dark=${dark} afterReload=${kept} backToLight=${light}`,
            ];
        });

        await step('responsive-phone', '390x844 touch', null, async () => {
            const phone = await newPage(browser, {
                viewport: { width: 390, height: 844 },
                hasTouch: true,
                isMobile: true,
            });
            await login(phone, USERS.ana);
            await phone.goto(`${BASE}/conversations`);
            await phone.waitForLoadState('networkidle');
            const list = phone.getByRole('region', {
                name: 'Lista de conversaciones',
            });
            const listOverflow = await overflow(phone);
            await listRow(phone, 'Cliente Reintento').tap();
            const chat = phone.getByRole('region', {
                name: 'Conversación con Cliente Reintento',
            });
            await chat.waitFor();
            const listHidden = !(await list.isVisible());
            const chatOverflow = await overflow(phone);
            await phone
                .getByRole('button', {
                    name: 'Volver a la lista de conversaciones',
                })
                .tap();
            await list.waitFor();
            const chatHidden = !(await chat.isVisible().catch(() => false));

            if (SHOTS) {
                await phone.screenshot({
                    path: join(SHOTS, 'final-phone-list.png'),
                });
            }

            return [
                listOverflow <= 0 &&
                    chatOverflow <= 0 &&
                    listHidden &&
                    chatHidden,
                `overflow list=${listOverflow} chat=${chatOverflow}; list hidden in chat=${listHidden}; chat hidden after back=${chatHidden}`,
            ];
        });
    } finally {
        await browser.close();
    }

    record(
        'no-page-errors',
        'console',
        problems.pageerror.length === 0 && problems.console.length === 0,
        JSON.stringify(problems.pageerror.concat(problems.console)).slice(
            0,
            600,
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
