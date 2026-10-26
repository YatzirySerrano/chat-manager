/*
 * Phase 5.1 charts (D-CHARTS): the dashboard and the reports draw exactly
 * the numbers of the server. Data from tests/e2e/graficas-seed.php (six
 * local days of conversations on WhatsApp and web chat, Ana/Beto/Carla and
 * unassigned, both departments; a day without measured responses).
 *
 *   dashboard-activity-table    «Ver como tabla» rows = chartsFor().activity
 *   dashboard-activity-keyboard ←/→/Inicio/Fin move the readout, values = server
 *   dashboard-activity-pointer  hovering shows the same readout
 *   dashboard-first-response-gap the day without responses is a gap (two
 *                               pieces of line, «—» in the table, never 0 s)
 *   dashboard-channels          one bar per channel with data, values = server
 *   dashboard-statuses          legend counts = server
 *   dashboard-team              agent and department bars = server
 *   dashboard-empty             «Hoy» without conversations: empty states
 *   agent-no-team               an agent (own scope) has no team charts and
 *                               only its own activity
 *   reports-trend / reports-channels / reports-breakdown  = compute()
 *   data-tour                   stable data-tour anchors exist
 *   dark-mode                   charts repaint in dark mode (tokens)
 *   reduced-motion              no draw-in animation with reduced motion
 *   responsive-<page>-<w>-<s>   320/375/768/1280/1440, light and dark: no
 *                               horizontal overflow, chart text >= 13 px
 *
 *   E2E_PORT=8092 E2E_REVERB=1 E2E_REVERB_PORT=8142 \
 *     TEST_DB_LOCK_WAIT=3600 php tests/e2e/run.php graficas.mjs
 */
import { join } from 'node:path';
import {
    BASE,
    HERE,
    USERS,
    exitWithSummary,
    launchBrowser,
    layoutReport,
    login,
    openPage,
    php,
    printProblems,
    recordPageProblems,
    shot,
    step,
} from './lib.mjs';

const SEED = join(HERE, 'graficas-seed.php');
const WIDTHS = [320, 375, 768, 1280, 1440];

const seeded = JSON.parse(php(SEED));
console.log(
    `seeded ${seeded.conversations} conversations (${seeded.timezone})`,
);

const backend = (mode, email, period) =>
    JSON.parse(php(SEED, mode, email, period));

const number = (value) => value.toLocaleString('es-MX');

async function settle(page) {
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(300);
}

async function openDashboard(page, period = '7d') {
    await page.goto(`${BASE}/dashboard?period=${period}`);
    await settle(page);
    await page
        .locator('[data-chart-card]:not([aria-busy])')
        .first()
        .waitFor({ timeout: 15_000 })
        .catch(() => {});
}

const card = (page, name) => page.locator(`[data-chart="${name}"]`);

/** Rows of a card's «Ver como tabla» view as arrays of cell texts. */
async function tableRows(page, name) {
    const target = card(page, name);
    const toggle = target.locator('[data-chart-toggle]');

    if ((await toggle.getAttribute('aria-pressed')) !== 'true') {
        await toggle.click();
    }

    const rows = await target
        .locator('[data-chart-table] tr[data-chart-row]')
        .evaluateAll((elements) =>
            elements.map((row) =>
                [...row.children].map((cell) => cell.textContent.trim()),
            ),
        );
    await toggle.click();

    return rows;
}

async function tooltipText(page, name) {
    const tooltip = card(page, name).locator('[data-chart-tooltip]');
    await tooltip.waitFor({ timeout: 3_000 });

    return (await tooltip.innerText()).replace(/\s+/g, ' ').trim();
}

const browser = await launchBrowser();

/* ---------------------------------------------------------------- */
/* Administrator (global scope).                                     */
/* ---------------------------------------------------------------- */

const admin = await openPage(browser);
await login(admin, USERS.admin);
const charts = backend('charts', USERS.admin, '7d');
await openDashboard(admin);
await shot(admin, 'dashboard-1366-light');

await step('dashboard-activity-table', '7d', async () => {
    const rows = await tableRows(admin, 'activity');
    const expected = charts.activity.points.map((point) => [
        number(point.received),
        number(point.attended),
    ]);
    const drawn = rows.map((row) => [row[1], row[2]]);

    return [
        JSON.stringify(drawn) === JSON.stringify(expected) &&
            charts.activity.points.some((point) => point.received > 0),
        `rows=${rows.length} expected=${expected.length} ${JSON.stringify(drawn)}`,
    ];
});

await step('dashboard-activity-keyboard', '←/→ Inicio Fin', async () => {
    const svg = card(admin, 'activity').locator('svg[role="img"]');
    await svg.focus();
    await admin.keyboard.press('End');
    const points = charts.activity.points;
    const last = points[points.length - 1];
    const lastText = await tooltipText(admin, 'activity');
    await admin.keyboard.press('Home');
    await admin.keyboard.press('ArrowRight');
    const second = points[1];
    const secondText = await tooltipText(admin, 'activity');
    const live = await card(admin, 'activity')
        .locator('[aria-live="polite"]')
        .innerText();
    await shot(admin, 'dashboard-activity-tooltip');
    await admin.keyboard.press('Tab');

    return [
        lastText.includes(`${number(last.received)} Recibidas`) &&
            lastText.includes(`${number(last.attended)} Atendidas`) &&
            secondText.includes(`${number(second.received)} Recibidas`) &&
            secondText.includes(`${number(second.attended)} Atendidas`) &&
            live.includes('Recibidas'),
        `last="${lastText}" second="${secondText}" live="${live}"`,
    ];
});

await step('dashboard-activity-pointer', 'hover', async () => {
    const svg = card(admin, 'activity').locator('svg[role="img"]');
    const box = await svg.boundingBox();
    await admin.mouse.move(box.x + box.width - 24, box.y + box.height / 2);
    const text = await tooltipText(admin, 'activity');
    const last = charts.activity.points[charts.activity.points.length - 1];
    await admin.mouse.move(0, 0);

    return [text.includes(`${number(last.received)} Recibidas`), text];
});

await step('dashboard-first-response-gap', 'null = gap', async () => {
    const points = charts.activity.points;
    const gapIndex = points.findIndex(
        (point, index) =>
            point.first_response_seconds === null &&
            index > 0 &&
            points
                .slice(0, index)
                .some((p) => p.first_response_seconds !== null) &&
            points
                .slice(index + 1)
                .some((p) => p.first_response_seconds !== null),
    );
    const d = await card(admin, 'first-response')
        .locator('path[data-series="first_response"]')
        .getAttribute('d');
    const pieces = (d.match(/M/g) ?? []).length;
    const rows = await tableRows(admin, 'first-response');
    const zeroDrawn = rows.some((row) => row[1] === '0 s');
    const gapRow = rows[gapIndex];
    const measuredMatch = points.every(
        (point, index) =>
            rows[index]?.[2] === number(point.first_response_samples),
    );
    const svg = card(admin, 'first-response').locator('svg[role="img"]');
    await svg.focus();
    await admin.keyboard.press('Home');

    for (let index = 0; index < gapIndex; index++) {
        await admin.keyboard.press('ArrowRight');
    }

    const text = await tooltipText(admin, 'first-response');
    await admin.keyboard.press('Tab');

    return [
        gapIndex > 0 &&
            pieces >= 2 &&
            gapRow?.[1] === '—' &&
            !zeroDrawn &&
            measuredMatch &&
            text.includes('Sin respuestas medidas'),
        `gapIndex=${gapIndex} pieces=${pieces} gapRow=${JSON.stringify(gapRow)} zero=${zeroDrawn} tooltip="${text}"`,
    ];
});

await step('dashboard-channels', 'bars = server', async () => {
    const values = await card(admin, 'channels')
        .locator('[data-bar-row]')
        .evaluateAll((rows) =>
            rows.map((row) => [
                row.getAttribute('data-bar-row'),
                row.querySelector('[data-bar-value]').textContent.trim(),
            ]),
        );
    const expected = charts.channels.map((row) => [
        row.channel,
        number(row.received),
    ]);

    return [
        JSON.stringify(values) === JSON.stringify(expected) &&
            expected.length === 2,
        JSON.stringify(values),
    ];
});

await step('dashboard-statuses', 'legend = server', async () => {
    const legend = (
        await card(admin, 'statuses').locator('ul li').allInnerTexts()
    ).map((text) => text.replace(/\s+/g, ' ').trim());
    const ok = charts.statuses.every((row, index) =>
        legend[index]?.startsWith(`${row.label} ${number(row.count)} ·`),
    );

    return [ok, JSON.stringify(legend)];
});

await step('dashboard-team', 'agents/departments = server', async () => {
    const read = (name) =>
        card(admin, name)
            .locator('[data-bar-row]')
            .evaluateAll((rows) =>
                rows.map((row) => [
                    row.getAttribute('data-bar-row'),
                    ...[...row.querySelectorAll('[data-bar-value]')].map(
                        (value) => value.textContent.trim(),
                    ),
                ]),
            );
    const agents = await read('agents');
    const departments = await read('departments');
    const expectedAgents = charts.agents.map((row) => [
        row.key,
        number(row.received),
        number(row.attended),
    ]);
    const expectedDepartments = (charts.departments ?? []).map((row) => [
        row.key,
        number(row.received),
        number(row.resolved),
    ]);

    return [
        JSON.stringify(agents) === JSON.stringify(expectedAgents) &&
            JSON.stringify(departments) === JSON.stringify(expectedDepartments),
        `agents=${JSON.stringify(agents)} departments=${JSON.stringify(departments)} expected=${JSON.stringify(expectedDepartments)}`,
    ];
});

await step('data-tour', 'dashboard anchors', async () => {
    const anchors = [
        'dashboard.filters',
        'dashboard.kpis',
        'dashboard.activity',
        'dashboard.team',
        'dashboard.reports-link',
        'dashboard.export',
    ];
    const missing = [];

    for (const anchor of anchors) {
        if ((await admin.locator(`[data-tour="${anchor}"]`).count()) === 0) {
            missing.push(anchor);
        }
    }

    return [missing.length === 0, `missing=${missing.join(',')}`];
});

await step('dashboard-empty', 'Hoy sin conversaciones', async () => {
    await openDashboard(admin, 'today');
    const today = backend('charts', USERS.admin, 'today');
    const empties = await admin
        .locator('[data-chart-card] [data-chart-empty]')
        .count();
    const activityEmpty = await card(admin, 'activity')
        .locator('[data-chart-empty]')
        .count();
    await shot(admin, 'dashboard-today-empty');

    return [
        today.activity.granularity === 'hour' &&
            today.activity.points.every((point) => point.received === 0) &&
            activityEmpty === 1 &&
            empties >= 4,
        `granularity=${today.activity.granularity} points=${today.activity.points.length} empties=${empties}`,
    ];
});

await step('dark-mode', 'tokens repaint', async () => {
    await openDashboard(admin);
    const stroke = () =>
        card(admin, 'activity')
            .locator('path[data-series="received"]')
            .evaluate((path) => getComputedStyle(path).stroke);
    const light = await stroke();
    await admin.emulateMedia({ colorScheme: 'dark' });
    await admin.reload();
    await settle(admin);
    const isDark = await admin.evaluate(() =>
        document.documentElement.classList.contains('dark'),
    );
    const dark = await stroke();
    await shot(admin, 'dashboard-1366-dark');
    await admin.emulateMedia({ colorScheme: 'light' });

    return [isDark && light !== dark, `light=${light} dark=${dark}`];
});

/* ---------------------------------------------------------------- */
/* Reports.                                                          */
/* ---------------------------------------------------------------- */

const analytics = backend('analytics', USERS.admin, '7d');
await admin.goto(`${BASE}/reports?period=7d`);
await settle(admin);
await card(admin, 'trend').waitFor();
await shot(admin, 'reports-1366-light');

await step('reports-trend', 'table = compute()', async () => {
    const rows = await tableRows(admin, 'trend');
    const drawn = rows.map((row) => row.slice(1));
    const expected = analytics.trend.points.map((point) => [
        number(point.received),
        number(point.resolved),
        number(point.previous_received),
    ]);
    const toggleTour = await admin
        .locator('[data-tour="reports.table-toggle"]')
        .count();

    return [
        JSON.stringify(drawn) === JSON.stringify(expected) && toggleTour === 1,
        JSON.stringify(drawn),
    ];
});

await step('reports-channels', 'by_channel', async () => {
    const values = await card(admin, 'channels')
        .locator('[data-bar-row] [data-bar-value]')
        .allInnerTexts();
    const expected = analytics.by_channel.map((row) => number(row.received));
    const sum = analytics.by_channel.reduce(
        (total, row) => total + row.received,
        0,
    );

    return [
        JSON.stringify(values) === JSON.stringify(expected) &&
            sum === analytics.summary.received.value,
        `${JSON.stringify(values)} sum=${sum} received=${analytics.summary.received.value}`,
    ];
});

await step('reports-breakdown', 'agent bars', async () => {
    const values = await admin
        .locator('[data-breakdown="agents"] [data-bar-row]')
        .evaluateAll((rows) =>
            rows.map((row) =>
                [...row.querySelectorAll('[data-bar-value]')].map((value) =>
                    value.textContent.trim(),
                ),
            ),
        );
    const expected = analytics.by_agent
        .slice(0, 10)
        .map((row) => [number(row.received), number(row.attended)]);
    const anchors = await Promise.all(
        [
            'reports.filters',
            'reports.kpis',
            'reports.trend',
            'reports.export',
        ].map((anchor) => admin.locator(`[data-tour="${anchor}"]`).count()),
    );

    return [
        JSON.stringify(values) === JSON.stringify(expected) &&
            anchors.every((count) => count > 0),
        `${JSON.stringify(values)} anchors=${anchors.join(',')}`,
    ];
});

/* ---------------------------------------------------------------- */
/* Responsive matrix (light and dark).                               */
/* ---------------------------------------------------------------- */

for (const scheme of ['light', 'dark']) {
    const context = await openPage(browser, {
        context: {
            colorScheme: scheme,
            viewport: { width: 1280, height: 900 },
        },
    });
    await login(context, USERS.admin);

    for (const [name, path] of [
        ['dashboard', '/dashboard?period=7d'],
        ['reports', '/reports?period=7d'],
    ]) {
        for (const width of WIDTHS) {
            await step(
                `responsive-${name}-${width}-${scheme}`,
                path,
                async () => {
                    await context.setViewportSize({ width, height: 900 });
                    await context.goto(`${BASE}${path}`);
                    await settle(context);
                    await context
                        .locator('[data-chart-card]:not([aria-busy])')
                        .first()
                        .waitFor();
                    const report = await layoutReport(context, {
                        fontRoots: ['[data-chart-card]'],
                        minFont: 13,
                    });
                    // Wide tables (heat map, breakdowns) scroll inside their
                    // card on purpose; only what escapes every scroller counts.
                    const offscreen = await context.evaluate(() =>
                        [
                            ...document.querySelectorAll(
                                '[data-chart-card], main *',
                            ),
                        ]
                            .filter((element) => {
                                const rect = element.getBoundingClientRect();

                                if (
                                    rect.width === 0 ||
                                    element.closest('.sr-only') ||
                                    (rect.right <= window.innerWidth + 1 &&
                                        rect.left >= -1)
                                ) {
                                    return false;
                                }

                                for (
                                    let parent = element.parentElement;
                                    parent && parent.tagName !== 'MAIN';
                                    parent = parent.parentElement
                                ) {
                                    if (
                                        getComputedStyle(parent).overflowX !==
                                        'visible'
                                    ) {
                                        return false;
                                    }
                                }

                                return true;
                            })
                            .map((element) => ({
                                tag: element.tagName,
                                text: element.textContent.trim().slice(0, 30),
                            })),
                    );
                    await shot(context, `${name}-${width}-${scheme}`);

                    return [
                        report.doc <= 0 &&
                            offscreen.length === 0 &&
                            report.smallFont.length === 0,
                        `doc=${report.doc} offscreen=${JSON.stringify(offscreen.slice(0, 3))} small=${JSON.stringify(report.smallFont.slice(0, 3))}`,
                    ];
                },
            );
        }
    }

    await step(`tooltip-inside-320-${scheme}`, 'tooltip', async () => {
        await context.setViewportSize({ width: 320, height: 900 });
        await context.goto(`${BASE}/dashboard?period=7d`);
        await settle(context);
        const svg = card(context, 'activity').locator('svg[role="img"]');
        await svg.focus();
        await context.keyboard.press('End');
        await context.waitForTimeout(200);
        const box = await card(context, 'activity')
            .locator('[data-chart-tooltip]')
            .boundingBox();
        await shot(context, `tooltip-320-${scheme}`, { fullPage: false });

        return [
            box !== null && box.x >= 0 && box.x + box.width <= 320,
            JSON.stringify(box),
        ];
    });

    await context.context().close();
}

/* ---------------------------------------------------------------- */
/* Reduced motion.                                                   */
/* ---------------------------------------------------------------- */

await step('reduced-motion', 'no draw-in', async () => {
    const reduced = await openPage(browser, {
        context: { reducedMotion: 'reduce' },
    });
    await login(reduced, USERS.admin);
    await openDashboard(reduced);
    const style = await card(reduced, 'activity')
        .locator('path[data-series="received"]')
        .getAttribute('style');
    await reduced.context().close();

    return [
        !/\d+m?s/.test(style.split('transition:')[1] ?? '') &&
            /stroke-dashoffset: 0/.test(style),
        style,
    ];
});

/* ---------------------------------------------------------------- */
/* Agent: own scope, no team charts.                                 */
/* ---------------------------------------------------------------- */

await step('agent-no-team', 'Ana', async () => {
    const agent = await openPage(browser);
    await login(agent, USERS.ana);
    const own = backend('charts', USERS.ana, '7d');
    await openDashboard(agent);
    const teamCards =
        (await card(agent, 'agents').count()) +
        (await card(agent, 'departments').count());
    const rows = await tableRows(agent, 'activity');
    const drawn = rows.map((row) => row[1]);
    const expected = own.activity.points.map((point) => number(point.received));
    await shot(agent, 'dashboard-agent');
    await agent.context().close();

    return [
        own.agents === null &&
            own.departments === null &&
            teamCards === 0 &&
            JSON.stringify(drawn) === JSON.stringify(expected),
        `teamCards=${teamCards} drawn=${JSON.stringify(drawn)} expected=${JSON.stringify(expected)}`,
    ];
});

recordPageProblems();
printProblems();
await browser.close();
exitWithSummary();
