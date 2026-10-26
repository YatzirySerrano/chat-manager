/*
 * Phase 5.2 Whaticket history importer (W5b): the import wizard, the
 * inventory classification, the summary and confirmation, the final report
 * and the read-only consultation of the imported history. Signed in as the
 * superadministrator of tests/e2e/increment-b-seed.php; E2E Ana (Agente)
 * is the unauthorized account. Every file is synthetic
 * (tests/fixtures/whaticket + the ZIP built by tests/e2e/historial-seed.php).
 *
 *   unauthorized-*      an agent gets 403 on the importer and the history
 *   create-batch        a batch is created from the index (format note shown)
 *   upload-*            chats CSV, messages CSV, contacts CSV, media ZIP and
 *                       a Chat.txt are uploaded and read («Leído»), with the
 *                       «Sin verificar» badge and unknown columns
 *   upload-invalid      a file of the wrong type is refused with a clear text
 *   remove-file         a file uploaded by mistake is removed («Quitar archivo»)
 *   mapping             every connection goes to «Conexión histórica sin envío»
 *   inventory-*         totals and filters equal the server's
 *   bulk-selection      selection + «Aplicar destino» (Importar)
 *   rule                example rule (no importar vacías): preview + apply
 *   participants        Chat.txt: «es la empresa» marked in the detail
 *   bulk-filter         «Aplicar a las N del filtro» (Archivar) on undecided
 *   change-decision     one conversation changed by hand before confirming
 *   summary-numbers     summary cards equal the server's summary
 *   summary-changed     a change behind the screen's back gives 409 → notice
 *   confirm-progress    confirmation, progress and «Importación terminada»
 *   final-report        report numbers equal the batch result; CSV downloads
 *   archive-search      the archived conversation is searchable
 *   archive-read-only   banner, no composer/reopen; no status ticks
 *   imported-closed     the imported conversation is listed under Cerradas
 *   responsive-*        320/768/1280, light and dark: nothing overflows
 *
 *   scratchpad/e2e.sh historial.mjs E2E_REVERB=1 E2E_PORT=8140 \
 *     E2E_REVERB_PORT=8197 WHATSAPP_TRANSPORT=simulated QUEUE_CONNECTION=sync
 */
import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import {
    BASE,
    HERE,
    SHOTS,
    USERS,
    exitWithSummary,
    launchBrowser,
    layoutReport,
    login,
    openPage,
    overflow,
    php,
    printProblems,
    recordPageProblems,
    record,
    shot,
    step,
    toastText,
} from './lib.mjs';

const SEED = join(HERE, 'historial-seed.php');
const WIDTHS = [320, 768, 1280];

async function settle(page, ms = 300) {
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(ms);
}

/** A JSON request from the page (session cookie + XSRF token). */
async function api(page, method, path, body) {
    return page.evaluate(
        async ({ method, path, body }) => {
            const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
            const response = await fetch(path, {
                method,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(match
                        ? { 'X-XSRF-TOKEN': decodeURIComponent(match[1]) }
                        : {}),
                },
                ...(body === undefined ? {} : { body: JSON.stringify(body) }),
            });

            return {
                status: response.status,
                data: await response.json().catch(() => null),
            };
        },
        { method, path, body },
    );
}

function query(params) {
    return new URLSearchParams(params).toString();
}

async function inventoryTotalShown(page) {
    const text = await page.locator('[data-inventory-total]').innerText();

    return Number(text.replace(/[^\d]/g, ''));
}

/** Waits until the inventory list stops loading and shows `expected` rows. */
async function waitInventory(page, expected) {
    await page
        .waitForFunction(
            (expected) => {
                const node = document.querySelector('[data-inventory-total]');

                return (
                    node &&
                    Number(node.textContent.replace(/[^\d]/g, '')) === expected
                );
            },
            expected,
            { timeout: 8_000 },
        )
        .catch(() => {});
    await settle(page, 200);
}

async function chooseOption(page, trigger, option) {
    await trigger.click();
    await page.getByRole('option', { name: option, exact: true }).click();
}

const run = async () => {
    const dir = SHOTS ?? mkdtempSync(join(tmpdir(), 'historial-'));
    const files = JSON.parse(php(SEED, 'files', join(dir, 'fixtures')));
    record(
        'seed',
        'historial-seed.php files',
        true,
        Object.keys(files).join(', '),
    );

    const browser = await launchBrowser();
    let batchId = null;
    let batchPath = null;

    try {
        /* ---------------------------------------------------------- */
        const agent = await openPage(browser, { prefix: '[ana] ' });
        await login(agent, USERS.ana);

        await step(
            'unauthorized-importer',
            'Ana → /admin/history-import',
            async () => {
                const response = await agent.goto(
                    `${BASE}/admin/history-import`,
                );

                return [
                    response.status() === 403,
                    `status ${response.status()}`,
                ];
            },
        );
        await step('unauthorized-history', 'Ana → /admin/history', async () => {
            const response = await agent.goto(`${BASE}/admin/history`);

            return [response.status() === 403, `status ${response.status()}`];
        });
        await agent.context().close();

        /* ---------------------------------------------------------- */
        const page = await openPage(browser, {
            prefix: '[root] ',
            trackToasts: true,
        });
        await login(page, USERS.superadmin);

        await step('create-batch', 'index → Nueva importación', async () => {
            await page.goto(`${BASE}/admin/history-import`);
            await settle(page);
            const note = await page
                .locator('[data-tour="history-import.format"]')
                .innerText();
            await page.locator('#batch-label').fill('Lote E2E sintético');
            await page
                .getByRole('button', { name: 'Crear lote y subir archivos' })
                .click();
            await page.waitForURL(/\/admin\/history-import\/\d+$/);
            await settle(page);
            batchPath = new URL(page.url()).pathname;
            batchId = Number(batchPath.split('/').pop());

            return [
                /aún no verificado con una exportación real/.test(note) &&
                    batchId > 0,
                `batch ${batchId}`,
            ];
        });

        async function upload(kindLabel, path, expectedRead) {
            await page.getByRole('radio', { name: kindLabel }).check();
            await page.setInputFiles('#history-file', path);
            await page.getByRole('button', { name: 'Subir y leer' }).click();
            await page.waitForFunction(
                (expected) =>
                    [...document.querySelectorAll('[data-file]')].filter(
                        (node) => node.textContent.includes('Leído'),
                    ).length >= expected,
                expectedRead,
                { timeout: 20_000 },
            );
            await settle(page, 200);
        }

        await step('upload-invalid', 'messages kind with a .zip', async () => {
            await page.getByRole('radio', { name: /Mensajes/ }).check();
            await page.setInputFiles('#history-file', files.media);
            const error = await page
                .locator('#history-file-error')
                .innerText()
                .catch(() => '');

            return [/solo se aceptan archivos \.csv/.test(error), error];
        });

        await step('upload-chats', 'chats-report.csv', async () => {
            await upload(/Reporte de chats/, files.chats, 1);
            const card = page.locator('[data-file="chats"]').first();
            const text = await card.innerText();

            return [
                /Sin verificar/.test(text) &&
                    /Columnas que no se usan/.test(text) &&
                    /userId/.test(text),
                text.replace(/\s+/g, ' ').slice(0, 160),
            ];
        });
        await step('upload-messages', 'messages.csv', async () => {
            await upload(/Mensajes/, files.messages, 2);
            const text = await page
                .locator('[data-file="messages"]')
                .first()
                .innerText();

            return [
                /Rechazadas/.test(text) && /Leído/.test(text),
                text.replace(/\s+/g, ' ').slice(0, 160),
            ];
        });
        await step('upload-contacts', 'contacts.csv', async () => {
            await upload(/Contactos/, files.contacts, 3);

            return [true, 'read'];
        });
        await step(
            'upload-media',
            'Whaticket-medias.zip + Chat.txt',
            async () => {
                await upload(/Archivos y Chat.txt/, files.media, 4);
                await upload(/Archivos y Chat.txt/, files.chat_txt, 5);
                const badges = await page
                    .getByText('Sin verificar', { exact: true })
                    .count();
                await shot(page, 'files-1366');

                return [badges === 5, `${badges} «Sin verificar» badges`];
            },
        );

        await step(
            'remove-file',
            'a second contacts file uploaded by mistake → «Quitar archivo»',
            async () => {
                await page.getByRole('radio', { name: /Contactos/ }).check();
                // A different file (the same one twice is refused as a duplicate).
                const mistaken = join(dir, 'contactos-equivocado.csv');
                writeFileSync(
                    mistaken,
                    `${readFileSync(files.contacts, 'utf8').trimEnd()}\nContacto Ficticio Extra,5215500000099,extra@example.invalid,\n`,
                );
                await page.setInputFiles('#history-file', mistaken);
                await page
                    .getByRole('button', { name: 'Subir y leer' })
                    .click();
                const uploaded = await page
                    .waitForFunction(
                        () =>
                            document.querySelectorAll('[data-file="contacts"]')
                                .length === 2,
                        undefined,
                        { timeout: 20_000 },
                    )
                    .then(() => true)
                    .catch(() => false);

                if (!uploaded) {
                    const cards = await page
                        .locator('[data-file]')
                        .allInnerTexts();
                    const error = await page
                        .locator('#history-file-error')
                        .innerText()
                        .catch(() => '');
                    await shot(page, 'remove-file-upload');

                    return [
                        false,
                        `second upload not shown; error "${error}"; cards ${cards.map((card) => card.split('\n').slice(0, 2).join(' ')).join(' | ')}`,
                    ];
                }

                await settle(page, 300);
                const before = await page.locator('[data-file]').count();
                await page
                    .locator('[data-file="contacts"]')
                    .last()
                    .locator('[data-file-remove]')
                    .click();
                const dialog = page.getByRole('dialog');
                const title = await dialog.getByRole('heading').innerText();
                await dialog
                    .getByRole('button', { name: 'Quitar archivo' })
                    .click();
                await page.waitForFunction(
                    () => document.querySelectorAll('[data-file]').length === 5,
                    undefined,
                    { timeout: 10_000 },
                );
                await settle(page, 300);
                const server = (await api(page, 'GET', `${batchPath}/progress`))
                    .data.batch.files;

                return [
                    before === 6 &&
                        /Quitar «contactos-equivocado/.test(title) &&
                        server.length === 5 &&
                        server.filter((file) => file.kind === 'contacts')
                            .length === 1,
                    `before ${before}; dialog "${title}"; server files ${server.length}`,
                ];
            },
        );

        await step(
            'mapping',
            'connections → Conexión histórica sin envío',
            async () => {
                await page.locator('[data-step="mapping"]').click();
                await settle(page, 200);
                const rows = page.locator('[data-connection-source]');
                const count = await rows.count();

                for (let index = 0; index < count; index += 1) {
                    await chooseOption(
                        page,
                        rows.nth(index).getByRole('combobox'),
                        'Conexión histórica sin envío',
                    );
                }

                await page
                    .getByRole('button', { name: 'Guardar correspondencias' })
                    .click();
                await toastText(page, 'Correspondencias guardadas.');
                const summary = (await api(page, 'GET', `${batchPath}/summary`))
                    .data.summary;
                await shot(page, 'mapping-1366');

                return [
                    count > 0 && summary.unmapped_connections.length === 0,
                    `${count} connections, unmapped ${JSON.stringify(summary.unmapped_connections)}`,
                ];
            },
        );

        await page.locator('[data-step="classify"]').click();
        await settle(page);

        await step('inventory-total', 'list total = server total', async () => {
            const server = (
                await api(page, 'GET', `${batchPath}/inventory?per_page=50`)
            ).data.meta.total;
            await waitInventory(page, server);
            const shown = await inventoryTotalShown(page);
            await shot(page, 'inventory-1366');

            return [
                shown === server && server >= 5,
                `shown ${shown}, server ${server}`,
            ];
        });

        await step(
            'inventory-filter-agent',
            'Agente en Whaticket = Agente Ficticio A',
            async () => {
                await page.getByRole('button', { name: 'Más filtros' }).click();
                await chooseOption(
                    page,
                    page.getByRole('combobox', { name: 'Agente en Whaticket' }),
                    'Agente Ficticio A',
                );
                const server = (
                    await api(
                        page,
                        'GET',
                        `${batchPath}/inventory?${query({ agent: 'Agente Ficticio A' })}`,
                    )
                ).data.meta.total;
                await waitInventory(page, server);
                const shown = await inventoryTotalShown(page);
                const rows = await page.locator('[data-row]').count();

                return [
                    shown === server && rows === server && server > 0,
                    `shown ${shown}, rows ${rows}, server ${server}`,
                ];
            },
        );

        await step('bulk-selection', 'select page → Importar', async () => {
            // Only the rows that admit «Importar» change (empty → only
            // «No importar», groups → archive/exclude); the rest are reported.
            const listed = (
                await api(
                    page,
                    'GET',
                    `${batchPath}/inventory?${query({ agent: 'Agente Ficticio A' })}`,
                )
            ).data.data;
            const expected = listed.filter((row) =>
                row.allowed_decisions.includes('import'),
            ).length;
            await page
                .getByRole('checkbox', {
                    name: 'Seleccionar todas las de esta página',
                })
                .click();
            await chooseOption(
                page,
                page.getByRole('combobox', { name: 'Destino a aplicar' }),
                'Importar',
            );
            await page
                .getByRole('button', { name: 'Aplicar destino a la selección' })
                .click();
            const toast = await toastText(page, 'actualizada');
            const decided = (
                await api(
                    page,
                    'GET',
                    `${batchPath}/inventory?${query({ agent: 'Agente Ficticio A', decision: 'import' })}`,
                )
            ).data.meta.total;

            return [
                decided === expected,
                `${toast}; import ${decided}/${expected}`,
            ];
        });

        await step('inventory-filter-empty', 'Sin mensajes', async () => {
            await page
                .getByRole('button', { name: 'Quitar filtros' })
                .first()
                .click();
            await page.getByRole('checkbox', { name: 'Sin mensajes' }).click();
            const server = (
                await api(page, 'GET', `${batchPath}/inventory?empty=1`)
            ).data.meta.total;
            await waitInventory(page, server);
            const shown = await inventoryTotalShown(page).catch(() => 0);
            await page
                .getByRole('button', { name: 'Quitar filtros' })
                .first()
                .click();
            await settle(page, 500);

            return [shown === server, `shown ${shown}, server ${server}`];
        });

        await step(
            'rule',
            'example «No importar las conversaciones sin mensajes»',
            async () => {
                await page
                    .getByRole('button', {
                        name: 'No importar las conversaciones sin mensajes',
                    })
                    .click();
                await page
                    .getByRole('button', { name: 'Ver cuántas cambian' })
                    .click();
                const preview = await page
                    .getByRole('status')
                    .filter({ hasText: 'Vista previa' })
                    .innerText();
                await page
                    .getByRole('button', { name: 'Aplicar regla' })
                    .click();
                const toast = await toastText(page, 'Regla aplicada');
                const rules = (await api(page, 'GET', `${batchPath}/rules`))
                    .data.rules;
                const excluded = (
                    await api(
                        page,
                        'GET',
                        `${batchPath}/inventory?${query({ empty: '1', decision: 'exclude' })}`,
                    )
                ).data.meta.total;

                return [
                    rules.length === 1 &&
                        rules[0].decision === 'exclude' &&
                        excluded > 0,
                    `${preview.replace(/\s+/g, ' ')} | ${toast} | excluded ${excluded}`,
                ];
            },
        );

        await step(
            'participants',
            'Chat.txt → Empresa Ficticia es la empresa',
            async () => {
                const row = page.locator('[data-row^="chattxt:"]').first();

                if ((await row.count()) === 0) {
                    return [false, 'no Chat.txt row'];
                }

                await row.locator('[data-row-open]').click();
                const sheet = page.getByRole('dialog');
                await sheet.waitFor();
                await sheet
                    .getByRole('checkbox', { name: /Empresa Ficticia/ })
                    .click();
                await sheet
                    .getByRole('button', { name: 'Guardar cambios' })
                    .click();
                await toastText(page, 'Cambios guardados.');
                await page.keyboard.press('Escape');
                await settle(page, 300);
                const data = (
                    await api(
                        page,
                        'GET',
                        `${batchPath}/inventory?${query({ kind: 'chat_txt' })}`,
                    )
                ).data.data[0];

                return [
                    data.business_participants.includes('Empresa Ficticia') &&
                        !data.issues.some(
                            (issue) =>
                                issue.code === 'participants_unidentified',
                        ),
                    JSON.stringify({
                        business: data.business_participants,
                        issues: data.issues.map((issue) => issue.code),
                    }),
                ];
            },
        );

        await step(
            'bulk-filter',
            'Sin decidir → Archivar (whole filter)',
            async () => {
                const undecided = (
                    await api(
                        page,
                        'GET',
                        `${batchPath}/inventory?decision=undecided`,
                    )
                ).data.meta.total;
                await page
                    .getByRole('button', { name: 'Menos filtros' })
                    .click();
                await page
                    .getByRole('button', { name: 'Sin decidir', exact: true })
                    .click();
                await waitInventory(page, undecided);
                await chooseOption(
                    page,
                    page.getByRole('combobox', { name: 'Destino a aplicar' }),
                    'Archivar como histórico',
                );
                await page
                    .getByRole('button', {
                        name: /Aplicar a las \d+ del filtro/,
                    })
                    .click();
                await page
                    .getByRole('dialog')
                    .getByRole('button', { name: 'Aplicar destino' })
                    .click();
                await toastText(page, 'actualizada');
                await page
                    .getByRole('dialog')
                    .waitFor({ state: 'hidden' })
                    .catch(() => {});
                let left = -1;

                for (
                    let attempt = 0;
                    attempt < 20 && left !== 0;
                    attempt += 1
                ) {
                    left = (
                        await api(
                            page,
                            'GET',
                            `${batchPath}/inventory?decision=undecided`,
                        )
                    ).data.meta.total;

                    if (left !== 0) {
                        await page.waitForTimeout(250);
                    }
                }
                const archived = (
                    await api(
                        page,
                        'GET',
                        `${batchPath}/inventory?decision=archive`,
                    )
                ).data.meta.total;

                return [
                    undecided > 0 && left === 0 && archived > 0,
                    `undecided ${undecided} → ${left}, archive ${archived}`,
                ];
            },
        );

        await step(
            'change-decision',
            'ticket:1002 → Importar by hand',
            async () => {
                await page
                    .getByRole('button', { name: 'Todas', exact: true })
                    .click();
                await settle(page, 600);
                const row = page.locator('[data-row="ticket:1002"]');
                await chooseOption(
                    page,
                    row.locator('[data-row-decision]'),
                    'Importar',
                );
                await settle(page, 500);
                const data = (
                    await api(
                        page,
                        'GET',
                        `${batchPath}/inventory?${query({ search: '1002' })}`,
                    )
                ).data.data.find((item) => item.source_key === 'ticket:1002');
                const text = await row.innerText();

                return [
                    data?.decision === 'import' &&
                        data.decision_source === 'manual' &&
                        /Decisión manual/.test(text),
                    `${data?.decision} ${data?.decision_source}`,
                ];
            },
        );

        await step(
            'summary-numbers',
            'summary cards = server summary',
            async () => {
                await page.locator('[data-step="summary"]').click();
                await settle(page, 600);
                const server = (await api(page, 'GET', `${batchPath}/summary`))
                    .data.summary;
                const notes = [];

                for (const decision of ['import', 'archive', 'exclude']) {
                    const shown = Number(
                        (
                            await page
                                .locator(
                                    `[data-summary-destination="${decision}"] [data-summary-count]`,
                                )
                                .innerText()
                        ).replace(/[^\d]/g, ''),
                    );

                    if (shown !== server.totals[decision]) {
                        notes.push(
                            `${decision} ${shown}≠${server.totals[decision]}`,
                        );
                    }
                }

                const mixed =
                    server.totals.import > 0 &&
                    server.totals.archive > 0 &&
                    server.totals.exclude > 0;
                const undecided = await page
                    .locator('[data-summary-undecided]')
                    .innerText();
                const blockers = await page.locator('[data-blocker]').count();
                const contacts = await page
                    .getByText('Contactos que se guardarán en el directorio')
                    .count();
                await shot(page, 'summary-1366');

                return [
                    notes.length === 0 &&
                        mixed &&
                        undecided.trim().endsWith(': 0') &&
                        blockers === 0 &&
                        server.ready &&
                        contacts === 1,
                    `${JSON.stringify(server.totals)} ready=${server.ready} blockers=${blockers} ${notes.join('; ')}`,
                ];
            },
        );

        await step(
            'summary-changed',
            '409 summary_changed → notice, then fresh summary',
            async () => {
                await page.locator('[data-confirm-reviewed]').click();
                // Another tab moves one archived conversation to «No importar».
                const archived = (
                    await api(
                        page,
                        'GET',
                        `${batchPath}/inventory?decision=archive`,
                    )
                ).data.data[0];
                await api(
                    page,
                    'PATCH',
                    `${batchPath}/inventory/${archived.id}`,
                    { decision: 'exclude' },
                );
                await page
                    .getByRole('button', { name: 'Confirmar e importar' })
                    .click();
                const notice = page
                    .getByRole('alert')
                    .filter({ hasText: 'El resumen cambió' });
                await notice.waitFor({ timeout: 8_000 });
                await settle(page, 600);
                const server = (await api(page, 'GET', `${batchPath}/summary`))
                    .data.summary;
                const shown = Number(
                    (
                        await page
                            .locator(
                                '[data-summary-destination="exclude"] [data-summary-count]',
                            )
                            .innerText()
                    ).replace(/[^\d]/g, ''),
                );
                const unchecked =
                    (await page
                        .locator('[data-confirm-reviewed]')
                        .getAttribute('data-state')) !== 'checked';
                const status = (await api(page, 'GET', `${batchPath}/progress`))
                    .data.batch.status;

                return [
                    shown === server.totals.exclude &&
                        unchecked &&
                        status === 'draft',
                    `exclude ${shown}/${server.totals.exclude}, unchecked ${unchecked}, status ${status}`,
                ];
            },
        );

        await step(
            'confirm-progress',
            'confirm → Importación terminada',
            async () => {
                await page.locator('[data-confirm-reviewed]').click();
                await page
                    .getByRole('button', { name: 'Confirmar e importar' })
                    .click();
                await page
                    .getByText('Importación terminada')
                    .waitFor({ timeout: 60_000 });
                await settle(page, 500);
                const status = await page
                    .locator('[data-batch-status]')
                    .innerText();
                await shot(page, 'result-1366');

                const final = (await api(page, 'GET', `${batchPath}/progress`))
                    .data.batch.status;

                return [final === 'completed', `${status} (${final})`];
            },
        );

        await step(
            'final-report',
            'result cards = batch result; CSV',
            async () => {
                const batch = (await api(page, 'GET', `${batchPath}/progress`))
                    .data.batch;
                const text = (
                    await page.locator('[data-result]').innerText()
                ).replace(/\s+/g, ' ');
                const notes = [];

                for (const [label, key] of [
                    ['Importadas', 'imported'],
                    ['Archivadas', 'archived'],
                    ['Duplicadas', 'duplicates'],
                    ['No importadas', 'excluded'],
                    ['Con error', 'failed'],
                ]) {
                    const match = text.match(new RegExp(`${label} (\\d+)`));

                    if (!match || Number(match[1]) !== batch.result[key]) {
                        notes.push(
                            `${label} ${match?.[1]}≠${batch.result[key]}`,
                        );
                    }
                }

                const csv = await page.request.get(
                    `${BASE}${batchPath}/report`,
                );
                const type = csv.headers()['content-type'] ?? '';

                return [
                    notes.length === 0 &&
                        csv.status() === 200 &&
                        /csv/.test(type) &&
                        batch.result.imported > 0 &&
                        batch.result.archived > 0,
                    `${JSON.stringify(batch.result)} csv ${csv.status()} ${type} ${notes.join('; ')}`,
                ];
            },
        );

        const imported = JSON.parse(php(SEED, 'imported', String(batchId)));
        record(
            'imported-seed',
            'historial-seed.php imported',
            imported.archived !== null && imported.imported !== null,
            JSON.stringify(imported),
        );

        await step(
            'archive-search',
            'Historial de Whaticket → search',
            async () => {
                await page.goto(`${BASE}/admin/history`);
                await settle(page);
                const name = imported.archived?.contact ?? '';
                await page.locator('#history-search').fill(name);
                await page.waitForTimeout(700);
                await settle(page);
                const item = page.locator(
                    `[data-history-conversation="${imported.archived?.id}"]`,
                );
                const found = await item.count();
                await shot(page, 'history-index-1366');

                return [found === 1, `«${name}» found ${found}`];
            },
        );

        await step(
            'archive-read-only',
            'conversation page is read only',
            async () => {
                await page
                    .locator(
                        `[data-history-conversation="${imported.archived?.id}"]`,
                    )
                    .click();
                await page.waitForURL(/\/admin\/history\/conversations\/\d+$/);
                await settle(page);
                const banner = await page
                    .locator('[data-read-only-banner]')
                    .innerText();
                const composer = await page.locator('textarea').count();
                const reopen = await page
                    .getByRole('button', {
                        name: /Reabrir|Responder|Asignar|Aceptar/,
                    })
                    .count();
                const bubbles = await page
                    .locator(
                        '[data-history-timeline] [data-layout-box="bubble"], [data-history-timeline] [data-imported-message]',
                    )
                    .count();

                await shot(page, 'history-show-1366');

                return [
                    /Historial importado de Whaticket · solo consulta/.test(
                        banner,
                    ) &&
                        composer === 0 &&
                        reopen === 0 &&
                        bubbles > 0,
                    `composer ${composer}, actions ${reopen}, bubbles ${bubbles}`,
                ];
            },
        );

        await step(
            'archive-no-ticks',
            'imported replies show no delivery ticks',
            async () => {
                const ticks = await page
                    .locator(
                        '[data-history-timeline] [title="Enviado"], [data-history-timeline] [title="Entregado"], [data-history-timeline] [title="Leído"]',
                    )
                    .count();

                return [
                    ticks === 0,
                    `${ticks} status indicators (MessageBubble, W4)`,
                ];
            },
        );

        await step(
            'imported-closed',
            'imported conversation under Cerradas',
            async () => {
                await page.goto(`${BASE}/conversations?status=closed`);
                await settle(page, 800);
                const name = imported.imported?.contact ?? '';
                const found = await page
                    .getByText(name, { exact: false })
                    .count();
                await shot(page, 'closed-1366');

                return [
                    imported.imported?.status === 'closed' && found > 0,
                    `«${name}» ${found}, status ${imported.imported?.status}`,
                ];
            },
        );

        const state = await page.context().storageState();
        const archivedPath = `/admin/history/conversations/${imported.archived?.id}`;

        for (const scheme of ['light', 'dark']) {
            for (const width of WIDTHS) {
                await step(
                    `responsive-${width}-${scheme}`,
                    `${width}px ${scheme}`,
                    async () => {
                        const phone = width <= 430;
                        const view = await openPage(browser, {
                            context: {
                                viewport: { width, height: phone ? 844 : 900 },
                                colorScheme: scheme,
                                hasTouch: phone,
                                isMobile: phone,
                                storageState: state,
                            },
                            prefix: `[${width}${scheme}] `,
                        });
                        const notes = [];

                        try {
                            for (const [label, path, prepare] of [
                                ['index', '/admin/history-import', null],
                                ['result', batchPath, null],
                                [
                                    'inventory',
                                    batchPath,
                                    async () => {
                                        await view
                                            .locator('[data-step="classify"]')
                                            .click();
                                        await settle(view, 600);
                                    },
                                ],
                                [
                                    'history',
                                    '/admin/history?destination=all',
                                    null,
                                ],
                                ['conversation', archivedPath, null],
                            ]) {
                                await view.goto(`${BASE}${path}`);
                                await settle(view);

                                if (prepare) {
                                    await prepare();
                                }

                                const by = await overflow(view, { width });
                                await shot(view, `${label}-${width}-${scheme}`);

                                if (by > 0) {
                                    const report = await layoutReport(view, {
                                        width,
                                    });
                                    const worst = [
                                        ...report.offscreen,
                                        ...report.innerScroll,
                                        ...report.clipped,
                                        ...report.contained,
                                    ]
                                        .slice(0, 3)
                                        .map((entry) =>
                                            JSON.stringify(entry).slice(0, 160),
                                        );
                                    notes.push(
                                        `${label} overflow ${by} ${worst.join(' ')}`,
                                    );
                                }
                            }
                        } finally {
                            await view.context().close();
                        }

                        return [notes.length === 0, notes.join('; ') || 'ok'];
                    },
                );
            }
        }

        await page.context().close();
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
