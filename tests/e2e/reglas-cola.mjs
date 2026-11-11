/*
 * Queue rules in the inbox: «Solicitud completa», minimum wait and order of
 * arrival, each one switched on by the administrator in Configuración and
 * seen by an agent (E2E Ana) and a supervisor (E2E Sofía, who may confirm
 * requests and justify exceptions). Customers write through the simulated
 * webhook; tests/e2e/reglas-cola-seed.php prepares the people and
 * categories and moves the queue clock.
 *
 *   rules-off                   with every rule off both requests can be
 *                               accepted and nothing explains a block
 *   preview-fields              the preview (right click) shows the initial
 *                               message apart, when it was received, the
 *                               department, «Sin categoría», the status
 *                               badge, the files summary and the availability
 *   preview-keeps-unread        previewing sends no read request and the row
 *                               keeps its unread counter, also after a reload
 *   order-on                    the administrator turns «Atender en orden de
 *                               llegada» on in Configuración
 *   order-blocks-later          the later request has «Aceptar» disabled,
 *                               described by the visible reason
 *                               (data-acceptance-code="queue_order"); the
 *                               agent has no «Hacer una excepción»
 *   order-refused-by-server     accepting it anyway is refused (409,
 *                               queue_order) and nothing is assigned
 *   order-go-to-head            «Ir a la solicitud que puedes tomar» opens
 *                               the first request of the queue
 *   preview-blocked             the preview of the later request says why it
 *                               is not available
 *   override-dialog-keyboard    the exception dialog traps the focus, Escape
 *                               closes it, the focus returns to its button
 *   override-reason-required    a short reason is refused with its message
 *   override-accepts            with a reason the supervisor takes the later
 *                               request; the event and the audit entry keep
 *                               the rule skipped and the reason
 *   override-in-audit           the administrator finds «Excepción a las
 *                               reglas de la cola» in Auditoría, with the
 *                               reason in its detail
 *   min-wait-on                 the administrator sets a minimum wait of one
 *                               minute (and turns the order off)
 *   min-wait-countdown          a new request shows «Disponible para atención
 *                               en mm:ss», counting down by itself
 *   min-wait-enables            at zero «Aceptar» enables without reloading
 *                               the page, and the agent accepts it
 *   preview-keeps-unread-counter  the conversation the agent attends gets
 *                               two more messages: previewing it keeps its
 *                               «N mensajes sin leer», also after a reload
 *   confirmation-on             the administrator turns «Exigir confirmación
 *                               de Solicitud completa» on
 *   needs-info-blocks           a new request is born «Necesita información»:
 *                               badge, reason, «Aceptar» disabled; the agent
 *                               cannot confirm it
 *   readiness-dialog-keyboard   the confirmation dialog: focus, Escape
 *   confirm-complete            the supervisor confirms it: «Lista para
 *                               atención», and its minimum wait starts then
 *   revert-needs-reason         «Marcar que necesita información» requires
 *                               saying what is missing
 *   revert-needs-info           with the reason it goes back, and the chat
 *                               history shows the reason
 *   category-assign             the supervisor classifies a request from the
 *                               details panel; the row and the preview show
 *                               the category
 *   category-filter             the «Categoría de la solicitud» filter leaves
 *                               only that request and lives in the URL
 *   status-not-color-alone      readiness, block reason and preview status
 *                               carry text and an icon
 *   layout-bandeja-<w>-<s>      inbox, exception dialog, confirmation dialog
 *                               and preview from 320 to 1440 px, light and
 *                               dark: no horizontal overflow
 *   no-page-errors              no console errors, uncaught exceptions,
 *                               unhandled rejections or 5xx
 *
 * SIMULACIÓN: nothing is sent to Meta; the queue clock is moved by the seed.
 *
 *   E2E_PORT=8174 WHATSAPP_TRANSPORT=simulated TEST_DB_LOCK_WAIT=7200 \
 *     php tests/e2e/run.php reglas-cola.mjs
 */
import { join } from 'node:path';
import {
    BASE,
    HERE,
    USERS,
    applyInboxFilters,
    clean,
    dialogKeyboard,
    domGarbage,
    exitWithSummary,
    inbound,
    launchBrowser,
    layoutSweep,
    listRow,
    login,
    notColorAlone,
    openConversation,
    openPage,
    php,
    printProblems,
    recordPageProblems,
    savePlatformRules,
    shot,
    step,
    waitFor,
} from './lib.mjs';

const SEED = join(HERE, 'reglas-cola-seed.php');
const seed = (...args) => {
    try {
        return JSON.parse(php(SEED, ...args));
    } catch (error) {
        console.error(String(error.stderr ?? error.stdout ?? error));
        process.exit(1);
    }
};
const data = seed('prepare');
const SOFIA = 'e2e-sofia@example.test';
const REASON = 'Cliente prioritario: lo pidió la gerencia comercial.';
const MISSING = 'Falta el número de pedido.';

/* Three requests while every rule is off: they are born ready, in this order. */
const first = inbound(
    '5215511180001',
    'Cliente Primero',
    'Hola, soy el primero',
)[0].conversation;
const second = inbound(
    '5215511180002',
    'Cliente Segundo',
    'Hola, llegué después',
)[0].conversation;
const burst = seed(
    'burst',
    '5215511180009',
    'Cliente Ráfaga',
    '22',
).conversation;

const state = (id) => seed('state', String(id));

/** The whole row of a conversation: the item, «Aceptar» and the reason. */
const rowBox = (page, name) =>
    listRow(page, name).locator(
        'xpath=ancestor::div[contains(concat(" ", @class, " "), " group/row ")][1]',
    );
const acceptButton = (page, name) =>
    rowBox(page, name).locator('[data-inbox-accept]');
const notice = (page, name) =>
    rowBox(page, name).locator('[data-acceptance-code]');
const dialog = (page) => page.locator('[role="dialog"]').last();
const preview = (page) => page.locator('[data-chat-preview]');

async function openInbox(page) {
    await page.goto(`${BASE}/conversations`);
    await page.waitForLoadState('networkidle');
}

async function openPreview(page, name) {
    await listRow(page, name).click({ button: 'right' });
    await preview(page).waitFor({ timeout: 10_000 });
    await preview(page)
        .locator('[data-preview-summary]')
        .waitFor({ timeout: 10_000 });
}

async function closePreview(page) {
    await page.keyboard.press('Escape');
    await preview(page).waitFor({ state: 'detached', timeout: 5_000 });
}

/** Read requests sent by the page (a preview must send none). */
function trackReads(page) {
    const reads = [];
    page.on('request', (request) => {
        if (
            request.method() === 'POST' &&
            new URL(request.url()).pathname.endsWith('/read')
        ) {
            reads.push(request.url());
        }
    });

    return reads;
}

/** POST /conversations/{id}/accept as the page's user, asking for JSON. */
function acceptDirectly(page, id) {
    return page.evaluate(async (conversation) => {
        const token = decodeURIComponent(
            document.cookie
                .split('; ')
                .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
                ?.split('=')[1] ?? '',
        );
        const response = await fetch(`/conversations/${conversation}/accept`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': token,
            },
            body: '{}',
        });

        return {
            status: response.status,
            body: await response.json().catch(() => null),
        };
    }, id);
}

const run = async () => {
    const browser = await launchBrowser();

    try {
        const admin = await openPage(browser, {
            trackToasts: true,
            prefix: '[admin] ',
        });
        await login(admin, USERS.admin);
        const ana = await openPage(browser, { prefix: '[ana] ' });
        const reads = trackReads(ana);
        await login(ana, USERS.ana);
        const sofia = await openPage(browser, {
            trackToasts: true,
            prefix: '[sofia] ',
        });
        await login(sofia, SOFIA);

        await step(
            'rules-off',
            'every rule off',
            clean(async () => {
                await openInbox(ana);
                await listRow(ana, 'Cliente Segundo').waitFor();
                const enabled = [];

                for (const name of ['Cliente Primero', 'Cliente Segundo']) {
                    enabled.push(await acceptButton(ana, name).isEnabled());
                }

                const notices = await ana
                    .locator('[data-acceptance-code]')
                    .count();
                const garbage = await domGarbage(ana);
                await shot(ana, 'cola-sin-reglas');

                return [
                    enabled.every(Boolean) &&
                        notices === 0 &&
                        garbage.length === 0,
                    `accept enabled=${JSON.stringify(enabled)} notices=${notices} garbage=${JSON.stringify(garbage)}`,
                ];
            }),
        );

        let labelBefore = '';

        await step(
            'preview-fields',
            'right click on «Cliente Ráfaga» (22 messages)',
            clean(async () => {
                labelBefore =
                    (await listRow(ana, 'Cliente Ráfaga').getAttribute(
                        'aria-label',
                    )) ?? '';
                await openPreview(ana, 'Cliente Ráfaga');
                const summary = preview(ana).locator('[data-preview-summary]');
                const text = (await summary.innerText()).replace(/\s+/g, ' ');
                const stage = await summary
                    .locator('[data-preview-stage]')
                    .innerText();
                const received = await summary
                    .locator('time')
                    .first()
                    .getAttribute('datetime');
                const initial = await summary
                    .locator('[data-preview-first-message]')
                    .innerText()
                    .catch(() => '');
                const available = await summary
                    .locator('[data-preview-available]')
                    .getAttribute('data-preview-available');
                const garbage = await domGarbage(ana);
                await shot(ana, 'cola-vista-previa');
                const chatOpened = new URL(ana.url()).searchParams.has('c');

                return [
                    stage.trim() !== '' &&
                        /Recibida/.test(text) &&
                        !Number.isNaN(Date.parse(received ?? '')) &&
                        /E2E Ventas/.test(text) &&
                        /Categoría Sin categoría/.test(text) &&
                        /Archivos Sin archivos adjuntos/.test(text) &&
                        available === 'yes' &&
                        /Disponible para atención/.test(text) &&
                        /Mensaje inicial/.test(initial) &&
                        /pedido 4521/.test(initial) &&
                        garbage.length === 0 &&
                        !chatOpened,
                    `stage="${stage.trim()}" received=${received} available=${available} initial="${initial.replace(/\s+/g, ' ').slice(0, 90)}" summary="${text.slice(0, 200)}" garbage=${JSON.stringify(garbage)} chat opened=${chatOpened}`,
                ];
            }),
        );

        await step(
            'preview-keeps-unread',
            'close, compare, reload',
            clean(async () => {
                await closePreview(ana);
                const after =
                    (await listRow(ana, 'Cliente Ráfaga').getAttribute(
                        'aria-label',
                    )) ?? '';
                await openInbox(ana);
                await listRow(ana, 'Cliente Ráfaga').waitFor();
                const reloaded =
                    (await listRow(ana, 'Cliente Ráfaga').getAttribute(
                        'aria-label',
                    )) ?? '';

                return [
                    labelBefore !== '' &&
                        after === labelBefore &&
                        reloaded === labelBefore &&
                        reads.length === 0,
                    `before="${labelBefore}" after same=${after === labelBefore} reloaded same=${reloaded === labelBefore} read requests=${reads.length}`,
                ];
            }),
        );

        await step(
            'order-on',
            'Configuración › Atender en orden de llegada',
            clean(async () => {
                const saved = await savePlatformRules(admin, {
                    'queue.chronological_order': true,
                });

                return [
                    saved.status !== null && saved.status < 400 && saved.clean,
                    JSON.stringify(saved),
                ];
            }),
        );

        await step(
            'order-blocks-later',
            'E2E Ana, «Cliente Segundo»',
            clean(async () => {
                await openInbox(ana);
                await listRow(ana, 'Cliente Segundo').waitFor();
                const button = acceptButton(ana, 'Cliente Segundo');
                const code = await notice(ana, 'Cliente Segundo').getAttribute(
                    'data-acceptance-code',
                );
                const text = (
                    await notice(ana, 'Cliente Segundo').innerText()
                ).replace(/\s+/g, ' ');
                const describedBy =
                    await button.getAttribute('aria-describedby');
                const noticeId = await notice(
                    ana,
                    'Cliente Segundo',
                ).getAttribute('id');
                const label = await button.getAttribute('aria-label');
                const override = await rowBox(ana, 'Cliente Segundo')
                    .locator('[data-acceptance-override]')
                    .count();
                const headEnabled = await acceptButton(
                    ana,
                    'Cliente Primero',
                ).isEnabled();
                await shot(ana, 'cola-orden-bloqueada');

                return [
                    (await button.isDisabled()) &&
                        code === 'queue_order' &&
                        /Existe una solicitud anterior que debe atenderse primero/.test(
                            text,
                        ) &&
                        describedBy !== null &&
                        describedBy === noticeId &&
                        /no disponible/.test(label ?? '') &&
                        override === 0 &&
                        headEnabled,
                    `code=${code} text="${text}" described by the reason=${describedBy === noticeId} label="${label}" override buttons=${override} head enabled=${headEnabled}`,
                ];
            }),
        );

        await step(
            'order-refused-by-server',
            `POST /conversations/${second}/accept`,
            async () => {
                const answer = await acceptDirectly(ana, second);
                const stored = state(second);

                return [
                    answer.status === 409 &&
                        answer.body?.code === 'queue_order' &&
                        answer.body?.blocking_conversation_id === first &&
                        stored.assigned_user_id === null &&
                        stored.status === 'pending',
                    `status=${answer.status} body=${JSON.stringify(answer.body)} stored=${stored.status}/${stored.assigned_user_id}`,
                ];
            },
        );

        await step(
            'order-go-to-head',
            '«Ir a la solicitud que puedes tomar»',
            clean(async () => {
                await rowBox(ana, 'Cliente Segundo')
                    .locator('[data-acceptance-go-to-head]')
                    .click();
                await ana.waitForURL(
                    (url) => url.searchParams.get('c') === String(first),
                    { timeout: 10_000 },
                );
                const chat = await ana
                    .locator('[data-chat-pane]')
                    .getAttribute('aria-label');

                return [
                    /Cliente Primero/.test(chat ?? ''),
                    `url c=${new URL(ana.url()).searchParams.get('c')} chat="${chat}"`,
                ];
            }),
        );

        await step(
            'preview-blocked',
            'preview of «Cliente Segundo»',
            clean(async () => {
                await openInbox(ana);
                await listRow(ana, 'Cliente Segundo').waitFor();
                await openPreview(ana, 'Cliente Segundo');
                const availability = preview(ana).locator(
                    '[data-preview-available]',
                );
                const value = await availability.getAttribute(
                    'data-preview-available',
                );
                const text = (await availability.innerText()).trim();
                await shot(ana, 'cola-vista-previa-bloqueada');
                await closePreview(ana);

                return [
                    value === 'no' && /solicitud anterior/.test(text),
                    `available=${value} text="${text}"`,
                ];
            }),
        );

        const overrideButton = () =>
            rowBox(sofia, 'Cliente Segundo').locator(
                '[data-acceptance-override]',
            );

        await step(
            'override-dialog-keyboard',
            'E2E Sofía, «Hacer una excepción»',
            clean(async () => {
                await openInbox(sofia);
                await listRow(sofia, 'Cliente Segundo').waitFor();

                return dialogKeyboard(sofia, {
                    dialog: '[data-queue-override-dialog]',
                    open: async () => {
                        await overrideButton().focus();
                        await sofia.keyboard.press('Enter');
                    },
                    returnsTo: overrideButton(),
                });
            }),
        );

        await step(
            'override-reason-required',
            'reason «corto»',
            clean(async () => {
                await overrideButton().click();
                const box = sofia.locator('[data-queue-override-dialog]');
                await box.waitFor();
                const rule = (await box.innerText()).replace(/\s+/g, ' ');
                await sofia.locator('#queue-override-reason').fill('corto');
                await box
                    .getByRole('button', { name: 'Aceptar con excepción' })
                    .click();
                const message = await box
                    .getByText(
                        'Explica el motivo de la excepción con al menos 10 caracteres.',
                    )
                    .isVisible();
                const invalid = await sofia
                    .locator('#queue-override-reason')
                    .getAttribute('aria-invalid');
                const stored = state(second);
                await shot(sofia, 'cola-excepcion-dialogo');

                return [
                    /Regla que se omite: Orden de llegada/.test(rule) &&
                        message &&
                        invalid === 'true' &&
                        stored.assigned_user_id === null,
                    `names the rule=${/Regla que se omite: Orden de llegada/.test(rule)} message=${message} aria-invalid=${invalid} assigned=${stored.assigned_user_id}`,
                ];
            }),
        );

        await step(
            'override-accepts',
            REASON,
            clean(async () => {
                await sofia.locator('#queue-override-reason').fill(REASON);
                await sofia
                    .locator('[data-queue-override-dialog]')
                    .getByRole('button', { name: 'Aceptar con excepción' })
                    .click();
                await sofia
                    .locator('[data-queue-override-dialog]')
                    .waitFor({ state: 'detached', timeout: 10_000 });
                await sofia.waitForLoadState('networkidle');
                const stored = state(second);
                const event = stored.events.find(
                    (item) => item.metadata?.queue_override,
                );
                const audit = stored.audit.find(
                    (item) =>
                        item.action === 'conversation.queue_rules_overridden',
                );
                const head = state(first);

                return [
                    stored.assigned_user_id === data.sofia &&
                        stored.status === 'open' &&
                        event?.metadata.queue_override.reason === REASON &&
                        JSON.stringify(
                            event?.metadata.queue_override.rules,
                        ).includes('queue_order') &&
                        audit?.properties?.reason === REASON &&
                        head.status === 'pending',
                    `assigned=${stored.assigned_user_id} (sofia=${data.sofia}) status=${stored.status} event=${JSON.stringify(event?.metadata?.queue_override)} audit=${JSON.stringify(audit?.properties)} head still ${head.status}`,
                ];
            }),
        );

        await step(
            'override-in-audit',
            '/admin/audit',
            clean(async () => {
                await admin.goto(`${BASE}/admin/audit`);
                await admin.waitForLoadState('networkidle');
                const row = admin
                    .getByRole('button', {
                        name: 'Ver detalle: Excepción a las reglas de la cola',
                    })
                    .first();
                await row.waitFor({ timeout: 10_000 });
                const text = (await row.innerText()).replace(/\s+/g, ' ');
                await row.click();
                const reason = await admin
                    .getByText(REASON)
                    .first()
                    .waitFor({ timeout: 8_000 })
                    .then(() => true)
                    .catch(() => false);
                const garbage = await domGarbage(admin);
                await shot(admin, 'cola-excepcion-auditoria');
                await admin.keyboard.press('Escape');

                return [
                    /E2E Sofía/.test(text) && reason && garbage.length === 0,
                    `row="${text}" reason in the detail=${reason} garbage=${JSON.stringify(garbage)}`,
                ];
            }),
        );

        await step(
            'min-wait-on',
            'Configuración › minimum wait 1 min, order off',
            clean(async () => {
                const saved = await savePlatformRules(admin, {
                    'queue.chronological_order': false,
                    'queue.min_wait_minutes': 1,
                });

                return [
                    saved.status !== null && saved.status < 400 && saved.clean,
                    JSON.stringify(saved),
                ];
            }),
        );

        const waiting = inbound(
            '5215511180003',
            'Cliente Espera',
            'Hola, acabo de escribir',
        )[0].conversation;

        await step(
            'min-wait-countdown',
            'new request, 12 s left (queue clock moved by the seed)',
            clean(async () => {
                seed('queue-age', String(waiting), '48');
                await openInbox(ana);
                await listRow(ana, 'Cliente Espera').waitFor();
                await ana.evaluate(() => (window.__sameDocument = true));
                const countdown = notice(ana, 'Cliente Espera').locator(
                    '[data-queue-countdown]',
                );
                const code = await notice(ana, 'Cliente Espera').getAttribute(
                    'data-acceptance-code',
                );
                const text = (
                    await notice(ana, 'Cliente Espera').innerText()
                ).replace(/\s+/g, ' ');
                const seconds = (value) =>
                    Number(value.slice(0, 2)) * 60 + Number(value.slice(3));
                const samples = [];
                const started = Date.now();

                // One reading every 250 ms for four seconds.
                while (Date.now() - started < 4_000) {
                    samples.push((await countdown.innerText()).trim());
                    await ana.waitForTimeout(250);
                }

                const values = samples.map(seconds);
                const steps = values
                    .slice(1)
                    .map((value, index) => values[index] - value);
                const distinct = [...new Set(samples)];
                const disabled = await acceptButton(
                    ana,
                    'Cliente Espera',
                ).isDisabled();
                await shot(ana, 'cola-espera-minima');

                return [
                    code === 'min_wait' &&
                        /^Disponible para atención en \d\d:\d\d$/.test(text) &&
                        samples.every((sample) => /^\d\d:\d\d$/.test(sample)) &&
                        values[0] <= 12 &&
                        steps.every((change) => change === 0 || change === 1) &&
                        values[0] - values[values.length - 1] >= 3 &&
                        values[0] - values[values.length - 1] <= 5 &&
                        disabled,
                    `code=${code} text="${text}" countdown over 4 s: ${distinct.join(' ')} (never back, one second at a time), accept disabled=${disabled}`,
                ];
            }),
        );

        await step(
            'min-wait-enables',
            'zero without reloading, then «Aceptar»',
            clean(async () => {
                const enabled = await waitFor(
                    () => acceptButton(ana, 'Cliente Espera').isEnabled(),
                    25_000,
                );
                const sameDocument = await ana.evaluate(
                    () => window.__sameDocument === true,
                );
                const notices = await notice(ana, 'Cliente Espera').count();
                await ana.waitForTimeout(1_200);
                await acceptButton(ana, 'Cliente Espera').click();
                await ana.waitForURL(
                    (url) => url.searchParams.get('c') === String(waiting),
                    { timeout: 10_000 },
                );
                const stored = state(waiting);

                return [
                    enabled &&
                        sameDocument &&
                        notices === 0 &&
                        stored.assigned_user_id === data.ana &&
                        stored.status === 'open',
                    `enabled by itself=${enabled} same document=${sameDocument} reason gone=${notices === 0} stored=${stored.status}/${stored.assigned_user_id} (ana=${data.ana})`,
                ];
            }),
        );

        await step(
            'preview-keeps-unread-counter',
            'two new messages in the conversation E2E Ana attends',
            clean(async () => {
                await openInbox(ana);
                inbound('5215511180003', 'Cliente Espera', 'Sigo aquí');
                inbound('5215511180003', 'Cliente Espera', '¿Me ayudan?');
                await openInbox(ana);
                await listRow(ana, 'Cliente Espera').waitFor();
                const sent = reads.length;
                const before =
                    (await listRow(ana, 'Cliente Espera').getAttribute(
                        'aria-label',
                    )) ?? '';
                await openPreview(ana, 'Cliente Espera');
                const shown = await preview(ana)
                    .getByText('¿Me ayudan?')
                    .first()
                    .isVisible();
                const assignee = (
                    await preview(ana)
                        .locator('[data-preview-summary]')
                        .innerText()
                ).replace(/\s+/g, ' ');
                await closePreview(ana);
                const after =
                    (await listRow(ana, 'Cliente Espera').getAttribute(
                        'aria-label',
                    )) ?? '';
                await openInbox(ana);
                await listRow(ana, 'Cliente Espera').waitFor();
                const reloaded =
                    (await listRow(ana, 'Cliente Espera').getAttribute(
                        'aria-label',
                    )) ?? '';

                return [
                    Number(before.match(/(\d+) mensajes sin leer/)?.[1] ?? 0) >=
                        2 &&
                        shown &&
                        /Responsable E2E Ana/.test(assignee) &&
                        after === before &&
                        reloaded === before &&
                        reads.length === sent,
                    `before="${before}" preview shows the new message=${shown} responsible shown=${/Responsable E2E Ana/.test(assignee)} after same=${after === before} reloaded same=${reloaded === before} read requests during the preview=${reads.length - sent}`,
                ];
            }),
        );

        await step(
            'confirmation-on',
            'Configuración › Exigir confirmación de «Solicitud completa»',
            clean(async () => {
                const saved = await savePlatformRules(admin, {
                    'intake.confirmation_required': true,
                });

                return [
                    saved.status !== null && saved.status < 400 && saved.clean,
                    JSON.stringify(saved),
                ];
            }),
        );

        const incomplete = inbound(
            '5215511180004',
            'Cliente Incompleto',
            'Hola, quiero cambiar mi pedido',
        )[0].conversation;

        await step(
            'needs-info-blocks',
            'E2E Ana, «Cliente Incompleto»',
            clean(async () => {
                await openInbox(ana);
                await listRow(ana, 'Cliente Incompleto').waitFor();
                const box = rowBox(ana, 'Cliente Incompleto');
                const readiness = await box.getAttribute('data-readiness');
                const badge = await box
                    .locator('span[data-readiness]')
                    .innerText();
                const code = await notice(
                    ana,
                    'Cliente Incompleto',
                ).getAttribute('data-acceptance-code');
                const text = (
                    await notice(ana, 'Cliente Incompleto').innerText()
                ).replace(/\s+/g, ' ');
                const confirm = await box
                    .locator('[data-readiness-confirm]')
                    .count();
                const disabled = await acceptButton(
                    ana,
                    'Cliente Incompleto',
                ).isDisabled();
                const refused = await acceptDirectly(ana, incomplete);
                const older = await rowBox(ana, 'Cliente Primero').getAttribute(
                    'data-readiness',
                );
                await shot(ana, 'cola-necesita-informacion');

                return [
                    readiness === 'needs_info' &&
                        badge.trim() === 'Necesita información' &&
                        code === 'needs_info' &&
                        /todavía necesita información/.test(text) &&
                        confirm === 0 &&
                        disabled &&
                        refused.status === 409 &&
                        refused.body?.code === 'needs_info' &&
                        older === 'ready',
                    `readiness=${readiness} badge="${badge.trim()}" code=${code} text="${text}" confirm buttons=${confirm} disabled=${disabled} direct accept=${refused.status}/${refused.body?.code} request from before the rule=${older}`,
                ];
            }),
        );

        const confirmButton = () =>
            notice(sofia, 'Cliente Incompleto').locator(
                '[data-readiness-confirm]',
            );

        await step(
            'readiness-dialog-keyboard',
            'E2E Sofía, «Confirmar solicitud completa»',
            clean(async () => {
                await openInbox(sofia);
                await listRow(sofia, 'Cliente Incompleto').waitFor();

                return dialogKeyboard(sofia, {
                    open: async () => {
                        await confirmButton().focus();
                        await sofia.keyboard.press('Enter');
                    },
                    returnsTo: confirmButton(),
                });
            }),
        );

        await step(
            'confirm-complete',
            '«Confirmar como completa»',
            clean(async () => {
                await confirmButton().click();
                await dialog(sofia).waitFor();
                const title = await dialog(sofia)
                    .getByRole('heading')
                    .innerText();
                await shot(sofia, 'cola-confirmar-dialogo');
                await dialog(sofia)
                    .getByRole('button', { name: 'Confirmar como completa' })
                    .click();
                await dialog(sofia).waitFor({ state: 'detached' });
                await sofia.waitForLoadState('networkidle');
                const box = rowBox(sofia, 'Cliente Incompleto');
                await sofia
                    .locator('div[data-readiness="ready"] [data-inbox-item]', {
                        hasText: 'Cliente Incompleto',
                    })
                    .waitFor({ timeout: 10_000 });
                const code = await notice(
                    sofia,
                    'Cliente Incompleto',
                ).getAttribute('data-acceptance-code');
                const countdown = (
                    await box.locator('[data-queue-countdown]').innerText()
                ).trim();
                const stored = state(incomplete);
                const audited = stored.audit.some(
                    (item) =>
                        item.action === 'conversation.readiness_confirmed',
                );
                await shot(sofia, 'cola-confirmada');

                return [
                    title === 'Confirmar solicitud completa' &&
                        code === 'min_wait' &&
                        /^0[01]:\d\d$/.test(countdown) &&
                        stored.readiness === 'ready' &&
                        stored.queued_at !== null &&
                        audited,
                    `title="${title}" then code=${code} countdown=${countdown} stored readiness=${stored.readiness} queued_at=${stored.queued_at} audited=${audited}`,
                ];
            }),
        );

        const openRevert = async () => {
            await rowBox(sofia, 'Cliente Incompleto')
                .locator('[data-inbox-row-menu]')
                .click();
            await sofia.locator('[data-readiness-revert]').click();
            await dialog(sofia).waitFor();
        };

        await step(
            'revert-needs-reason',
            '«Marcar que necesita información» without a reason',
            clean(async () => {
                await openRevert();
                const submit = dialog(sofia).getByRole('button', {
                    name: 'Marcar que necesita información',
                });
                await submit.click();
                await sofia.waitForTimeout(400);
                const invalid = await sofia
                    .locator('#conversation-reason')
                    .getAttribute('aria-invalid');
                const error = await dialog(sofia)
                    .locator('#conversation-reason-error')
                    .innerText()
                    .catch(() => '');
                const open = await dialog(sofia).isVisible();
                const stored = state(incomplete).readiness;

                return [
                    invalid === 'true' &&
                        error.trim() !== '' &&
                        open &&
                        stored === 'ready',
                    `aria-invalid=${invalid} error="${error.trim()}" dialog open=${open} stored=${stored}`,
                ];
            }),
        );

        await step(
            'revert-needs-info',
            MISSING,
            clean(async () => {
                await sofia.locator('#conversation-reason').fill(MISSING);
                await dialog(sofia)
                    .getByRole('button', {
                        name: 'Marcar que necesita información',
                    })
                    .click();
                await dialog(sofia).waitFor({ state: 'detached' });
                await sofia
                    .locator(
                        'div[data-readiness="needs_info"] [data-inbox-item]',
                        { hasText: 'Cliente Incompleto' },
                    )
                    .waitFor({ timeout: 10_000 });
                const stored = state(incomplete);
                await openConversation(sofia, incomplete);
                const inHistory = await sofia
                    .locator('[data-chat-pane]')
                    .getByText(MISSING)
                    .first()
                    .waitFor({ timeout: 8_000 })
                    .then(() => true)
                    .catch(() => false);
                const header = await sofia
                    .locator('[data-chat-pane] span[data-readiness]')
                    .first()
                    .innerText()
                    .catch(() => '');
                await shot(sofia, 'cola-devuelta-historial');

                return [
                    stored.readiness === 'needs_info' &&
                        stored.audit.some(
                            (item) =>
                                item.action ===
                                    'conversation.readiness_reverted' &&
                                item.properties?.reason === MISSING,
                        ) &&
                        inHistory &&
                        header.trim() === 'Necesita información',
                    `stored=${stored.readiness} reason in the chat history=${inHistory} header badge="${header.trim()}"`,
                ];
            }),
        );

        await step(
            'category-assign',
            'details panel of «Cliente Primero» › E2E Garantías',
            clean(async () => {
                await openConversation(sofia, first);
                const select = sofia.locator('select#conversation-category');

                if (!(await select.isVisible().catch(() => false))) {
                    await sofia.locator('[data-tour="chat.details"]').click();
                }

                await select.waitFor({ timeout: 10_000 });
                const saved = sofia.waitForResponse(
                    (response) =>
                        response.request().method() === 'PUT' &&
                        new URL(response.url()).pathname ===
                            `/conversations/${first}/category`,
                );
                await select.selectOption(String(data.categories.garantias));
                const status = (await saved).status();
                // The page reads its props again after the redirect.
                await sofia
                    .locator('[data-chat-pane] header')
                    .getByText('E2E Garantías')
                    .first()
                    .waitFor({ timeout: 10_000 })
                    .catch(() => {});
                const row = (
                    await rowBox(sofia, 'Cliente Primero').innerText()
                ).replace(/\s+/g, ' ');
                const header = (
                    await sofia.locator('[data-chat-pane] header').innerText()
                ).replace(/\s+/g, ' ');
                await shot(sofia, 'cola-categoria-asignada');
                await openInbox(sofia);
                await listRow(sofia, 'Cliente Primero').waitFor();
                await openPreview(sofia, 'Cliente Primero');
                const previewText = (
                    await preview(sofia)
                        .locator('[data-preview-summary]')
                        .innerText()
                ).replace(/\s+/g, ' ');
                await closePreview(sofia);
                const stored = state(first);

                return [
                    status < 400 &&
                        stored.category_id === data.categories.garantias &&
                        /E2E Garantías/.test(row) &&
                        /E2E Garantías/.test(header) &&
                        /E2E Garantías/.test(previewText) &&
                        !/Sin categoría/.test(previewText),
                    `PUT=${status} stored category=${stored.category_id} row has badge=${/E2E Garantías/.test(row)} header=${/E2E Garantías/.test(header)} preview="${previewText.slice(0, 140)}"`,
                ];
            }),
        );

        await step(
            'category-filter',
            'Filtros › Categoría de la solicitud',
            clean(async () => {
                const total = await sofia.locator('[data-inbox-item]').count();
                await sofia
                    .locator('[data-inbox-filters]:visible')
                    .first()
                    .click();
                const select = sofia.locator('[data-inbox-filter-category]');
                await select.waitFor({ timeout: 10_000 });
                const options = await select.locator('option').allInnerTexts();
                await select.selectOption(String(data.categories.garantias));
                await applyInboxFilters(sofia);
                await waitFor(
                    async () =>
                        (await sofia.locator('[data-inbox-item]').count()) ===
                        1,
                    10_000,
                );
                const names = await sofia
                    .locator('[data-inbox-item]')
                    .evaluateAll((rows) =>
                        rows.map((row) => row.getAttribute('aria-label')),
                    );
                const inUrl = new URL(sofia.url()).searchParams.get('category');
                await shot(sofia, 'cola-filtro-categoria');
                await sofia.reload();
                await sofia.waitForLoadState('networkidle');
                const afterReload = await sofia
                    .locator('[data-inbox-item]')
                    .count();
                await sofia
                    .locator('[data-inbox-filters]:visible')
                    .first()
                    .click();
                await select.waitFor();
                await select.selectOption(String(data.categories.facturacion));
                await applyInboxFilters(sofia);
                const empty = await waitFor(
                    async () =>
                        (await sofia.locator('[data-inbox-item]').count()) ===
                        0,
                    10_000,
                );

                return [
                    total > 1 &&
                        options
                            .map((option) => option.trim())
                            .includes('E2E Garantías') &&
                        names.length === 1 &&
                        /Cliente Primero/.test(names[0] ?? '') &&
                        inUrl === String(data.categories.garantias) &&
                        afterReload === 1 &&
                        empty,
                    `before=${total} options=${JSON.stringify(options.map((option) => option.trim()))} filtered=${JSON.stringify(names).slice(0, 120)} url category=${inUrl} after reload=${afterReload} other category empty=${empty}`,
                ];
            }),
        );

        await step(
            'status-not-color-alone',
            'readiness, reason and preview status',
            clean(async () => {
                await openInbox(sofia);
                await listRow(sofia, 'Cliente Incompleto').waitFor();
                const readiness = await notColorAlone(
                    sofia,
                    'span[data-readiness]',
                );
                const reason = await notColorAlone(
                    sofia,
                    '[data-acceptance-code] > p',
                );
                await openPreview(sofia, 'Cliente Incompleto');
                const stage = await notColorAlone(
                    sofia,
                    '[data-preview-stage]',
                );
                const availability = await notColorAlone(
                    sofia,
                    '[data-preview-available]',
                );
                await closePreview(sofia);
                const all = [readiness, reason, stage, availability];

                return [
                    all.every(([ok]) => ok),
                    all.map(([, note]) => note).join(' | '),
                ];
            }),
        );

        const blocked = (page) =>
            listRow(page, 'Cliente Incompleto').locator(
                'xpath=ancestor::div[contains(concat(" ", @class, " "), " group/row ")][1]',
            );

        await layoutSweep(browser, {
            id: 'bandeja',
            storageState: await sofia.context().storageState(),
            states: [
                {
                    name: 'lista',
                    prepare: async (page) => {
                        await openInbox(page);
                        await listRow(page, 'Cliente Incompleto').waitFor();
                        await blocked(page).scrollIntoViewIfNeeded();
                    },
                },
                {
                    name: 'excepcion',
                    prepare: async (page) => {
                        await blocked(page)
                            .locator('[data-acceptance-override]')
                            .click();
                        await page
                            .locator('[data-queue-override-dialog]')
                            .waitFor();
                        await page.waitForTimeout(250);
                    },
                    cleanup: async (page) => {
                        await page.keyboard.press('Escape');
                        await page
                            .locator('[data-queue-override-dialog]')
                            .waitFor({ state: 'detached' });
                    },
                },
                {
                    name: 'confirmar',
                    prepare: async (page) => {
                        await blocked(page)
                            .locator(
                                '[data-acceptance-code] [data-readiness-confirm]',
                            )
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
                    name: 'vista-previa',
                    prepare: async (page) => {
                        await blocked(page)
                            .locator('[data-inbox-row-menu]')
                            .click();
                        await page.locator('[data-inbox-preview]').click();
                        await preview(page)
                            .locator('[data-preview-summary]')
                            .waitFor({ timeout: 10_000 });
                        await page.waitForTimeout(250);
                    },
                    cleanup: async (page) => {
                        await page.keyboard.press('Escape');
                        await preview(page).waitFor({ state: 'detached' });
                    },
                },
            ],
        });
    } finally {
        await browser.close();
    }

    console.log(
        `\nconversations: first=${first} second=${second} burst=${burst}`,
    );
    recordPageProblems(800);
    printProblems();
    exitWithSummary();
};

run().catch((error) => {
    console.error(error);
    process.exit(1);
});
