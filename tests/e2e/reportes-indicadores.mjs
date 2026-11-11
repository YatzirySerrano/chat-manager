/*
 * Dashboard and reports with indicators: first with NO data at all (honest
 * empty states), then with the six requests of
 * tests/e2e/reportes-indicadores-seed.php (real actions: webhook, category,
 * accept, reply, close; one answered after its deadline). The figures on
 * screen are compared with what the server computes for the same person and
 * filters (seed «figures»).
 *
 *   empty-dashboard             no conversations: zeros and empty charts, no
 *                               «NaN», «undefined», «Invalid Date» or «null»
 *   empty-reports-<tab>         the same on each tab of Reportes
 *   dashboard-figures           with data, «Recibidas» equals the server
 *                               figure and the charts draw
 *   dashboard-filters           the Categoría and Estado actual filters
 *                               change the figures to the server's, live in
 *                               the URL and survive a reload
 *   reports-figures             Reportes › Resumen: KPIs equal the server
 *   reports-tabs                Resumen / Productividad / Cumplimiento de
 *                               SLA / Desgloses: mouse and arrow keys,
 *                               aria-selected, panel and #hash follow, each
 *                               tab has its own content and no broken value
 *   reports-filter-category     the Categoría filter changes the figures
 *   granularity                 día / semana / mes: the pressed button, the
 *                               URL and the number of periods change
 *   sla-compliance              the SLA tab reflects one breach and the
 *                               cycles answered in time
 *   export-follows-tab          the export button is named after the tab
 *   export-xlsx / export-pdf    the menu produces a non-empty .xlsx (zip
 *                               signature) and a non-empty PDF («%PDF»)
 *   agent-own-productivity      E2E Ana (personal scope) sees only her own
 *                               row, also when asking for another agent in
 *                               the URL, and has no department filter
 *   layout-indicadores-<w>-<s>  dashboard and the four tabs of Reportes
 *                               from 320 to 1440 px, light and dark
 *   no-page-errors              no console errors, uncaught exceptions,
 *                               unhandled rejections or 5xx
 *
 * SIMULACIÓN: nothing is sent to Meta (simulated transport).
 *
 *   E2E_PORT=8178 WHATSAPP_TRANSPORT=simulated QUEUE_CONNECTION=sync \
 *     TEST_DB_LOCK_WAIT=7200 php tests/e2e/run.php reportes-indicadores.mjs
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import {
    BASE,
    HERE,
    SHOTS,
    USERS,
    clean,
    domGarbage,
    exitWithSummary,
    launchBrowser,
    layoutSweep,
    login,
    openPage,
    php,
    printProblems,
    recordPageProblems,
    shot,
    step,
} from './lib.mjs';

const SEED = join(HERE, 'reportes-indicadores-seed.php');
const figures = (email, filters = {}) =>
    JSON.parse(php(SEED, 'figures', email, JSON.stringify(filters)));
const TABS = [
    ['resumen', 'Resumen', 'Exportar reporte'],
    ['productividad', 'Productividad', 'Exportar productividad'],
    ['sla', 'Cumplimiento de SLA', 'Exportar cumplimiento'],
    ['desgloses', 'Desgloses', 'Exportar reporte'],
];

async function settle(page) {
    await page.waitForLoadState('networkidle').catch(() => {});
    await page
        .waitForFunction(() => !document.querySelector('[aria-busy="true"]'), {
            timeout: 15_000,
        })
        .catch(() => {});
    await page.waitForTimeout(300);
}

async function openDashboard(page, query = '?period=7d') {
    await page.goto(`${BASE}/dashboard${query}`);
    await settle(page);
}

/**
 * Opens Reportes with a full page load (a link that only changes the #hash
 * is a same-document navigation), on the tab named in the hash.
 */
async function openReports(page, query = '?period=7d', tab = '') {
    if (new URL(page.url()).pathname === '/reports') {
        await page.goto('about:blank');
    }

    await page.goto(`${BASE}/reports${query}${tab ? `#${tab}` : ''}`);
    await settle(page);

    if (tab) {
        await page
            .locator(`#reports-panel-${tab}`)
            .waitFor({ timeout: 15_000 });
    }
}

/** The figure of a KPI card as a number (its first line under the label). */
async function kpi(page, key) {
    const text = await page
        .locator(`[data-kpi="${key}"]`)
        .first()
        .locator('p')
        .first()
        .innerText();

    return Number(text.replace(/[^\d.-]/g, ''));
}

async function selectTab(page, key) {
    await page.locator(`[data-reports-tabs] [data-view="${key}"]`).click();
    await page.locator(`#reports-panel-${key}`).waitFor({ timeout: 10_000 });
    await page.waitForTimeout(250);
}

/** What a page of indicators shows: broken values, empty charts, drawn charts. */
async function survey(page) {
    return {
        garbage: await domGarbage(page),
        empty: await page.locator('[data-chart-empty]:visible').count(),
        cards: await page.locator('[data-chart-card]:visible').count(),
        loading: await page.locator('[aria-busy="true"]').count(),
    };
}

async function download(page, trigger, name) {
    const [file] = await Promise.all([
        page.waitForEvent('download', { timeout: 60_000 }),
        trigger(),
    ]);
    const path = SHOTS ? join(SHOTS, name) : await file.path();

    if (SHOTS) {
        await file.saveAs(path);
    }

    return { bytes: readFileSync(path), suggested: file.suggestedFilename() };
}

const run = async () => {
    const browser = await launchBrowser();

    try {
        const admin = await openPage(browser, {
            context: { acceptDownloads: true },
            trackToasts: true,
            prefix: '[admin] ',
        });
        await login(admin, USERS.admin);

        /* ------------------------------------------------------------ */
        /* No data.                                                      */
        /* ------------------------------------------------------------ */

        await step(
            'empty-dashboard',
            '/dashboard, no conversations',
            clean(async () => {
                await openDashboard(admin);
                const seen = await survey(admin);
                // Without a channel with confirmed messages the period
                // figures are not shown at all; the page says why.
                const note = await admin
                    .getByText('Aún no hay canales con mensajes confirmados')
                    .isVisible();
                const received =
                    (await admin.locator('[data-kpi="received"]').count()) > 0
                        ? await kpi(admin, 'received')
                        : null;
                const pending = await kpi(admin, 'pending');
                await shot(admin, 'indicadores-panel-vacio');

                return [
                    seen.garbage.length === 0 &&
                        seen.loading === 0 &&
                        pending === 0 &&
                        (received === 0 || (received === null && note)) &&
                        (seen.empty > 0 || note),
                    `pending now=${pending} received=${received} «sin canales confirmados» note=${note} empty charts=${seen.empty}/${seen.cards} still loading=${seen.loading} garbage=${JSON.stringify(seen.garbage)}`,
                ];
            }),
        );

        for (const [key, label] of TABS) {
            await step(
                `empty-reports-${key}`,
                `/reports › ${label}, no conversations`,
                clean(async () => {
                    await openReports(admin, '?period=7d', key);
                    const seen = await survey(admin);
                    const selected = await admin
                        .locator(`[data-view="${key}"]`)
                        .getAttribute('aria-selected');
                    const words = (
                        await admin.locator(`#reports-panel-${key}`).innerText()
                    ).replace(/\s+/g, ' ');
                    const says =
                        seen.empty > 0 ||
                        /Sin datos|Aún no|No hay|Sin solicitudes|Sin ciclos|todavía/i.test(
                            words,
                        );
                    await shot(admin, `indicadores-reportes-vacio-${key}`);

                    return [
                        selected === 'true' &&
                            seen.garbage.length === 0 &&
                            seen.loading === 0 &&
                            says,
                        `selected=${selected} empty charts=${seen.empty}/${seen.cards} says it is empty=${says} garbage=${JSON.stringify(seen.garbage)} text="${words.slice(0, 160)}"`,
                    ];
                }),
            );
        }

        /* ------------------------------------------------------------ */
        /* With data.                                                    */
        /* ------------------------------------------------------------ */

        const seeded = (() => {
            try {
                return JSON.parse(php(SEED, 'setup'));
            } catch (error) {
                console.error(String(error.stderr ?? error.stdout ?? error));
                process.exit(1);
            }
        })();
        const all = figures(USERS.admin);
        const warranties = figures(USERS.admin, {
            category: seeded.categories.garantias,
        });
        const closed = figures(USERS.admin, { status: 'closed' });
        console.log(
            `seeded: all=${JSON.stringify({ received: all.received, attended: all.attended, resolved: all.resolved })} garantías=${warranties.received} closed=${closed.received}`,
        );

        await step(
            'dashboard-figures',
            'period 7d',
            clean(async () => {
                await openDashboard(admin);
                const received = await kpi(admin, 'received');
                const attended = await kpi(admin, 'attended');
                const seen = await survey(admin);
                const drawn = await admin
                    .locator('[data-chart-card]:visible svg[role="img"]')
                    .count();
                await shot(admin, 'indicadores-panel');

                return [
                    all.received === 6 &&
                        received === all.received &&
                        attended === all.attended &&
                        drawn > 0 &&
                        seen.garbage.length === 0,
                    `received screen=${received} server=${all.received}; attended screen=${attended} server=${all.attended}; charts drawn=${drawn}, empty=${seen.empty}/${seen.cards}; garbage=${JSON.stringify(seen.garbage)}`,
                ];
            }),
        );

        await step(
            'dashboard-filters',
            'Categoría = E2E Garantías, then Estado actual = Cerrada',
            clean(async () => {
                const bar = admin.locator('[data-tour="dashboard.filters"]');
                await bar
                    .locator('[data-filter="category"]')
                    .selectOption(String(seeded.categories.garantias));
                await admin.waitForURL(
                    (url) =>
                        url.searchParams.get('category') ===
                        String(seeded.categories.garantias),
                );
                await settle(admin);
                const byCategory = await kpi(admin, 'received');
                await admin.reload();
                await settle(admin);
                const afterReload = await kpi(admin, 'received');
                const kept = await bar
                    .locator('[data-filter="category"]')
                    .inputValue();
                await shot(admin, 'indicadores-panel-categoria');
                await bar.locator('[data-filter="category"]').selectOption('');
                await admin.waitForURL(
                    (url) => !url.searchParams.has('category'),
                );
                await settle(admin);
                await bar
                    .locator('[data-filter="status"]')
                    .selectOption('closed');
                await admin.waitForURL(
                    (url) => url.searchParams.get('status') === 'closed',
                );
                await settle(admin);
                const byStatus = await kpi(admin, 'received');
                const garbage = await domGarbage(admin);

                return [
                    warranties.received === 3 &&
                        byCategory === warranties.received &&
                        afterReload === warranties.received &&
                        kept === String(seeded.categories.garantias) &&
                        byStatus === closed.received &&
                        closed.received !== all.received &&
                        garbage.length === 0,
                    `all=${all.received}; category screen=${byCategory} server=${warranties.received}, after reload=${afterReload} (select kept=${kept}); status closed screen=${byStatus} server=${closed.received}; garbage=${JSON.stringify(garbage)}`,
                ];
            }),
        );

        await step(
            'reports-figures',
            '/reports › Resumen',
            clean(async () => {
                await openReports(admin, '?period=7d', 'resumen');
                const shown = {
                    received: await kpi(admin, 'received'),
                    attended: await kpi(admin, 'attended'),
                    resolved: await kpi(admin, 'resolved'),
                };
                const seen = await survey(admin);
                await shot(admin, 'indicadores-reportes-resumen');

                return [
                    shown.received === all.received &&
                        shown.attended === all.attended &&
                        shown.resolved === all.resolved &&
                        seen.garbage.length === 0,
                    `screen=${JSON.stringify(shown)} server=${JSON.stringify({ received: all.received, attended: all.attended, resolved: all.resolved })} garbage=${JSON.stringify(seen.garbage)}`,
                ];
            }),
        );

        await step(
            'reports-tabs',
            'click and arrow keys',
            clean(async () => {
                const notes = [];
                let ok = true;

                for (const [key, label] of TABS) {
                    await selectTab(admin, key);
                    const selected = await admin
                        .locator('[data-reports-tabs] [aria-selected="true"]')
                        .allInnerTexts();
                    const hash = new URL(admin.url()).hash;
                    const labelledBy = await admin
                        .locator(`#reports-panel-${key}`)
                        .getAttribute('aria-labelledby');
                    const seen = await survey(admin);
                    const good =
                        selected.length === 1 &&
                        selected[0].trim() === label &&
                        hash === `#${key}` &&
                        labelledBy === `reports-tab-${key}` &&
                        seen.garbage.length === 0;
                    ok &&= good;
                    notes.push(
                        `${key}: selected=${JSON.stringify(selected)} hash=${hash} labelledby=${labelledBy} garbage=${JSON.stringify(seen.garbage)}`,
                    );
                    await shot(admin, `indicadores-reportes-${key}`);
                }

                const content = {
                    productividad: await admin
                        .locator('[data-productivity-row]')
                        .count(),
                };
                await selectTab(admin, 'resumen');
                await admin.locator('[data-view="resumen"]').focus();
                await admin.keyboard.press('ArrowRight');
                const afterRight = await admin.evaluate(() =>
                    document.activeElement?.getAttribute('data-view'),
                );
                await admin.keyboard.press('ArrowLeft');
                await admin.keyboard.press('ArrowLeft');
                const afterWrap = await admin.evaluate(() =>
                    document.activeElement?.getAttribute('data-view'),
                );
                const tabStops = await admin
                    .locator('[data-reports-tabs] [role="tab"][tabindex="0"]')
                    .count();
                await selectTab(admin, 'productividad');
                content.productividad = await admin
                    .locator('[data-productivity-row]')
                    .count();

                return [
                    ok &&
                        afterRight === 'productividad' &&
                        afterWrap === 'desgloses' &&
                        tabStops === 1 &&
                        content.productividad >= 2,
                    `${notes.join(' | ')} | ArrowRight -> ${afterRight}, ArrowLeft×2 -> ${afterWrap}, tab stops=${tabStops}, productivity rows=${content.productividad}`,
                ];
            }),
        );

        await step(
            'reports-filter-category',
            'Categoría = E2E Garantías',
            clean(async () => {
                await openReports(admin, '?period=7d', 'resumen');
                const before = await kpi(admin, 'received');
                await admin
                    .locator(
                        '[data-tour="reports.filters"] [data-filter="category"]',
                    )
                    .selectOption(String(seeded.categories.garantias));
                await admin.waitForURL(
                    (url) =>
                        url.searchParams.get('category') ===
                        String(seeded.categories.garantias),
                );
                await settle(admin);
                const after = await kpi(admin, 'received');
                const garbage = await domGarbage(admin);

                return [
                    before === all.received &&
                        after === warranties.received &&
                        after !== before &&
                        garbage.length === 0,
                    `before=${before} after=${after} server=${warranties.received} garbage=${JSON.stringify(garbage)}`,
                ];
            }),
        );

        await step(
            'granularity',
            'día / semana / mes over the last 30 days',
            clean(async () => {
                await openReports(admin, '?period=30d', 'resumen');
                const toggle = admin
                    .locator('[data-chart="trend"] [data-granularity-toggle]')
                    .first();
                const offered = await toggle
                    .locator('[data-granularity]')
                    .evaluateAll((buttons) =>
                        buttons.map((button) => [
                            button.getAttribute('data-granularity'),
                            button.textContent.trim(),
                        ]),
                    );
                const results = [];

                for (const [grain] of offered) {
                    const button = toggle.locator(
                        `[data-granularity="${grain}"]`,
                    );

                    if (
                        (await button.getAttribute('aria-pressed')) !== 'true'
                    ) {
                        await button.click();
                        await admin.waitForURL(
                            (url) =>
                                url.searchParams.get('granularity') === grain,
                        );
                        await settle(admin);
                    }

                    results.push({
                        grain,
                        pressed: await admin
                            .locator(
                                '[data-chart="trend"] [data-granularity-toggle] [aria-pressed="true"]',
                            )
                            .first()
                            .getAttribute('data-granularity'),
                        server: figures(USERS.admin, {
                            period: '30d',
                            granularity: grain,
                        }).trend_points,
                        garbage: (await domGarbage(admin)).length,
                    });
                }

                await shot(admin, 'indicadores-reportes-granularidad');
                const counts = results.map((result) => result.server);

                return [
                    offered.length >= 2 &&
                        results.every(
                            (result) =>
                                result.pressed === result.grain &&
                                result.garbage === 0,
                        ) &&
                        new Set(counts).size === counts.length,
                    `offered=${JSON.stringify(offered)} results=${JSON.stringify(results)}`,
                ];
            }),
        );

        await step(
            'sla-compliance',
            '/reports › Cumplimiento de SLA',
            clean(async () => {
                await openReports(admin, '?period=7d', 'sla');
                const panel = (
                    await admin.locator('#reports-panel-sla').innerText()
                ).replace(/\s+/g, ' ');
                const seen = await survey(admin);
                const totals = all.sla.totals ?? {};
                await shot(admin, 'indicadores-reportes-sla');

                return [
                    totals.breached === 1 &&
                        totals.met >= 2 &&
                        seen.garbage.length === 0 &&
                        /Fuera del plazo/.test(panel) &&
                        /Dentro del plazo/.test(panel),
                    `server totals=${JSON.stringify(totals)} empty charts=${seen.empty}/${seen.cards} garbage=${JSON.stringify(seen.garbage)} text="${panel.slice(0, 200)}"`,
                ];
            }),
        );

        await step(
            'export-follows-tab',
            'the export button of each tab',
            clean(async () => {
                await openReports(admin, '?period=7d', 'resumen');
                const labels = {};

                for (const [key] of TABS) {
                    await selectTab(admin, key);
                    labels[key] = (
                        await admin
                            .locator('[data-tour="reports.export"] button')
                            .first()
                            .innerText()
                    ).trim();
                }

                return [
                    TABS.every(([key, , expected]) => labels[key] === expected),
                    JSON.stringify(labels),
                ];
            }),
        );

        for (const [format, item, signature] of [
            ['xlsx', 'Excel (.xlsx)', 'PK'],
            ['pdf', 'PDF', '%PDF'],
        ]) {
            await step(
                `export-${format}`,
                'Productividad › Exportar productividad',
                clean(async () => {
                    await openReports(admin, '?period=7d', 'productividad');
                    await admin
                        .locator('[data-tour="reports.export"] button')
                        .first()
                        .click();
                    const file = await download(
                        admin,
                        () =>
                            admin.getByRole('menuitem', { name: item }).click(),
                        `indicadores-productividad.${format}`,
                    );
                    const head = file.bytes.subarray(0, 4).toString('latin1');

                    return [
                        file.bytes.length > 1_000 &&
                            head.startsWith(signature) &&
                            file.suggested.endsWith(`.${format}`),
                        `${file.suggested}: ${file.bytes.length} bytes, starts with "${head.replace(/[^\x20-\x7e]/g, '?')}"`,
                    ];
                }),
            );
        }

        await step(
            'export-sla-xlsx',
            'Cumplimiento de SLA › Exportar cumplimiento',
            clean(async () => {
                await openReports(admin, '?period=7d', 'sla');
                await admin
                    .locator('[data-tour="reports.export"] button')
                    .first()
                    .click();
                const file = await download(
                    admin,
                    () =>
                        admin
                            .getByRole('menuitem', { name: 'Excel (.xlsx)' })
                            .click(),
                    'indicadores-cumplimiento.xlsx',
                );

                return [
                    file.bytes.length > 1_000 &&
                        file.bytes.subarray(0, 2).toString('latin1') === 'PK',
                    `${file.suggested}: ${file.bytes.length} bytes`,
                ];
            }),
        );

        /* ------------------------------------------------------------ */
        /* Personal scope.                                               */
        /* ------------------------------------------------------------ */

        const ana = await openPage(browser, { prefix: '[ana] ' });
        await login(ana, USERS.ana);
        const own = figures(USERS.ana);

        await step(
            'agent-own-productivity',
            'E2E Ana',
            clean(async () => {
                await openReports(ana, '?period=7d', 'productividad');
                const names = async () =>
                    ana
                        .locator('[data-productivity-row]')
                        .evaluateAll((rows) =>
                            rows.map((row) =>
                                row.firstElementChild.textContent.trim(),
                            ),
                        );
                const rows = await names();
                const title = await ana
                    .locator('[data-breakdown="productivity"]')
                    .innerText();
                const page = (
                    await ana.locator('.page-shell').first().innerText()
                ).replace(/\s+/g, ' ');
                await shot(ana, 'indicadores-reportes-agente');
                await openReports(
                    ana,
                    `?period=7d&agent=${seeded.beto}`,
                    'productividad',
                );
                const forced = await names();
                await selectTab(ana, 'resumen');
                const received = await kpi(ana, 'received');
                const garbage = await domGarbage(ana);

                return [
                    JSON.stringify(rows) === JSON.stringify(['E2E Ana']) &&
                        JSON.stringify(own.productivity) ===
                            JSON.stringify(['E2E Ana']) &&
                        /Tu productividad/.test(title) &&
                        /Tus propios indicadores de atención/.test(page) &&
                        !/E2E Beto/.test(page) &&
                        forced.every((name) => name === 'E2E Ana') &&
                        received === own.received &&
                        received < all.received &&
                        garbage.length === 0,
                    `rows=${JSON.stringify(rows)} server=${JSON.stringify(own.productivity)} with ?agent=Beto rows=${JSON.stringify(forced)} received screen=${received} server=${own.received} (all=${all.received}) garbage=${JSON.stringify(garbage)}`,
                ];
            }),
        );

        await layoutSweep(browser, {
            id: 'indicadores',
            storageState: await admin.context().storageState(),
            states: [
                {
                    name: 'panel',
                    tables: true,
                    prepare: async (page) => {
                        await openDashboard(page);
                        await page
                            .locator('[data-kpi="received"]')
                            .waitFor({ timeout: 15_000 });
                    },
                },
                ...TABS.map(([key]) => ({
                    name: `reportes-${key}`,
                    tables: true,
                    prepare: async (page) => {
                        await openReports(page, '?period=7d', key);
                    },
                })),
            ],
        });
    } finally {
        await browser.close();
    }

    recordPageProblems(800);
    printProblems();
    exitWithSummary();
};

run().catch((error) => {
    console.error(error);
    process.exit(1);
});
