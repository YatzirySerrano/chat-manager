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
import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import {
    BASE,
    HERE,
    SHOTS,
    USERS,
    chromium,
    exitWithSummary,
    listRow,
    login,
    openConversation,
    openInboxView,
    openPage,
    operations,
    overflow,
    php,
    recordPageProblems,
    runOperation,
    shot,
    signedWebhook,
    step as runStep,
    toastText,
} from './lib.mjs';

const SEED = join(HERE, 'finalizacion-seed.php');
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

/** A failed step leaves a full-page screenshot of the page it used. */
const step = (id, input, page, scenario) =>
    runStep(id, input, scenario, () => shot(page, `fail-${id}`));

function seed(...args) {
    return php(SEED, ...args);
}

const data = JSON.parse(seed('setup'));

/** Posts a webhook body signed like Meta does (X-Hub-Signature-256). */
const webhook = (body, secret = data.app_secret) =>
    signedWebhook(data.webhook_key, secret, body);

const newPage = (browser, options = {}) =>
    openPage(browser, {
        context: { acceptDownloads: true, ...options },
        // Reverb is optional: without it the page falls back to polling.
        ignoreConsole: /status of (403|404|409|422|429)|WebSocket/,
        trackToasts: true,
    });

const bubble = (page, text) =>
    page.locator('[data-message-id]', { hasText: text }).last();

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
    await page
        .getByRole('button', { name: 'Responder al cliente', exact: true })
        .click();
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
                // ?view=queue stays an alias of the general inbox with status=pending.
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
                const delivered = await waitStatus(
                    ana,
                    text,
                    /Entregado|Leído/,
                );
                // The badge comes with the delivery result (simulated
                // transport), so it is read once the status arrived.
                const simulated =
                    (await bubble(ana, text).getByText('Simulación').count()) >
                    0;
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
                    'Vista previa de foto-e2e.png',
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
            await ana
                .getByRole('button', { name: 'Nota interna', exact: true })
                .click();
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
                // Phase 5: templates are an administrative send
                // (templates.send, global scope): the agent gets neither
                // the free-form composer nor the template button, and the
                // Administrador sends it on the conversation they attend.
                await openConversation(ana, data.conversations.closed);
                const blocked =
                    (await ana.locator('#chat-composer').count()) === 0;
                const agentTemplateButton = await ana
                    .getByRole('button', { name: 'Enviar plantilla' })
                    .count();
                await openConversation(
                    administrator,
                    data.conversations.closed_administrator,
                );
                await administrator
                    .getByRole('button', { name: 'Enviar plantilla' })
                    .click();
                const dialog = administrator.getByRole('dialog');
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
                await bubble(administrator, 'retomamos tu solicitud').waitFor();

                return [
                    blocked && agentTemplateButton === 0,
                    `agent: free-form composer hidden=${blocked}, template button=${agentTemplateButton}; administrator sent the template`,
                ];
            },
        );

        await step('admin-inbox', 'render', administrator, async () => {
            await administrator.goto(`${BASE}/conversations`);
            await administrator.waitForLoadState('networkidle');
            // Phase 5: the inbox opens on "Bandeja general" (pending ones and
            // the person's own); every authorized conversation is the
            // "Todas las visibles" view of the "Filtros" panel.
            await openInboxView(administrator, 'Todas');
            await administrator.waitForLoadState('networkidle');
            await administrator
                .getByRole('button', { name: /^Filtros/ })
                .click();
            const all = await administrator
                .getByRole('radio', { name: 'Todas las visibles', exact: true })
                .count();
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

    recordPageProblems(600);
    exitWithSummary();
};

run().catch((error) => {
    console.error(error);
    process.exit(1);
});
