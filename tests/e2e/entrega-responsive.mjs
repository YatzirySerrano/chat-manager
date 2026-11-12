/*
 * Responsive sweep of the first-delivery pages: every page a customer
 * touches on day one, at 320, 375, 390, 430, 768, 1024, 1280, 1440 and
 * 1920 px plus a short landscape phone (740x360), in light and dark.
 *
 *   layout-<viewport>-<scheme>   per viewport and color scheme, every
 *                                state below loads, has no horizontal
 *                                document overflow (doc) and nothing
 *                                offscreen; the other detector findings
 *                                (clipped text, inner scroll, small
 *                                targets) are written in the note only
 *   no-page-errors               no console errors, uncaught exceptions,
 *                                unhandled rejections or 5xx
 *
 * States: login (signed out); as the administrator: dashboard, contacts,
 * departments, connections, templates, users, roles, settings and the
 * notifications bell open; as E2E Ana: the inbox, the open conversation
 * (tests/e2e/chat-movil-seed.php), the composer with an attachment draft,
 * the «Vista previa» dialog of a row and an internal chat thread
 * (tests/e2e/chat-interno-avisos-seed.php).
 *
 * E2E_VIEWPORTS=320,740x360 limits the sweep to some viewports.
 *
 * Each state is saved as <viewport>-<scheme>-<state>.png when
 * E2E_SCREENSHOTS is set. SIMULACIÓN: Meta is never called.
 *
 *   E2E_PORT=8190 E2E_SCREENSHOTS=/path/to/screens TEST_DB_LOCK_WAIT=3600 \
 *     php tests/e2e/run.php entrega-responsive.mjs
 */
import { writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
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
    problemMark,
    problemsSince,
    recordPageProblems,
    shot,
    step,
} from './lib.mjs';

const chat = JSON.parse(php(join(HERE, 'chat-movil-seed.php'), 'prepare'));
const threads = JSON.parse(php(join(HERE, 'chat-interno-avisos-seed.php')));

const ATTACHMENT = join(tmpdir(), `Entrega_responsive_${process.pid}.pdf`);
writeFileSync(ATTACHMENT, '%PDF-1.4\n%%EOF\n');

/** [name, width, height, phone (touch)] */
const ONLY = process.env.E2E_VIEWPORTS?.split(',') ?? null;
const ALL_VIEWPORTS = [
    ['320', 320, 700, true],
    ['375', 375, 812, true],
    ['390', 390, 844, true],
    ['430', 430, 932, true],
    ['740x360', 740, 360, true],
    ['768', 768, 1024, true],
    ['1024', 1024, 768, false],
    ['1280', 1280, 800, false],
    ['1440', 1440, 900, false],
    ['1920', 1920, 1080, false],
];
/** E2E_VIEWPORTS=320,740x360 limits the sweep (a quick re-check). */
const VIEWPORTS = ALL_VIEWPORTS.filter(
    ([name]) => !ONLY || ONLY.includes(name),
);

async function settle(page) {
    await page.waitForLoadState('networkidle').catch(() => {});
    await page
        .waitForFunction(
            () =>
                document
                    .getAnimations()
                    .every((animation) => animation.playState !== 'running'),
            null,
            { timeout: 5_000 },
        )
        .catch(() => {});
    await page.waitForTimeout(200);
}

async function visit(page, path) {
    await page.goto(`${BASE}${path}`);
    await settle(page);
}

const history = (page) =>
    page.getByRole('log', { name: /Historial de la conversación/ });

const ADMIN_STATES = [
    { name: 'dashboard', prepare: (page) => visit(page, '/dashboard') },
    { name: 'contacts', prepare: (page) => visit(page, '/contacts') },
    { name: 'departments', prepare: (page) => visit(page, '/departments') },
    { name: 'connections', prepare: (page) => visit(page, '/connections') },
    { name: 'templates', prepare: (page) => visit(page, '/templates') },
    { name: 'users', prepare: (page) => visit(page, '/admin/users') },
    { name: 'roles', prepare: (page) => visit(page, '/admin/roles') },
    { name: 'settings', prepare: (page) => visit(page, '/admin/settings') },
    {
        name: 'bell',
        prepare: async (page) => {
            await visit(page, '/dashboard');
            await page
                .getByRole('button', { name: /^Notificaciones,/ })
                .first()
                .click();
            await page
                .getByRole('heading', { name: 'Notificaciones', exact: true })
                .waitFor({ timeout: 10_000 });
            await settle(page);
        },
        cleanup: (page) => page.keyboard.press('Escape'),
    },
];

const AGENT_STATES = [
    {
        name: 'inbox',
        prepare: async (page) => {
            await visit(page, '/conversations');
            await page.locator('[data-inbox-item]').first().waitFor();
        },
    },
    {
        name: 'conversation',
        prepare: async (page) => {
            await visit(page, `/conversations?c=${chat.conversation}`);
            await history(page).waitFor();
        },
    },
    {
        name: 'attachment',
        prepare: async (page) => {
            await page
                .locator('input[type="file"]')
                .first()
                .setInputFiles(ATTACHMENT);
            await page
                .getByRole('button', { name: 'Quitar el archivo adjunto' })
                .waitFor();
            await page.locator('#chat-composer').scrollIntoViewIfNeeded();
            await settle(page);
        },
        cleanup: (page) =>
            page
                .getByRole('button', { name: 'Quitar el archivo adjunto' })
                .click(),
    },
    {
        name: 'preview',
        prepare: async (page) => {
            await visit(page, '/conversations');
            // The row menu is a sibling of the row button, in its group/row.
            const row = page.locator('div.group\\/row').first();
            await row.waitFor();
            await row.locator('[data-inbox-row-menu]').click();
            await page.locator('[data-inbox-preview]').click();
            await page.locator('[data-chat-preview]').waitFor();
            await settle(page);
        },
        cleanup: (page) => page.keyboard.press('Escape'),
    },
    {
        name: 'team-chat',
        prepare: async (page) => {
            await visit(page, `/team-chat/threads/${threads.direct}`);
            await page
                .locator('textarea, [contenteditable="true"]')
                .first()
                .waitFor();
        },
    },
];

async function measure(page, state, label) {
    try {
        await state.prepare(page);
    } catch (error) {
        return `${state.name}: not reached: ${String(error).split('\n')[0]}`;
    }

    // Native inputs mirrored by Reka (checkboxes, selects) are visually
    // hidden on purpose: they are not part of the layout.
    await page.evaluate(() =>
        document
            .querySelectorAll(
                'input[aria-hidden="true"], select[aria-hidden="true"]',
            )
            .forEach((input) => input.setAttribute('data-e2e-ignore', '')),
    );
    const report = await layoutReport(page);
    await shot(page, `${label}-${state.name}`, { fullPage: false });

    if (state.cleanup) {
        await Promise.resolve()
            .then(() => state.cleanup(page))
            .catch(() => {});
    }

    const blocking = [
        report.doc > 0 ? `doc=${report.doc}` : null,
        report.offscreen.length > 0
            ? `offscreen=${JSON.stringify(report.offscreen.slice(0, 3).map((entry) => `${entry.tag}:${entry.name}:${entry.by}`))}`
            : null,
    ].filter(Boolean);

    observed.push(
        `${label} ${state.name}: clipped=${report.clipped.length} innerScroll=${report.innerScroll.length} smallTargets=${report.smallTargets.length}`,
    );

    return blocking.length === 0
        ? null
        : `${state.name}: ${blocking.join(' ')}`;
}

const observed = [];

const run = async () => {
    const browser = await launchBrowser();

    try {
        const admin = await openPage(browser, { prefix: '[admin] ' });
        await login(admin, USERS.admin);
        const adminState = await admin.context().storageState();
        await admin.context().close();

        const ana = await openPage(browser, { prefix: '[ana] ' });
        await login(ana, USERS.ana);
        const anaState = await ana.context().storageState();
        await ana.context().close();

        for (const scheme of ['light', 'dark']) {
            for (const [name, width, height, phone] of VIEWPORTS) {
                const label = `${name}-${scheme}`;

                await step(
                    `layout-${label}`,
                    `${width}x${height} ${scheme}`,
                    async () => {
                        const mark = problemMark();
                        const problems = [];
                        const context = (storageState) => ({
                            context: {
                                viewport: { width, height },
                                colorScheme: scheme,
                                hasTouch: phone,
                                isMobile: phone && width < 768,
                                storageState,
                            },
                            prefix: `[${label}] `,
                        });

                        const guest = await openPage(
                            browser,
                            context(undefined),
                        );

                        try {
                            problems.push(
                                await measure(
                                    guest,
                                    {
                                        name: 'login',
                                        prepare: (page) =>
                                            visit(page, '/login'),
                                    },
                                    label,
                                ),
                            );
                        } finally {
                            await guest.context().close();
                        }

                        for (const [storageState, states] of [
                            [adminState, ADMIN_STATES],
                            [anaState, AGENT_STATES],
                        ]) {
                            const page = await openPage(
                                browser,
                                context(storageState),
                            );

                            try {
                                for (const state of states) {
                                    problems.push(
                                        await measure(page, state, label),
                                    );
                                }
                            } finally {
                                await page.context().close();
                            }
                        }

                        const found = problems.filter(Boolean);
                        const fresh = problemsSince(mark);

                        if (fresh.length > 0) {
                            found.push(
                                `browser errors: ${JSON.stringify(fresh).slice(0, 300)}`,
                            );
                        }

                        return [
                            found.length === 0,
                            found.join(' | ').slice(0, 900) ||
                                `${ADMIN_STATES.length + AGENT_STATES.length + 1} states without horizontal overflow`,
                        ];
                    },
                );
            }
        }
    } finally {
        await browser.close();
    }

    console.log('\nDetector notes (not failures):');
    observed.forEach((line) => console.log(`  ${line}`));
    recordPageProblems(800);
    printProblems();
    exitWithSummary();
};

run().catch((error) => {
    console.error(error);
    process.exit(1);
});
