/*
 * Browser scenarios of the integral corrections to increments 01, A and B:
 * logout confirmation, no passkey login, hover help, single tag preview,
 * custom colors, live validation, unchanged forms, logical deletion and
 * restore, fully clickable cards, Excel/PDF exports and permission-gated UI.
 *
 * Real Chromium, real mouse and keyboard. Run it through the runner, which
 * rebuilds the testing database (chatmanager_testing) and starts the server:
 *
 *   php tests/e2e/run.php corrections.mjs
 *
 * Environment (set by the runner): E2E_BASE_URL, APP_ENV=testing,
 * PLAYWRIGHT_MODULE, optional E2E_SCREENSHOTS (also receives the exported
 * files so they can be inspected).
 */
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import {
    BASE,
    SHOTS,
    USERS,
    chromium,
    exitWithSummary,
    inbound,
    login,
    openPage,
    recordPageProblems,
    step,
} from './lib.mjs';

const newPage = (browser, options = {}) =>
    openPage(browser, {
        context: { acceptDownloads: true, ...options },
        ignoreConsole: /status of (404|409|422|429)/,
    });

async function download(page, trigger, name) {
    const [file] = await Promise.all([
        page.waitForEvent('download'),
        trigger(),
    ]);
    const path = SHOTS ? join(SHOTS, name) : await file.path();

    if (SHOTS) {
        await file.saveAs(path);
    }

    return { bytes: readFileSync(path), suggested: file.suggestedFilename() };
}

async function exportFrom(page, menuName, format, name) {
    await page.getByRole('button', { name: menuName }).first().click();

    return download(
        page,
        () => page.getByRole('menuitem', { name: format }).click(),
        name,
    );
}

const run = async () => {
    const browser = await chromium.launch();

    try {
        const guest = await newPage(browser);

        await step('login-without-passkey', 'render', async () => {
            await guest.goto(`${BASE}/login`);
            const passkey = await guest
                .getByText(/llave de acceso|passkey/i)
                .count();
            const passkeyRoute = await guest.request.get(
                `${BASE}/passkeys/login/options`,
            );

            return [
                passkey === 0 && passkeyRoute.status() === 404,
                `passkey texts=${passkey} route=${passkeyRoute.status()}`,
            ];
        });

        const ana = await newPage(browser);
        await login(ana, USERS.ana);

        await step('logout-cancel-keeps-session', 'mouse', async () => {
            await ana
                .getByRole('button', { name: /E2E Ana/ })
                .first()
                .click();
            await ana.getByRole('menuitem', { name: 'Cerrar sesión' }).click();
            const dialog = ana
                .getByRole('alertdialog')
                .or(ana.getByRole('dialog'));
            await dialog.getByText('¿Cerrar sesión?').waitFor();
            await dialog.getByRole('button', { name: 'Cancelar' }).click();
            await dialog.waitFor({ state: 'detached' });

            return [!ana.url().includes('/login'), ana.url()];
        });

        await step(
            'help-on-hover-never-toggles-switch',
            'hover + click',
            async () => {
                await ana.goto(`${BASE}/conversations`);
                const toggle = ana.getByRole('switch').first();
                const before = await toggle.getAttribute('aria-checked');
                const help = ana.getByRole('button', {
                    name: 'Ayuda: Disponibilidad',
                });
                await help.hover();
                await ana
                    .getByText(/asignación automática|disponib/i)
                    .nth(1)
                    .waitFor({ timeout: 3_000 });
                await help.click();
                const after = await toggle.getAttribute('aria-checked');

                return [before === after, `switch ${before} -> ${after}`];
            },
        );

        await step(
            'agent-has-no-deleted-filter-nor-export',
            'render',
            async () => {
                await ana.goto(`${BASE}/contacts`);
                const deletedFilter = await ana
                    .getByRole('combobox', { name: 'Estado del registro' })
                    .count();
                const exportMenu = await ana
                    .getByRole('button', { name: 'Exportar' })
                    .count();

                return [
                    deletedFilter === 0 && exportMenu === 0,
                    `filter=${deletedFilter} export=${exportMenu}`,
                ];
            },
        );

        await step('logout-confirmed', 'keyboard', async () => {
            await ana
                .getByRole('button', { name: /E2E Ana/ })
                .first()
                .click();
            await ana.getByRole('menuitem', { name: 'Cerrar sesión' }).click();
            const dialog = ana
                .getByRole('alertdialog')
                .or(ana.getByRole('dialog'));
            await dialog.getByRole('button', { name: 'Cerrar sesión' }).click();
            await ana.waitForURL(/\/login/);
            const back = await ana.goto(`${BASE}/dashboard`);

            return [
                ana.url().includes('/login'),
                `after logout dashboard -> ${back?.url()}`,
            ];
        });

        const admin = await newPage(browser);
        await login(admin, USERS.admin);

        await step(
            'tag-create-custom-color-single-preview',
            'mouse + keyboard',
            async () => {
                await admin.goto(`${BASE}/tags`);
                await admin
                    .getByRole('button', { name: 'Nueva etiqueta' })
                    .first()
                    .click();
                const dialog = admin.getByRole('dialog');
                await dialog.locator('#tag-name').click();
                await admin.keyboard.type('E2E Color');
                const previews = await dialog
                    .getByText('E2E Color', { exact: true })
                    .count();
                await dialog.locator('[data-test="custom-color"]').click();
                await dialog
                    .locator('input[type="color"]')
                    .evaluate((input) => {
                        input.value = '#123abc';
                        input.dispatchEvent(
                            new Event('input', { bubbles: true }),
                        );
                        input.dispatchEvent(
                            new Event('change', { bubbles: true }),
                        );
                    });
                const custom = await dialog
                    .locator('[data-test="custom-color"]')
                    .getAttribute('aria-label');
                await dialog.locator('button[type="submit"]').click();
                await dialog.waitFor({ state: 'detached' });
                await admin.getByText('E2E Color').first().waitFor();

                return [
                    previews === 1 && /#123abc/.test(custom ?? ''),
                    `previews=${previews} picker="${custom}"`,
                ];
            },
        );

        await step(
            'unchanged-form-cannot-save-and-live-validation',
            'keyboard',
            async () => {
                await admin
                    .getByRole('button', {
                        name: 'Editar la etiqueta E2E Color',
                    })
                    .first()
                    .click();
                const dialog = admin.getByRole('dialog');
                const submit = dialog.locator('button[type="submit"]');
                const unchanged = await submit.isDisabled();
                const noChangesText =
                    (await dialog
                        .getByText('Sin cambios por guardar')
                        .count()) > 0;
                const name = dialog.locator('#tag-name');
                await name.click();
                await admin.keyboard.press('End');
                await admin.keyboard.type('x');
                const changed = await submit.isEnabled();
                await admin.keyboard.press('Backspace');
                const reverted = await submit.isDisabled();
                await name.fill('');
                await dialog.locator('#tag-description').click();
                const required =
                    (await dialog.locator('[aria-invalid="true"]').count()) > 0;
                // An invalid form is never sent: submitting keeps the dialog
                // open, shows the error and focuses the invalid field.
                let sent = false;
                const watch = (request) => {
                    if (
                        request.method() !== 'GET' &&
                        request.url().includes('/tags')
                    ) {
                        sent = true;
                    }
                };
                admin.on('request', watch);
                await submit.click();
                await admin.waitForTimeout(500);
                admin.off('request', watch);
                const blocked =
                    !sent &&
                    (await dialog.isVisible()) &&
                    (await name.evaluate(
                        (input) => input === document.activeElement,
                    ));
                await admin.keyboard.press('Escape');
                await admin
                    .getByRole('button', { name: /Descartar/ })
                    .click()
                    .catch(() => {});
                await dialog.waitFor({ state: 'detached' }).catch(() => {});

                return [
                    unchanged &&
                        noChangesText &&
                        changed &&
                        reverted &&
                        required &&
                        blocked,
                    `unchanged=${unchanged} text=${noChangesText} changed=${changed} reverted=${reverted} required=${required} blocked=${blocked}`,
                ];
            },
        );

        await step('logical-delete-and-restore', 'mouse', async () => {
            await admin.goto(`${BASE}/tags`);
            await admin
                .getByRole('button', { name: 'Eliminar la etiqueta E2E Color' })
                .first()
                .click();
            await admin
                .getByRole('alertdialog')
                .or(admin.getByRole('dialog'))
                .getByRole('button', { name: 'Eliminar' })
                .click();
            await admin
                .getByRole('button', { name: 'Eliminar la etiqueta E2E Color' })
                .waitFor({ state: 'detached' });
            const goneFromActive =
                (await admin
                    .getByRole('button', {
                        name: 'Editar la etiqueta E2E Color',
                    })
                    .count()) === 0;
            await admin
                .getByRole('combobox', { name: 'Estado del registro' })
                .click();
            await admin.getByRole('option', { name: 'Eliminados' }).click();
            await admin
                .getByRole('button', {
                    name: 'Restaurar la etiqueta E2E Color',
                })
                .first()
                .waitFor();
            const badge =
                (await admin.getByText('Eliminada', { exact: false }).count()) >
                0;
            await admin
                .getByRole('button', {
                    name: 'Restaurar la etiqueta E2E Color',
                })
                .first()
                .click();
            await admin
                .getByRole('alertdialog')
                .or(admin.getByRole('dialog'))
                .getByRole('button', { name: 'Restaurar' })
                .click();
            await admin
                .getByRole('button', {
                    name: 'Restaurar la etiqueta E2E Color',
                })
                .waitFor({ state: 'detached' });

            return [
                goneFromActive && badge,
                `goneFromActive=${goneFromActive} deletedBadge=${badge}`,
            ];
        });

        await step('whole-card-opens-detail', 'mouse', async () => {
            await admin.goto(`${BASE}/departments`);
            await admin
                .getByRole('button', { name: 'Vista de tarjetas' })
                .click();
            const card = admin.locator('article').first();
            const box = await card.boundingBox();
            await admin.mouse.click(
                box.x + box.width - 12,
                box.y + box.height / 2,
            );
            await admin.waitForURL(/\/departments\/\d+/);

            return [true, admin.url()];
        });

        await step('exports-xlsx-and-pdf', 'mouse', async () => {
            await admin.goto(`${BASE}/tags`);
            const xlsx = await exportFrom(
                admin,
                'Exportar',
                'Excel (.xlsx)',
                'etiquetas.xlsx',
            );
            const pdf = await exportFrom(
                admin,
                'Exportar',
                'PDF',
                'etiquetas.pdf',
            );
            await admin.goto(`${BASE}/dashboard`);
            const dashboard = await exportFrom(
                admin,
                'Exportar',
                'PDF',
                'dashboard.pdf',
            );
            const zip = xlsx.bytes.subarray(0, 2).toString() === 'PK';
            const pdfOk =
                pdf.bytes.subarray(0, 4).toString() === '%PDF' &&
                dashboard.bytes.subarray(0, 4).toString() === '%PDF';

            return [
                zip && pdfOk,
                `${xlsx.suggested} ${pdf.suggested} ${dashboard.suggested}`,
            ];
        });

        await step('conversation-dossier-pdf', 'mouse', async () => {
            const [{ conversation }] = inbound(
                '5215511119999',
                'Cliente Expediente',
                'Necesito ayuda con mi pedido',
            );
            await admin.goto(`${BASE}/conversations/${conversation}`);
            const dossier = await exportFrom(
                admin,
                'Exportar expediente',
                'PDF',
                'expediente.pdf',
            );

            return [
                dossier.bytes.subarray(0, 4).toString() === '%PDF',
                dossier.suggested,
            ];
        });

        await step('responsive-dark-users', '390px dark', async () => {
            const phone = await newPage(browser, {
                viewport: { width: 390, height: 844 },
                colorScheme: 'dark',
            });
            await login(phone, USERS.admin);
            await phone.goto(`${BASE}/admin/users`);
            const overflow = await phone.evaluate(
                () => document.documentElement.scrollWidth - window.innerWidth,
            );
            if (SHOTS) {
                await phone.screenshot({
                    path: join(SHOTS, 'users-phone-dark.png'),
                    fullPage: true,
                });
            }

            return [overflow <= 0, `overflow=${overflow}`];
        });
    } finally {
        await browser.close();
    }

    recordPageProblems(500);
    exitWithSummary();
};

run().catch((error) => {
    console.error(error);
    process.exit(1);
});
