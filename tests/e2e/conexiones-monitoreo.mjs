/*
 * Monitoring block of the connections: how current the evidence about each
 * one is, never presenting old activity as «connected now». Connections of
 * tests/e2e/conexiones-monitoreo-seed.php (a recent check, only history, two
 * operational warnings, rejected credentials) plus the untouched "E2E
 * WhatsApp Ventas" (no evidence at all).
 *
 *   list-evidence               each row of Conexiones says its own
 *                               evidence: «Comprobada hace…», «Sin comprobar
 *                               desde…», «Con un problema sin resolver»,
 *                               «Sin evidencia todavía», and «2 advertencias»
 *   list-matches-server         the level shown equals
 *                               ConnectionMonitoring::for() of each one
 *   evidence-not-color-alone    recent and historical evidence differ in
 *                               words and icon, and explain themselves to a
 *                               screen reader
 *   list-phone                  on a phone the evidence and the warnings are
 *                               still visible in the list
 *   detail-anchor               /connections/{id}#diagnostico lands on the
 *                               monitoring block, with the two warnings
 *                               written out
 *   detail-recent-vs-history    the detail of the checked connection says
 *                               «Comprobada hace…»; the historical one «Sin
 *                               comprobar desde…» and no warnings block
 *   agent-forbidden             an agent cannot open Conexiones (403)
 *   layout-conexiones-<w>-<s>   list and monitoring block of the detail from
 *                               320 to 1440 px, light and dark: no horizontal
 *                               overflow (the list's filter bar and the
 *                               configuration form are not measured)
 *   no-page-errors              no console errors, uncaught exceptions,
 *                               unhandled rejections or 5xx
 *
 * SIMULACIÓN: nothing is checked against Meta; the evidence is seeded.
 *
 *   E2E_PORT=8180 TEST_DB_LOCK_WAIT=7200 php tests/e2e/run.php conexiones-monitoreo.mjs
 */
import { join } from 'node:path';
import {
    BASE,
    HERE,
    USERS,
    clean,
    domGarbage,
    exitWithSummary,
    launchBrowser,
    layoutSweep,
    login,
    notColorAlone,
    openPage,
    php,
    printProblems,
    recordPageProblems,
    shot,
    step,
} from './lib.mjs';

const data = (() => {
    try {
        return JSON.parse(php(join(HERE, 'conexiones-monitoreo-seed.php')));
    } catch (error) {
        console.error(String(error.stderr ?? error.stdout ?? error));
        process.exit(1);
    }
})();

/** [seed key, name, level, text of the evidence line, warnings text or null] */
const EXPECTED = [
    [
        'comprobada',
        'E2E Conexión comprobada',
        'recent',
        /^Comprobada hace /,
        null,
    ],
    [
        'historica',
        'E2E Conexión histórica',
        'historical',
        /^Sin comprobar desde hace /,
        null,
    ],
    [
        'avisos',
        'E2E Conexión con avisos',
        'recent',
        /^(Comprobada|Con actividad) hace /,
        '2 advertencias',
    ],
    [
        'rechazada',
        'E2E Conexión rechazada',
        'problem',
        /^Con un problema sin resolver/,
        null,
    ],
    [
        'sin_evidencia',
        'E2E WhatsApp Ventas',
        'none',
        /^Sin evidencia todavía/,
        null,
    ],
];

async function openList(page) {
    await page.goto(`${BASE}/connections`);
    await page.waitForLoadState('networkidle');
}

async function openDetail(page, id, hash = '') {
    await page.goto('about:blank');
    await page.goto(`${BASE}/connections/${id}${hash}`);
    await page.waitForLoadState('networkidle');
    await page.locator('#diagnostico').waitFor({ timeout: 10_000 });
    await page.waitForTimeout(400);
}

/** The record (table row or card) of a connection in the list. */
const record = (page, name) =>
    page
        .locator('tr, li')
        .filter({ hasText: name })
        .filter({ has: page.locator('[data-connection-evidence]:visible') })
        .first();

/** Evidence line of a record: level, visible words, screen-reader hint, warnings. */
async function evidenceOf(scope) {
    const line = scope.locator('[data-connection-evidence]:visible').first();
    const visible = (
        await line.locator('span:not(.sr-only)').first().innerText()
    ).trim();
    const count = scope.locator('[data-connection-warning-count]:visible');

    return {
        level: await line.getAttribute('data-connection-evidence'),
        visible,
        hint: (await line.locator('.sr-only').textContent()).trim(),
        warnings:
            (await count.count()) > 0
                ? (await count.first().innerText()).trim()
                : null,
    };
}

const run = async () => {
    const browser = await launchBrowser();

    try {
        const admin = await openPage(browser, { prefix: '[admin] ' });
        await login(admin, USERS.admin);
        const seen = {};

        await step(
            'list-evidence',
            '/connections',
            clean(async () => {
                await openList(admin);
                const notes = [];
                let ok = true;

                for (const [key, name, , text, warnings] of EXPECTED) {
                    await record(admin, name).waitFor({ timeout: 10_000 });
                    seen[key] = await evidenceOf(record(admin, name));
                    const good =
                        text.test(seen[key].visible) &&
                        seen[key].warnings === warnings;
                    ok &&= good;
                    notes.push(
                        `${key}: "${seen[key].visible}"${seen[key].warnings ? ` + "${seen[key].warnings}"` : ''}${good ? '' : ' (UNEXPECTED)'}`,
                    );
                }

                const garbage = await domGarbage(admin);
                await shot(admin, 'conexiones-lista');

                return [
                    ok && garbage.length === 0,
                    `${notes.join(' | ')} garbage=${JSON.stringify(garbage)}`,
                ];
            }),
        );

        await step(
            'list-matches-server',
            'ConnectionMonitoring::for()',
            async () => {
                const pairs = EXPECTED.map(([key, , level]) => ({
                    key,
                    screen: seen[key]?.level,
                    server: data.monitoring[key].evidence.level,
                    expected: level,
                    warnings: data.monitoring[key].warnings.length,
                }));

                return [
                    pairs.every(
                        (pair) =>
                            pair.screen === pair.server &&
                            pair.server === pair.expected,
                    ) &&
                        data.monitoring.avisos.warnings.length === 2 &&
                        data.monitoring.historica.warnings.length === 0,
                    JSON.stringify(pairs),
                ];
            },
        );

        await step(
            'evidence-not-color-alone',
            'recent vs historical',
            clean(async () => {
                const all = await notColorAlone(
                    admin,
                    '[data-connection-evidence]',
                );
                const counts = await notColorAlone(
                    admin,
                    '[data-connection-warning-count]',
                );
                const icon = (name) =>
                    record(admin, name)
                        .locator('[data-connection-evidence]:visible svg')
                        .first()
                        .evaluate((svg) => svg.innerHTML);
                const recentIcon = await icon('E2E Conexión comprobada');
                const historyIcon = await icon('E2E Conexión histórica');

                return [
                    all[0] &&
                        counts[0] &&
                        recentIcon !== historyIcon &&
                        /Evidencia reciente/.test(seen.comprobada.hint) &&
                        /Actividad histórica/.test(seen.historica.hint) &&
                        !/hace/.test(seen.rechazada.visible),
                    `${all[1]} | ${counts[1]} | different icons=${recentIcon !== historyIcon} | hints: "${seen.comprobada.hint.slice(0, 60)}" / "${seen.historica.hint.slice(0, 60)}"`,
                ];
            }),
        );

        await step(
            'list-phone',
            '390 px',
            clean(async () => {
                const phone = await openPage(browser, {
                    context: {
                        viewport: { width: 390, height: 844 },
                        hasTouch: true,
                        isMobile: true,
                        storageState: await admin.context().storageState(),
                    },
                    prefix: '[phone] ',
                });

                try {
                    await openList(phone);
                    const visible = await phone
                        .locator('[data-connection-evidence]:visible')
                        .count();
                    const warnings = await phone
                        .locator('[data-connection-warning-count]:visible')
                        .allInnerTexts();
                    await shot(phone, 'conexiones-lista-telefono');

                    return [
                        visible === EXPECTED.length &&
                            warnings.some((text) =>
                                /2 advertencias/.test(text),
                            ),
                        `evidence lines visible=${visible}/${EXPECTED.length} warnings=${JSON.stringify(warnings)}`,
                    ];
                } finally {
                    await phone.context().close();
                }
            }),
        );

        await step(
            'detail-anchor',
            `/connections/${data.avisos}#diagnostico`,
            clean(async () => {
                await openDetail(admin, data.avisos, '#diagnostico');
                const block = admin.locator('#diagnostico');
                const inView = await block.evaluate((element) => {
                    const rect = element.getBoundingClientRect();

                    return (
                        rect.top >= -2 && rect.top < window.innerHeight * 0.6
                    );
                });
                const warnings = await block
                    .locator('[data-connection-warnings] li')
                    .allInnerTexts();
                const heading = await block
                    .locator('[data-connection-warnings]')
                    .getByText('Advertencias operativas')
                    .isVisible();
                const states = await block
                    .getByRole('list', {
                        name: 'Evidencia del estado de la conexión',
                    })
                    .locator('li')
                    .evaluateAll((items) =>
                        items.map((item) => ({
                            icon: item.querySelector('svg') !== null,
                            state: (
                                item.querySelector('.sr-only')?.textContent ??
                                ''
                            ).trim(),
                        })),
                    );
                const garbage = await domGarbage(admin);
                await shot(admin, 'conexiones-diagnostico-avisos', {
                    fullPage: false,
                });

                return [
                    inView &&
                        heading &&
                        warnings.length === 2 &&
                        warnings.some((text) =>
                            /no está suscrita/.test(text),
                        ) &&
                        warnings.some((text) => /FLAGGED/.test(text)) &&
                        states.length >= 3 &&
                        states.every(
                            (item) => item.icon && item.state.length > 2,
                        ) &&
                        garbage.length === 0,
                    `anchor in view=${inView} warnings=${JSON.stringify(warnings.map((text) => text.replace(/\s+/g, ' ').slice(0, 70)))} checklist states=${JSON.stringify(states.map((item) => item.state))} garbage=${JSON.stringify(garbage)}`,
                ];
            }),
        );

        await step(
            'detail-recent-vs-history',
            'detail of the checked and of the historical connection',
            clean(async () => {
                await openDetail(admin, data.comprobada);
                const recent = await evidenceOf(admin.locator('#diagnostico'));
                const recentWarnings = await admin
                    .locator('[data-connection-warnings]')
                    .count();
                await shot(admin, 'conexiones-diagnostico-reciente', {
                    fullPage: false,
                });
                await openDetail(admin, data.historica);
                const history = await evidenceOf(admin.locator('#diagnostico'));
                const historyWarnings = await admin
                    .locator('[data-connection-warnings]')
                    .count();
                const garbage = await domGarbage(admin);
                await shot(admin, 'conexiones-diagnostico-historica', {
                    fullPage: false,
                });

                return [
                    recent.level === 'recent' &&
                        recent.visible.startsWith('Comprobada hace ') &&
                        history.level === 'historical' &&
                        history.visible.startsWith(
                            'Sin comprobar desde hace ',
                        ) &&
                        recentWarnings === 0 &&
                        historyWarnings === 0 &&
                        garbage.length === 0,
                    `recent="${recent.visible}" history="${history.visible}" warning blocks=${recentWarnings}/${historyWarnings} garbage=${JSON.stringify(garbage)}`,
                ];
            }),
        );

        const ana = await openPage(browser, { prefix: '[ana] ' });
        await login(ana, USERS.ana);

        await step(
            'agent-forbidden',
            'E2E Ana',
            clean(async () => {
                const list = (await ana.goto(`${BASE}/connections`)).status();
                const detail = (
                    await ana.goto(`${BASE}/connections/${data.avisos}`)
                ).status();
                await ana.goto(`${BASE}/conversations`);
                const menu = await ana
                    .locator('a[href$="/connections"]')
                    .count();

                return [
                    list === 403 && [403, 404].includes(detail) && menu === 0,
                    `list=${list} detail=${detail} menu links=${menu}`,
                ];
            }),
        );

        await layoutSweep(browser, {
            id: 'conexiones',
            storageState: await admin.context().storageState(),
            states: [
                {
                    name: 'lista',
                    tables: true,
                    // The filter bar is not part of the monitoring block
                    // (its selects cut their value with a line clamp).
                    ignore: ['[role="search"]'],
                    prepare: async (page) => {
                        await openList(page);
                        await page
                            .locator('[data-connection-evidence]:visible')
                            .first()
                            .waitFor({ timeout: 10_000 });
                    },
                },
                {
                    name: 'diagnostico',
                    // The configuration form beside the block is not under
                    // test here (inputs hold values longer than their box).
                    ignore: ['form fieldset'],
                    prepare: async (page) => {
                        await openDetail(page, data.avisos, '#diagnostico');
                    },
                },
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
