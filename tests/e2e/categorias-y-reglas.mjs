/*
 * Request categories and service rules at their three levels (platform,
 * department, category), written only through the interface and checked
 * against the database with tests/e2e/categorias-y-reglas-seed.php, on top
 * of tests/e2e/increment-b-seed.php (superadministrator, E2E Ana in E2E
 * Ventas).
 *
 *   categories-empty            «Categorías de solicitud» starts with its
 *                               empty state and offers «Nueva categoría»
 *   category-dialog-keyboard    the form dialog traps the focus, Escape
 *                               closes it and the focus returns to the button
 *   category-required           an empty name is refused with its message
 *   category-create             a category is created and listed
 *   category-duplicate          the same name in another case is refused
 *   category-edit               renamed from «Editar»
 *   category-rules-inherit      its rules dialog starts inheriting («Usa el
 *                               valor general: …»); the rules a category
 *                               cannot set say who defines them
 *   category-rules-validation   an out-of-range own value is refused, with
 *                               the range in the message, and nothing is saved
 *   category-rules-custom       two own values are saved («Valor propio»,
 *                               «2 reglas propias»)
 *   category-rules-reinherit    one goes back to «Usar el valor general»
 *   platform-blocks             Configuración shows the four blocks apart
 *   platform-validation         out-of-range general values are refused
 *   platform-save               general values are saved
 *   department-inherits         the department card shows the general values
 *                               just saved as inherited
 *   department-validation       an out-of-range own value is refused
 *   department-discard          «Descartar» returns the card to what is saved
 *   department-custom           own values are saved and survive a reload
 *   category-delete-restore     logical deletion with confirmation, the
 *                               «Eliminados» filter and «Restaurar»
 *   audit-trail                 every change left its audit entry
 *   agent-forbidden             an agent has no menu entry and gets 403 on
 *                               the pages and on a direct write
 *   layout-<screen>-<w>-<s>     categories (list and both dialogs),
 *                               Configuración and the department page from
 *                               320 to 1440 px, light and dark: no horizontal
 *                               overflow
 *   no-page-errors              no console errors, uncaught exceptions,
 *                               unhandled rejections or 5xx (also checked per
 *                               step)
 *
 *   E2E_PORT=8172 TEST_DB_LOCK_WAIT=7200 php tests/e2e/run.php categorias-y-reglas.mjs
 */
import { join } from 'node:path';
import {
    BASE,
    HERE,
    USERS,
    clean,
    dialogKeyboard,
    domGarbage,
    exitWithSummary,
    launchBrowser,
    layoutSweep,
    login,
    openPage,
    php,
    printProblems,
    recordPageProblems,
    savePlatformRules,
    shot,
    step,
    toastText,
} from './lib.mjs';

const SEED = join(HERE, 'categorias-y-reglas-seed.php');
const state = () => JSON.parse(php(SEED, 'state'));
const NAME = 'E2E Garantías';
const RENAMED = 'E2E Garantías y devoluciones';
const FIRST_RESPONSE = 'sla.first_response_minutes';
const MIN_WAIT = 'queue.min_wait_minutes';
const ORDER = 'queue.chronological_order';

const dialog = (page) => page.locator('[role="dialog"]').last();

async function openCategories(page, query = '') {
    await page.goto(`${BASE}/categories${query}`);
    await page.waitForLoadState('networkidle');
}

/** A record action of a category row: a visible icon button or an entry of «Más acciones». */
async function categoryAction(page, name, label) {
    const group = page.getByRole('group', {
        name: `Acciones de la categoría ${name}`,
        exact: true,
    });
    const direct = group.getByRole('button', {
        name: `${label} la categoría ${name}`,
        exact: true,
    });

    if ((await direct.count()) > 0) {
        await direct.click();

        return;
    }

    await group.getByRole('button', { name: /^Más acciones/ }).click();
    await page.getByRole('menuitem', { name: label, exact: true }).click();
}

const ruleRow = (scope, key) => scope.locator(`[data-service-rule="${key}"]`);

const rowText = async (scope, key) =>
    (await ruleRow(scope, key).innerText()).replace(/\s+/g, ' ').trim();

const run = async () => {
    const browser = await launchBrowser();

    try {
        const admin = await openPage(browser, { trackToasts: true });
        await login(admin, USERS.admin);
        const salesId = state().sales;

        await step(
            'categories-empty',
            '/categories',
            clean(async () => {
                await openCategories(admin);
                const empty = await admin
                    .getByText('Aún no hay categorías')
                    .isVisible();
                const create = await admin
                    .locator('[data-tour="categories.new"]')
                    .isVisible();
                const garbage = await domGarbage(admin);
                await shot(admin, 'categorias-vacio');

                return [
                    empty && create && garbage.length === 0,
                    `empty=${empty} create=${create} garbage=${JSON.stringify(garbage)}`,
                ];
            }),
        );

        await step(
            'category-dialog-keyboard',
            'Tab / Shift+Tab / Escape',
            clean(async () => {
                const trigger = admin.locator('[data-tour="categories.new"]');

                return dialogKeyboard(admin, {
                    open: async () => {
                        await trigger.focus();
                        await admin.keyboard.press('Enter');
                    },
                    returnsTo: trigger,
                });
            }),
        );

        await step(
            'category-required',
            'empty name',
            clean(async () => {
                await admin.locator('[data-tour="categories.new"]').click();
                await dialog(admin).waitFor();
                await dialog(admin)
                    .getByRole('button', { name: 'Crear categoría' })
                    .click();
                const message = await dialog(admin)
                    .getByText('Escribe el nombre.')
                    .isVisible();
                const invalid = await admin
                    .locator('#category-name')
                    .getAttribute('aria-invalid');

                return [
                    message &&
                        invalid === 'true' &&
                        state().categories.length === 0,
                    `message=${message} aria-invalid=${invalid} stored=${state().categories.length}`,
                ];
            }),
        );

        await step(
            'category-create',
            NAME,
            clean(async () => {
                await admin.locator('#category-name').fill(NAME);
                await admin
                    .locator('#category-description')
                    .fill('Reclamos por productos con falla.');
                await dialog(admin)
                    .getByRole('button', { name: 'Crear categoría' })
                    .click();
                await dialog(admin).waitFor({ state: 'detached' });
                const listed = await admin
                    .getByRole('row', { name: new RegExp(NAME) })
                    .isVisible();
                const stored = state().categories;
                await shot(admin, 'categorias-lista');

                return [
                    listed &&
                        stored.length === 1 &&
                        stored[0].name === NAME &&
                        stored[0].is_active,
                    `listed=${listed} stored=${JSON.stringify(stored)}`,
                ];
            }),
        );

        await step(
            'category-duplicate',
            'e2e garantías',
            clean(async () => {
                await admin.locator('[data-tour="categories.new"]').click();
                await dialog(admin).waitFor();
                await admin.locator('#category-name').fill('e2e garantías');
                await dialog(admin)
                    .getByRole('button', { name: 'Crear categoría' })
                    .click();
                const message = await dialog(admin)
                    .getByText(/Ya existe una categoría con ese nombre/)
                    .waitFor({ timeout: 8_000 })
                    .then(() => true)
                    .catch(() => false);
                const open = await dialog(admin).isVisible();
                const stored = state().categories.length;
                await openCategories(admin);

                return [
                    message && open && stored === 1,
                    `message=${message} dialog kept open=${open} stored=${stored}`,
                ];
            }),
        );

        await step(
            'category-edit',
            RENAMED,
            clean(async () => {
                await categoryAction(admin, NAME, 'Editar');
                await dialog(admin).waitFor();
                await admin.locator('#category-name').fill(RENAMED);
                await dialog(admin)
                    .getByRole('button', { name: 'Guardar cambios' })
                    .click();
                await dialog(admin).waitFor({ state: 'detached' });
                const listed = await admin
                    .getByRole('row', { name: new RegExp(RENAMED) })
                    .isVisible();

                return [
                    listed && state().categories[0].name === RENAMED,
                    `listed=${listed} stored="${state().categories[0].name}"`,
                ];
            }),
        );

        await step(
            'category-rules-inherit',
            'Reglas de servicio',
            clean(async () => {
                await categoryAction(admin, RENAMED, 'Reglas de servicio');
                await dialog(admin).waitFor();
                const first = await rowText(dialog(admin), FIRST_RESPONSE);
                const wait = await rowText(dialog(admin), MIN_WAIT);
                const order = await rowText(dialog(admin), ORDER);
                const inherited = await dialog(admin)
                    .locator('[data-service-rule-state="inherited"]')
                    .count();
                const own = await dialog(admin)
                    .locator('[data-service-rule-state="own"]')
                    .count();
                const blocks = await dialog(admin)
                    .locator('[data-service-rules-block]')
                    .count();
                const saveDisabled = await dialog(admin)
                    .getByRole('button', { name: 'Guardar reglas' })
                    .isDisabled();
                await shot(admin, 'categorias-reglas-heredadas');

                return [
                    /Usa el valor general: Sin objetivo/.test(first) &&
                        /Usa el valor general: Sin espera mínima/.test(wait) &&
                        /Lo define el departamento/.test(order) &&
                        inherited === 6 &&
                        own === 0 &&
                        blocks === 3 &&
                        saveDisabled,
                    `first="${first}" wait="${wait}" order="${order.slice(0, 70)}" inherited=${inherited} own=${own} blocks=${blocks} saveDisabled=${saveDisabled}`,
                ];
            }),
        );

        await step(
            'category-rules-validation',
            'first response = 0',
            clean(async () => {
                const row = ruleRow(dialog(admin), FIRST_RESPONSE);
                await row
                    .getByRole('button', { name: /^Personalizar/ })
                    .click();
                const own = await row
                    .locator('[data-service-rule-state="own"]')
                    .innerText();
                const suggested = await row
                    .locator('input[type="number"]')
                    .inputValue();
                await row.locator('input[type="number"]').fill('0');
                await dialog(admin)
                    .getByRole('button', { name: 'Guardar reglas' })
                    .click();
                const message = await row
                    .getByText('Escribe un valor entre 1 y 43200.')
                    .isVisible();
                const stored = state().categories[0].service_rules;

                return [
                    own.trim() === 'Valor propio' &&
                        suggested === '15' &&
                        message &&
                        (stored === null || Object.keys(stored).length === 0),
                    `badge="${own.trim()}" suggested=${suggested} message=${message} stored=${JSON.stringify(stored)}`,
                ];
            }),
        );

        await step(
            'category-rules-custom',
            'first response 20, min wait 3',
            clean(async () => {
                await ruleRow(dialog(admin), FIRST_RESPONSE)
                    .locator('input[type="number"]')
                    .fill('20');
                const wait = ruleRow(dialog(admin), MIN_WAIT);
                await wait
                    .getByRole('button', { name: /^Personalizar/ })
                    .click();
                await wait.locator('input[type="number"]').fill('3');
                await dialog(admin)
                    .getByRole('button', { name: 'Guardar reglas' })
                    .click();
                await dialog(admin).waitFor({ state: 'detached' });
                const summary = await admin
                    .getByRole('row', { name: new RegExp(RENAMED) })
                    .innerText();
                const stored = state().categories[0].service_rules ?? {};

                return [
                    /2 reglas propias/.test(summary) &&
                        stored[FIRST_RESPONSE] === 20 &&
                        stored[MIN_WAIT] === 3 &&
                        Object.keys(stored).length === 2,
                    `row="${summary.replace(/\s+/g, ' ')}" stored=${JSON.stringify(stored)}`,
                ];
            }),
        );

        await step(
            'category-rules-reinherit',
            'min wait back to the general value',
            clean(async () => {
                await categoryAction(admin, RENAMED, 'Reglas de servicio');
                await dialog(admin).waitFor();
                const own = await dialog(admin)
                    .locator('[data-service-rule-state="own"]')
                    .count();
                const value = await ruleRow(dialog(admin), FIRST_RESPONSE)
                    .locator('input[type="number"]')
                    .inputValue();
                await shot(admin, 'categorias-reglas-propias');
                await ruleRow(dialog(admin), MIN_WAIT)
                    .getByRole('button', { name: /^Usar el valor general/ })
                    .click();
                await dialog(admin)
                    .getByRole('button', { name: 'Guardar reglas' })
                    .click();
                await dialog(admin).waitFor({ state: 'detached' });
                const summary = await admin
                    .getByRole('row', { name: new RegExp(RENAMED) })
                    .innerText();
                const stored = state().categories[0].service_rules ?? {};

                return [
                    own === 2 &&
                        value === '20' &&
                        /1 regla propia/.test(summary) &&
                        stored[FIRST_RESPONSE] === 20 &&
                        !(MIN_WAIT in stored),
                    `own on reopen=${own} value=${value} row="${summary.replace(/\s+/g, ' ')}" stored=${JSON.stringify(stored)}`,
                ];
            }),
        );

        await step(
            'platform-blocks',
            '/admin/settings',
            clean(async () => {
                await admin.goto(`${BASE}/admin/settings`);
                await admin.waitForLoadState('networkidle');
                const titles = await admin
                    .locator(
                        '[data-settings-service-rules] [data-service-rules-block] h3',
                    )
                    .allInnerTexts();
                const alerts = await admin
                    .getByRole('heading', {
                        name: 'Alertas automáticas de SLA',
                    })
                    .isVisible();
                const switches = await admin
                    .locator('[data-settings-sla-alerts]')
                    .getByRole('switch')
                    .count();
                const garbage = await domGarbage(admin);
                const expected = [
                    'Tiempo máximo de respuesta (SLA)',
                    'Solicitud completa / Lista para atención',
                    'Tiempo mínimo de espera y orden de atención',
                ];

                return [
                    JSON.stringify(titles.map((title) => title.trim())) ===
                        JSON.stringify(expected) &&
                        alerts &&
                        switches === 4 &&
                        garbage.length === 0,
                    `blocks=${JSON.stringify(titles)} alerts=${alerts} alert switches=${switches} garbage=${JSON.stringify(garbage)}`,
                ];
            }),
        );

        await step(
            'platform-validation',
            'first response 0, reminder 2',
            clean(async () => {
                let sent = 0;
                const count = (request) => {
                    if (request.method() === 'PUT') {
                        sent++;
                    }
                };
                admin.on('request', count);
                const row = ruleRow(
                    admin.locator('[data-settings-service-rules]'),
                    FIRST_RESPONSE,
                );
                await row.locator('input[type="number"]').fill('0');
                await admin.locator('#sla-reminder-minutes').fill('2');
                await admin
                    .getByRole('button', { name: 'Guardar configuración' })
                    .click();
                await admin.waitForTimeout(600);
                const ruleError = (await row.innerText())
                    .replace(/\s+/g, ' ')
                    .match(/Escribe[^.]*\./)?.[0];
                const reminderError = await admin
                    .locator('#sla-reminder-minutes-error')
                    .innerText()
                    .catch(() => '');
                admin.off('request', count);
                await shot(admin, 'configuracion-validacion');
                const stored = state().platform;

                return [
                    Boolean(ruleError) &&
                        reminderError.trim() !== '' &&
                        sent === 0 &&
                        stored.sla.first_response_minutes === null &&
                        stored.sla_alerts.reminder_minutes === null,
                    `rule error="${ruleError}" reminder error="${reminderError.trim()}" PUT sent=${sent} stored first=${stored.sla.first_response_minutes} reminder=${stored.sla_alerts.reminder_minutes}`,
                ];
            }),
        );

        await step(
            'platform-save',
            'first response 15, min wait 2',
            clean(async () => {
                const saved = await savePlatformRules(admin, {
                    [FIRST_RESPONSE]: 15,
                    [MIN_WAIT]: 2,
                });
                const toast = await toastText(admin, 'Configuración guardada');
                const stored = state().platform;

                return [
                    saved.status !== null &&
                        saved.status < 400 &&
                        saved.clean &&
                        stored.sla.first_response_minutes === 15 &&
                        stored.queue.min_wait_minutes === 2,
                    `status=${saved.status} clean=${saved.clean} toast="${toast}" stored=${JSON.stringify({ sla: stored.sla, queue: stored.queue })}`,
                ];
            }),
        );

        const card = admin.locator('[data-department-service-rules]');

        await step(
            'department-inherits',
            `/departments/${salesId}`,
            clean(async () => {
                await admin.goto(`${BASE}/departments/${salesId}`);
                await admin.waitForLoadState('networkidle');
                await card.scrollIntoViewIfNeeded();
                const first = await rowText(card, FIRST_RESPONSE);
                const wait = await rowText(card, MIN_WAIT);
                const order = await rowText(card, ORDER);
                const own = await card
                    .locator('[data-service-rule-state="own"]')
                    .count();
                const garbage = await domGarbage(admin);
                await shot(admin, 'departamento-reglas-heredadas');

                return [
                    /Usa el valor general: 15 minutos/.test(first) &&
                        /Usa el valor general: 2 minutos/.test(wait) &&
                        /Usa el valor general:/.test(order) &&
                        own === 0 &&
                        garbage.length === 0,
                    `first="${first}" wait="${wait}" order="${order}" own=${own} garbage=${JSON.stringify(garbage)}`,
                ];
            }),
        );

        await step(
            'department-validation',
            'min wait 5000',
            clean(async () => {
                const row = ruleRow(card, MIN_WAIT);
                await row
                    .getByRole('button', { name: /^Personalizar/ })
                    .click();
                const started = await row
                    .locator('input[type="number"]')
                    .inputValue();
                await row.locator('input[type="number"]').fill('5000');
                await card
                    .getByRole('button', { name: 'Guardar reglas' })
                    .click();
                const message = await row
                    .getByText('Escribe un valor entre 0 y 1440.')
                    .isVisible();

                return [
                    started === '2' &&
                        message &&
                        state().department_rules === null,
                    `starts from the inherited value=${started} message=${message} stored=${JSON.stringify(state().department_rules)}`,
                ];
            }),
        );

        await step(
            'department-discard',
            'Descartar',
            clean(async () => {
                await card.getByRole('button', { name: 'Descartar' }).click();
                const own = await card
                    .locator('[data-service-rule-state="own"]')
                    .count();
                const wait = await rowText(card, MIN_WAIT);
                const saveDisabled = await card
                    .getByRole('button', { name: 'Guardar reglas' })
                    .isDisabled();

                return [
                    own === 0 &&
                        /Usa el valor general: 2 minutos/.test(wait) &&
                        saveDisabled,
                    `own=${own} wait="${wait}" saveDisabled=${saveDisabled}`,
                ];
            }),
        );

        await step(
            'department-custom',
            'min wait 5, order on',
            clean(async () => {
                const wait = ruleRow(card, MIN_WAIT);
                await wait
                    .getByRole('button', { name: /^Personalizar/ })
                    .click();
                await wait.locator('input[type="number"]').fill('5');
                const order = ruleRow(card, ORDER);
                await order
                    .getByRole('button', { name: /^Personalizar/ })
                    .click();
                await order.getByRole('switch').click();
                const saved = admin.waitForResponse(
                    (response) =>
                        response.request().method() === 'PUT' &&
                        new URL(response.url()).pathname ===
                            `/departments/${salesId}/service-rules`,
                );
                await card
                    .getByRole('button', { name: 'Guardar reglas' })
                    .click();
                await saved;
                await admin.waitForLoadState('networkidle');
                await admin.reload();
                await admin.waitForLoadState('networkidle');
                const own = await card
                    .locator('[data-service-rule-state="own"]')
                    .count();
                const value = await ruleRow(card, MIN_WAIT)
                    .locator('input[type="number"]')
                    .inputValue();
                const checked = await ruleRow(card, ORDER)
                    .getByRole('switch')
                    .getAttribute('aria-checked');
                const stored = state().department_rules ?? {};
                await shot(admin, 'departamento-reglas-propias');

                return [
                    own === 2 &&
                        value === '5' &&
                        checked === 'true' &&
                        stored[MIN_WAIT] === 5 &&
                        stored[ORDER] === true &&
                        Object.keys(stored).length === 2,
                    `own after reload=${own} value=${value} order=${checked} stored=${JSON.stringify(stored)}`,
                ];
            }),
        );

        await step(
            'category-delete-restore',
            'Eliminar / Eliminados / Restaurar',
            clean(async () => {
                await openCategories(admin);
                await categoryAction(admin, RENAMED, 'Eliminar');
                const confirm = admin.locator(
                    '[role="alertdialog"], [role="dialog"]',
                );
                await confirm
                    .getByRole('button', { name: 'Eliminar', exact: true })
                    .click();
                await admin
                    .getByText('Aún no hay categorías')
                    .waitFor({ timeout: 10_000 });
                const deleted = state().categories[0].is_active === false;
                await admin
                    .getByRole('combobox', {
                        name: 'Mostrar activos o eliminados',
                    })
                    .click();
                await admin
                    .getByRole('option', { name: 'Eliminados', exact: true })
                    .click();
                const row = admin.getByRole('row', {
                    name: new RegExp(RENAMED),
                });
                await row.waitFor({ timeout: 10_000 });
                const badge = /Eliminada/.test(await row.innerText());
                await shot(admin, 'categorias-eliminadas');
                await categoryAction(admin, RENAMED, 'Restaurar');
                await confirm
                    .getByRole('button', { name: 'Restaurar', exact: true })
                    .click();
                await row.waitFor({ state: 'detached', timeout: 10_000 });
                const restored = state().categories[0].is_active === true;
                await openCategories(admin);
                const back = await admin
                    .getByRole('row', { name: new RegExp(RENAMED) })
                    .isVisible();

                return [
                    deleted && badge && restored && back,
                    `deleted=${deleted} «Eliminada» with text=${badge} restored=${restored} listed again=${back}`,
                ];
            }),
        );

        await step('audit-trail', 'audit_logs', async () => {
            const audit = state().audit;
            const expected = [
                'conversation_category.created',
                'conversation_category.updated',
                'conversation_category.rules_updated',
                'conversation_category.deleted',
                'conversation_category.restored',
                'department.service_rules_updated',
                'settings.updated',
            ];
            const missing = expected.filter(
                (action) => !audit.includes(action),
            );
            const rulesUpdates = audit.filter(
                (action) => action === 'conversation_category.rules_updated',
            ).length;

            return [
                missing.length === 0 && rulesUpdates === 2,
                `missing=${JSON.stringify(missing)} category rules updates=${rulesUpdates} (expected 2: the refused ones wrote nothing)`,
            ];
        });

        const categoryId = state().categories[0].id;
        const ana = await openPage(browser, { prefix: '[ana] ' });
        await login(ana, USERS.ana);

        await step(
            'agent-forbidden',
            'E2E Ana (agent)',
            clean(async () => {
                const menu = await ana
                    .locator('a[href$="/categories"]')
                    .count();
                const statuses = {};

                for (const path of [
                    '/categories',
                    '/admin/settings',
                    `/departments/${salesId}`,
                ]) {
                    statuses[path] = (
                        await ana.goto(`${BASE}${path}`)
                    ).status();
                }

                await shot(ana, 'categorias-agente-403');
                await ana.goto(`${BASE}/conversations`);
                const write = await ana.evaluate(async (id) => {
                    const token = decodeURIComponent(
                        document.cookie
                            .split('; ')
                            .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
                            ?.split('=')[1] ?? '',
                    );
                    const response = await fetch(`/categories/${id}/rules`, {
                        method: 'PUT',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-XSRF-TOKEN': token,
                        },
                        body: JSON.stringify({
                            rules: { sla: { first_response_minutes: 1 } },
                        }),
                    });

                    return response.status;
                }, categoryId);
                const stored = state().categories[0].service_rules ?? {};

                return [
                    menu === 0 &&
                        Object.values(statuses).every(
                            (status) => status === 403,
                        ) &&
                        write === 403 &&
                        stored[FIRST_RESPONSE] === 20,
                    `menu links=${menu} pages=${JSON.stringify(statuses)} direct PUT=${write} stored=${JSON.stringify(stored)}`,
                ];
            }),
        );

        await layoutSweep(browser, {
            id: 'categorias',
            storageState: await admin.context().storageState(),
            states: [
                {
                    name: 'lista',
                    tables: true,
                    prepare: async (page) => {
                        await openCategories(page);
                        await page
                            .getByText(RENAMED)
                            .first()
                            .waitFor({ timeout: 10_000 });
                    },
                },
                {
                    name: 'dialogo-nueva',
                    prepare: async (page) => {
                        await page
                            .locator('[data-tour="categories.new"]')
                            .click();
                        await dialog(page).waitFor();
                        await page.waitForTimeout(250);
                    },
                    cleanup: async (page) => {
                        await page.keyboard.press('Escape');
                        await dialog(page).waitFor({ state: 'detached' });
                    },
                },
                {
                    name: 'dialogo-reglas',
                    prepare: async (page) => {
                        await categoryAction(
                            page,
                            RENAMED,
                            'Reglas de servicio',
                        );
                        await dialog(page).waitFor();
                        await page.waitForTimeout(250);
                    },
                    cleanup: async (page) => {
                        await page.keyboard.press('Escape');
                        await dialog(page).waitFor({ state: 'detached' });
                    },
                },
                {
                    name: 'configuracion',
                    prepare: async (page) => {
                        await page.goto(`${BASE}/admin/settings`);
                        await page.waitForLoadState('networkidle');
                        await page
                            .locator('[data-settings-service-rules]')
                            .scrollIntoViewIfNeeded();
                    },
                },
                {
                    name: 'departamento',
                    tables: true,
                    prepare: async (page) => {
                        await page.goto(`${BASE}/departments/${salesId}`);
                        await page.waitForLoadState('networkidle');
                        await page
                            .locator('[data-department-service-rules]')
                            .scrollIntoViewIfNeeded();
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
