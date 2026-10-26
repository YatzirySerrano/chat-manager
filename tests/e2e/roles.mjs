/*
 * Phase 5.1 roles and scopes (C-ROLES): executive role cards, the «Ver
 * permisos» detail, the scope selector and the root role's invisibility.
 * Signed in as the superadministrator of tests/e2e/increment-b-seed.php and
 * as the non-root Administrador of tests/e2e/roles-seed.php.
 *
 *   cards-summary          cards show scope, users and «N permisos
 *                          habilitados · Acceso a N áreas», no per-module chips
 *   responsive-<w>-<s>     roles page in cards view at 320/375/768/1280/1440,
 *                          light and dark: nothing overflows
 *   sheet-groups           «Ver permisos» opens the detail with one heading
 *                          per module; Enter/Space on a trigger toggles it;
 *                          no technical permission names
 *   sheet-only-enabled     «Mostrar solo los habilitados» off shows more
 *   sheet-mobile           the detail as a bottom sheet at 375 px without
 *                          horizontal overflow
 *   scope-keyboard         the scope radio group moves with the arrow keys
 *                          and explains the consequences of the change
 *   edit-warnings          the editor keeps the «A quién afecta» block and
 *                          the 0-holder warning; saving still works
 *   administrator-no-root  the Administrador never sees the root role nor
 *                          the «Solo superadministrador» permissions
 *
 *   E2E_PORT=8090 E2E_REVERB=1 E2E_REVERB_PORT=8140 \
 *     TEST_DB_LOCK_WAIT=3600 php tests/e2e/run.php roles.mjs
 */
import { join } from 'node:path';
import {
    BASE,
    HERE,
    SHOTS,
    USERS,
    exitWithSummary,
    launchBrowser,
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

const WIDTHS = [320, 375, 768, 1280, 1440];
const SEED = join(HERE, 'roles-seed.php');

/** A slug like conversations.view or quick-replies.use. */
const SLUG = /\b[a-z]+(?:-[a-z]+)*\.[a-z]+(?:-[a-z]+)*\b/;

async function settle(page) {
    await page.waitForLoadState('networkidle').catch(() => {});
    await page.waitForTimeout(250);
}

async function openRoles(page) {
    await page.goto(`${BASE}/admin/roles`);
    await settle(page);
}

const card = (page, label) =>
    page.locator('article', {
        has: page.getByRole('heading', { name: label, exact: true }),
    });

async function useCards(page) {
    const toggle = page.getByRole('radio', { name: 'Vista de tarjetas' });

    if ((await toggle.count()) > 0) {
        await toggle.click();
    } else {
        await page.getByRole('button', { name: 'Vista de tarjetas' }).click();
    }

    await page.waitForTimeout(200);
}

const run = async () => {
    record(
        'seed',
        'roles-seed.php',
        true,
        php(SEED).trim().replace(/\s+/g, ' '),
    );
    const browser = await launchBrowser();

    try {
        const root = await openPage(browser, { prefix: '[root] ' });
        await login(root, USERS.superadmin);
        await openRoles(root);
        await useCards(root);
        const rootState = await root.context().storageState();

        await step('cards-summary', 'root, cards view', async () => {
            const notes = [];
            const text = await root.locator('main').innerText();

            for (const label of [
                'Superadministrador',
                'Administrador',
                'Supervisor',
                'Agente',
            ]) {
                if ((await card(root, label).count()) !== 1) {
                    notes.push(`card ${label} missing`);
                }
            }

            const agent = await card(root, 'Agente').innerText();

            if (
                !/permisos? habilitados?/.test(agent) ||
                !/Acceso a \d+ áreas?/.test(agent)
            ) {
                notes.push(`agent summary: ${agent.replace(/\s+/g, ' ')}`);
            }

            if (!/Solo lo asignado/.test(agent) || !/3 usuarios/.test(agent)) {
                notes.push('agent scope/users missing');
            }

            if (/·\s*\d+\/\d+/.test(text)) {
                notes.push('per-module chips still shown');
            }

            if (
                !/Sin usuarios/.test(await card(root, 'Supervisor').innerText())
            ) {
                notes.push('supervisor without «Sin usuarios»');
            }

            await shot(root, 'cards-root-1366');

            return [notes.length === 0, notes.join('; ') || 'ok'];
        });

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
                                storageState: rootState,
                            },
                            prefix: `[${width}${scheme}] `,
                        });

                        try {
                            await openRoles(page);
                            const by = await overflow(page, { width });
                            const cards = await page.locator('article').count();
                            await shot(page, `cards-${width}-${scheme}`);

                            return [
                                by <= 0 && cards >= 4,
                                `overflow ${by}, ${cards} cards`,
                            ];
                        } finally {
                            await page.context().close();
                        }
                    },
                );
            }
        }

        await step(
            'sheet-groups',
            'root, Ver permisos of Administrador',
            async () => {
                const notes = [];
                await card(root, 'Administrador')
                    .getByRole('button', { name: 'Ver permisos' })
                    .click();
                const sheet = root.getByRole('dialog');
                await sheet.waitFor();
                await root.waitForTimeout(400);

                const title = await sheet
                    .getByRole('heading', { level: 2 })
                    .first()
                    .innerText();
                const triggers = sheet.locator('h3 > button');
                const count = await triggers.count();
                const text = await sheet.innerText();

                if (!title.includes('Administrador')) {
                    notes.push(`title ${title}`);
                }

                if (count < 10) {
                    notes.push(`${count} module headings`);
                }

                if (SLUG.test(text)) {
                    notes.push(`technical name: ${text.match(SLUG)[0]}`);
                }

                if (
                    !/Toda la organización/.test(text) ||
                    !/permisos\s+habilitados/.test(text)
                ) {
                    notes.push('scope or summary missing in header');
                }

                if (!/Permitido/.test(text)) {
                    notes.push('no «Permitido» state');
                }

                // Keyboard: focus a collapsed trigger and open it with Enter.
                const states = await triggers.evaluateAll((buttons) =>
                    buttons.map((button) =>
                        button.getAttribute('aria-expanded'),
                    ),
                );
                // A fixed trigger: an attribute locator would move on to the
                // next collapsed module once this one opens.
                const collapsed = triggers.nth(
                    Math.max(0, states.indexOf('false')),
                );
                await collapsed.focus();
                await root.keyboard.press('Enter');
                await root.waitForTimeout(300);
                const opened = await collapsed.getAttribute('aria-expanded');
                await root.keyboard.press('Space');
                await root.waitForTimeout(300);
                const closedAgain =
                    await collapsed.getAttribute('aria-expanded');

                if (opened !== 'true' || closedAgain !== 'false') {
                    notes.push(`keyboard toggle ${opened}/${closedAgain}`);
                }

                await sheet
                    .getByRole('button', { name: 'Expandir todo' })
                    .click();
                await root.waitForTimeout(400);
                const expanded = await sheet
                    .locator('h3 > button[aria-expanded="true"]')
                    .count();

                if (expanded !== count) {
                    notes.push(`expand all ${expanded}/${count}`);
                }

                await shot(root, 'sheet-administrator-1366', {
                    fullPage: false,
                });

                return [
                    notes.length === 0,
                    notes.join('; ') || `${count} modules`,
                ];
            },
        );

        await step(
            'sheet-only-enabled',
            'switch off shows not granted',
            async () => {
                const sheet = root.getByRole('dialog');
                const status = sheet.locator('[aria-live="polite"]').first();
                const before = await status.innerText();
                await sheet.getByRole('switch').click();
                await root.waitForTimeout(300);
                const after = await status.innerText();
                const text = await sheet.innerText();
                const n = (value) => Number(value.match(/\d+/)?.[0] ?? 0);
                await shot(root, 'sheet-administrator-all-1366', {
                    fullPage: false,
                });
                await root.keyboard.press('Escape');
                await root.waitForTimeout(300);

                return [
                    n(after) > n(before) && /No permitido/.test(text),
                    `${before} → ${after}`,
                ];
            },
        );

        await step(
            'sheet-mobile',
            '375px light, Ver permisos of Agente',
            async () => {
                const page = await openPage(browser, {
                    context: {
                        viewport: { width: 375, height: 812 },
                        hasTouch: true,
                        isMobile: true,
                        storageState: rootState,
                    },
                    prefix: '[sheet375] ',
                });

                try {
                    await openRoles(page);
                    await card(page, 'Agente')
                        .getByRole('button', { name: 'Ver permisos' })
                        .click();
                    await page.getByRole('dialog').waitFor();
                    await page.waitForTimeout(400);
                    const by = await overflow(page, { width: 375 });
                    await shot(page, 'sheet-agent-375', { fullPage: false });
                    await page.colorScheme?.('dark');
                    await page.emulateMedia({ colorScheme: 'dark' });
                    await page.waitForTimeout(200);
                    await shot(page, 'sheet-agent-375-dark', {
                        fullPage: false,
                    });

                    return [by <= 0, `overflow ${by}`];
                } finally {
                    await page.context().close();
                }
            },
        );

        await step('scope-keyboard', 'Agente editor, arrow keys', async () => {
            const notes = [];
            await openRoles(root);
            await card(root, 'Agente')
                .getByRole('button', { name: /^Editar/ })
                .first()
                .click();
            const dialog = root.getByRole('dialog');
            await dialog.waitFor();
            await root.waitForTimeout(300);

            const group = dialog.getByRole('radiogroup');
            const checked = () =>
                group
                    .locator('[role="radio"][aria-checked="true"]')
                    .innerText();
            const tabbable = await group
                .locator('[role="radio"][tabindex="0"]')
                .count();

            if (tabbable !== 1) {
                notes.push(`${tabbable} tabbable radios`);
            }

            await group.locator('[role="radio"][aria-checked="true"]').focus();
            const start = await checked();
            await root.keyboard.press('ArrowLeft');
            await root.waitForTimeout(200);
            const left = await checked();
            const focused = await root.evaluate(() =>
                document.activeElement?.getAttribute('aria-checked'),
            );
            const note = await dialog.innerText();

            if (
                !/Solo lo asignado/.test(start) ||
                !/Sus departamentos/.test(left)
            ) {
                notes.push(
                    `selection ${start.split('\n')[0]} → ${left.split('\n')[0]}`,
                );
            }

            if (focused !== 'true') {
                notes.push('focus did not follow the selection');
            }

            if (
                !/Qué cambia al guardar/.test(note) ||
                !/3 personas con este rol podrán consultar/.test(note)
            ) {
                notes.push('consequence text missing');
            }

            if (/Actúa/.test(note)) {
                notes.push('«Actúa» still shown');
            }

            await shot(root, 'editor-scope-agent', { fullPage: false });
            await root.keyboard.press('ArrowRight');
            await root.waitForTimeout(200);
            const back = await checked();

            if (
                !/Solo lo asignado/.test(back) ||
                /Qué cambia al guardar/.test(await dialog.innerText())
            ) {
                notes.push('ArrowRight did not return to the original scope');
            }

            await root.keyboard.press('Escape');
            await root.waitForTimeout(300);

            return [notes.length === 0, notes.join('; ') || 'ok'];
        });

        await step(
            'edit-warnings',
            'Supervisor editor (0 holders), save',
            async () => {
                const notes = [];
                await card(root, 'Supervisor')
                    .getByRole('button', { name: /^Editar/ })
                    .first()
                    .click();
                const dialog = root.getByRole('dialog');
                await dialog.waitFor();
                await root.waitForTimeout(300);
                const text = await dialog.innerText();

                if (!/A quién afecta/.test(text)) {
                    notes.push('no «A quién afecta»');
                }

                if (!/no está asignado a ningún usuario activo/.test(text)) {
                    notes.push('no 0-holder warning');
                }

                const description = dialog.locator('#role-description');
                await description.fill(
                    'Supervisa las conversaciones de sus departamentos (E2E).',
                );
                await shot(root, 'editor-supervisor', { fullPage: false });
                await dialog
                    .getByRole('button', { name: 'Guardar rol' })
                    .click();
                const toast = await toastText(root, /Supervisor|rol/i).catch(
                    (error) => String(error),
                );
                await root.waitForTimeout(500);
                const closed = (await root.getByRole('dialog').count()) === 0;

                if (!closed) {
                    notes.push('dialog stayed open');
                }

                return [
                    notes.length === 0,
                    `${notes.join('; ')} toast: ${String(toast).slice(0, 120)}`,
                ];
            },
        );

        await root.context().close();

        await step(
            'administrator-no-root',
            'Administrador roles page and detail',
            async () => {
                const notes = [];
                const page = await openPage(browser, {
                    prefix: '[administrador] ',
                });

                try {
                    await login(page, USERS.administrator);
                    await openRoles(page);
                    await useCards(page);
                    const text = await page.locator('main').innerText();

                    if (/Superadministrador/i.test(text)) {
                        notes.push('root role text visible');
                    }

                    if ((await page.locator('article').count()) !== 3) {
                        notes.push(
                            `${await page.locator('article').count()} cards`,
                        );
                    }

                    await shot(page, 'cards-administrator-1366');
                    await card(page, 'Administrador')
                        .getByRole('button', { name: 'Ver permisos' })
                        .click();
                    const sheet = page.getByRole('dialog');
                    await sheet.waitFor();
                    await sheet.getByRole('switch').click();
                    await sheet
                        .getByRole('button', { name: 'Expandir todo' })
                        .click();
                    await page.waitForTimeout(400);
                    const detail = await sheet.innerText();

                    if (
                        /superadministrador/i.test(detail) ||
                        /Importar contactos/i.test(detail)
                    ) {
                        notes.push(
                            'reserved permission or root reference in detail',
                        );
                    }

                    await shot(page, 'sheet-administrator-as-administrador', {
                        fullPage: false,
                    });
                } finally {
                    await page.context().close();
                }

                return [notes.length === 0, notes.join('; ') || 'ok'];
            },
        );
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
