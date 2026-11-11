/*
 * Response objective (SLA) end to end: the administrator sets the objective
 * in Configuración, a customer writes through the simulated webhook, the
 * agent (E2E Ana) sees the traffic light move and receives the automatic
 * alerts, the supervisor (E2E Sofía) follows it in «Monitoreo de SLA», and
 * the agent's answer resolves it. People, clocks and the evaluator come
 * from tests/e2e/reglas-cola-seed.php (prepare, sla-age, sla-evaluate).
 *
 *   monitor-not-configured      without objectives «Monitoreo de SLA» says so
 *                               honestly, with zeros and no broken values
 *   objective-configured        first response in 10 minutes of calendar
 *                               time, saved in Configuración
 *   badge-on-time               the new request shows «En tiempo» in the
 *                               row, the chat header and the details panel
 *                               (objective, deadline, how it is counted)
 *   badge-header-accessible     the header badge is text inside the button
 *                               that opens the details (no nested button),
 *                               announces its state and the keyboard reaches
 *                               the full detail in the details panel
 *   badge-warning-live          a few seconds before the warning instant the
 *                               row is still «En tiempo» and turns «Por
 *                               vencer» by itself, without reloading
 *   alert-warning               `php artisan sla:evaluate` marks the warning
 *                               and the agent's bell gets it tagged «SLA
 *                               automático»; the supervisor is not warned yet
 *                               (default settings)
 *   badge-breached              past the deadline the evaluator records the
 *                               breach (event + audit) and the badge says
 *                               «Vencida» with the overdue time
 *   alert-breach                agent and supervisor bells: «SLA automático»,
 *                               «Requiere acción»
 *   monitor-row                 the tab «Monitoreo de SLA»: counters and the
 *                               live row (state, overdue time, responsible)
 *   monitor-filters             the state and agent filters change the table
 *                               and live in the URL; the filtered empty state
 *                               offers «Quitar filtros»
 *   monitor-history             the breach is in «Historial de
 *                               incumplimientos» as «Sigue sin respuesta»
 *   glossary                    the three concepts are explained apart and
 *                               the explanation opens with the keyboard
 *   status-not-color-alone      every SLA state carries text and an icon
 *   reply-resolves              the agent answers: the badge disappears, the
 *                               cycle ends as answered, the monitor row goes,
 *                               the history says who answered and the bell
 *                               entry reads as already answered
 *   layout-sla-<w>-<s>          monitor, inbox row, chat header and bell from
 *                               320 to 1440 px, light and dark
 *   no-page-errors              no console errors, uncaught exceptions,
 *                               unhandled rejections or 5xx
 *
 * SIMULACIÓN: nothing is sent to Meta (simulated transport); time passing is
 * simulated by moving the response cycle back with the seed.
 *
 *   E2E_PORT=8176 WHATSAPP_TRANSPORT=simulated QUEUE_CONNECTION=sync \
 *     TEST_DB_LOCK_WAIT=7200 php tests/e2e/run.php sla.mjs
 */
import { join } from 'node:path';
import {
    BASE,
    HERE,
    USERS,
    clean,
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
const CUSTOMER = 'Cliente SLA';
const REPLY = 'Hola, soy Ana. Ya reviso tu pedido.';
const state = (id) => seed('state', String(id));

const rowBadge = (page, name) =>
    listRow(page, name).locator('[data-sla-badge]');
const bellButton = (page) =>
    page.getByRole('button', { name: /^Notificaciones,/ }).first();
const stat = (page, label) =>
    page
        .locator('[data-sla-monitor] [data-stat-card]')
        .filter({
            has: page.locator('[data-stat-label]', {
                hasText: new RegExp(`^${label}$`),
            }),
        })
        .first();

async function statValue(page, label) {
    const text = (await stat(page, label).innerText()).replace(/\s+/g, ' ');

    return Number(text.replace(label, '').match(/\d+/)?.[0] ?? NaN);
}

async function openInbox(page) {
    await page.goto(`${BASE}/conversations`);
    await page.waitForLoadState('networkidle');
}

async function openMonitor(page, query = '') {
    await page.goto(`${BASE}/attention?tab=sla${query}`);
    await page.waitForLoadState('networkidle');
    await page
        .locator('[data-sla-monitor] [aria-busy="true"]')
        .first()
        .waitFor({ state: 'detached', timeout: 15_000 })
        .catch(() => {});
}

/** Texts of the bell entries tagged «SLA automático» (opens and closes the bell). */
async function slaNotifications(page) {
    await page.reload();
    await page.waitForLoadState('networkidle');
    await bellButton(page).click();
    await page
        .getByRole('heading', { name: 'Notificaciones', exact: true })
        .waitFor({ timeout: 10_000 });
    await page.waitForTimeout(800);
    const items = await page
        .locator('li', { has: page.locator('[data-notification-sla]') })
        .evaluateAll((entries) =>
            entries.map((entry) => entry.innerText.replace(/\s+/g, ' ').trim()),
        );
    await page.keyboard.press('Escape');

    return items;
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
        await login(ana, USERS.ana);
        const sofia = await openPage(browser, { prefix: '[sofia] ' });
        await login(sofia, SOFIA);

        await step(
            'monitor-not-configured',
            '/attention › Monitoreo de SLA, no objectives',
            clean(async () => {
                await sofia.goto(`${BASE}/attention`);
                await sofia.waitForLoadState('networkidle');
                await sofia.locator('[data-attention-tab="sla"]').click();
                await sofia.locator('[data-sla-monitor]').waitFor();
                await sofia
                    .waitForURL(
                        (url) => url.searchParams.get('tab') === 'sla',
                        { timeout: 10_000 },
                    )
                    .catch(() => {});
                const selected = await sofia
                    .locator('[data-attention-tab="sla"]')
                    .getAttribute('aria-selected');
                const inUrl = new URL(sofia.url()).searchParams.get('tab');
                const empty = await sofia
                    .getByText('No hay objetivos de respuesta configurados')
                    .first()
                    .waitFor({ timeout: 15_000 })
                    .then(() => true)
                    .catch(() => false);
                const history = await sofia
                    .getByText('Sin incumplimientos en este periodo')
                    .isVisible()
                    .catch(() => false);
                const values = [];

                for (const label of [
                    'En tiempo',
                    'Por vencer',
                    'Vencidas',
                    'Sin objetivo configurado',
                ]) {
                    values.push(await statValue(sofia, label));
                }

                const garbage = await domGarbage(sofia);
                await shot(sofia, 'sla-monitoreo-sin-objetivos');

                return [
                    selected === 'true' &&
                        inUrl === 'sla' &&
                        empty &&
                        history &&
                        values.every((value) => value === 0) &&
                        garbage.length === 0,
                    `tab selected=${selected} url tab=${inUrl} empty state=${empty} history empty=${history} counters=${JSON.stringify(values)} garbage=${JSON.stringify(garbage)}`,
                ];
            }),
        );

        await step(
            'objective-configured',
            'Configuración › first response 10 min, calendar time',
            clean(async () => {
                const saved = await savePlatformRules(admin, {
                    'sla.first_response_minutes': 10,
                    'sla.business_hours_only': false,
                });

                return [
                    saved.status !== null && saved.status < 400 && saved.clean,
                    JSON.stringify(saved),
                ];
            }),
        );

        const conversation = inbound(
            '5215511190001',
            CUSTOMER,
            'Hola, ¿dónde está mi pedido?',
        )[0].conversation;

        await step(
            'badge-on-time',
            'E2E Ana accepts the new request',
            clean(async () => {
                await openInbox(ana);
                await listRow(ana, CUSTOMER).waitFor();
                const queued = await rowBadge(ana, CUSTOMER).getAttribute(
                    'data-sla-state',
                );
                await listRow(ana, CUSTOMER)
                    .locator(
                        'xpath=ancestor::div[contains(concat(" ", @class, " "), " group/row ")][1]',
                    )
                    .locator('[data-inbox-accept]')
                    .click();
                await ana.waitForURL(
                    (url) => url.searchParams.get('c') === String(conversation),
                    { timeout: 10_000 },
                );
                await ana.waitForLoadState('networkidle');
                const row = (await rowBadge(ana, CUSTOMER).innerText()).replace(
                    /\s+/g,
                    ' ',
                );
                const header = ana.locator(
                    '[data-chat-pane] header [data-sla-badge]',
                );
                const headerLabel = await header.getAttribute('title');

                if (
                    !(await ana
                        .locator('[data-sla-details]')
                        .isVisible()
                        .catch(() => false))
                ) {
                    await ana.locator('[data-tour="chat.details"]').click();
                }

                const details = (
                    await ana.locator('[data-sla-details]').innerText()
                ).replace(/\s+/g, ' ');
                const stored = state(conversation);
                const garbage = await domGarbage(ana);
                await shot(ana, 'sla-en-tiempo');

                return [
                    queued === 'on_time' &&
                        /En tiempo/.test(row) &&
                        /^SLA: En tiempo\. Vence en \d+ min\. Primera respuesta en 10 min\./.test(
                            headerLabel ?? '',
                        ) &&
                        /En tiempo/.test(details) &&
                        /Objetivo Primera respuesta en 10 min/.test(details) &&
                        /Fecha límite/.test(details) &&
                        /Cuenta el tiempo corrido \(24 horas\)/.test(details) &&
                        stored.cycle?.target_seconds === 600 &&
                        stored.cycle?.active === 1 &&
                        garbage.length === 0,
                    `in the queue=${queued} row="${row}" header="${headerLabel}" details="${details.slice(0, 220)}" cycle=${JSON.stringify(stored.cycle)} garbage=${JSON.stringify(garbage)}`,
                ];
            }),
        );

        await step(
            'badge-header-accessible',
            'the header badge inside the «details» button',
            clean(async () => {
                const header = ana.locator('[data-chat-pane] header');
                const nested = await header.locator('button button').count();
                const badge = header.locator('[data-sla-badge]');
                const spoken = (
                    await badge.locator('.sr-only').first().innerText()
                ).trim();
                const live = await badge
                    .locator('[aria-live="polite"]')
                    .innerText();
                const toggle = header.locator('button', {
                    has: ana.locator('[data-sla-badge]'),
                });
                await toggle.focus();
                await ana.keyboard.press('Enter');
                const hidden = await ana
                    .locator('[data-sla-details]')
                    .waitFor({ state: 'hidden', timeout: 5_000 })
                    .then(() => true)
                    .catch(() => false);
                await ana.keyboard.press('Enter');
                const back = await ana
                    .locator('[data-sla-details]')
                    .waitFor({ state: 'visible', timeout: 5_000 })
                    .then(() => true)
                    .catch(() => false);
                await shot(ana, 'sla-detalle');

                return [
                    nested === 0 &&
                        spoken.startsWith('SLA: En tiempo. Vence en ') &&
                        /Fecha límite/.test(spoken) &&
                        live.trim() === 'SLA: En tiempo' &&
                        hidden &&
                        back,
                    `buttons nested in a button=${nested} spoken="${spoken.slice(0, 110)}" live region="${live.trim()}" Enter on the header toggles the details (with the full SLA detail): closed=${hidden} reopened=${back}`,
                ];
            }),
        );

        await step(
            'badge-warning-live',
            'warning instant 10 s ahead (cycle moved by the seed)',
            clean(async () => {
                const moved = seed(
                    'sla-age',
                    String(conversation),
                    'warn',
                    '10',
                );
                await openInbox(ana);
                await listRow(ana, CUSTOMER).waitFor();
                await ana.evaluate(() => (window.__sameDocument = true));
                const before = await rowBadge(ana, CUSTOMER).getAttribute(
                    'data-sla-state',
                );
                const turned = await waitFor(
                    async () =>
                        (await rowBadge(ana, CUSTOMER).getAttribute(
                            'data-sla-state',
                        )) === 'warning',
                    20_000,
                );
                const sameDocument = await ana.evaluate(
                    () => window.__sameDocument === true,
                );
                const text = (
                    await rowBadge(ana, CUSTOMER).innerText()
                ).replace(/\s+/g, ' ');
                await shot(ana, 'sla-por-vencer');

                return [
                    before === 'on_time' &&
                        turned &&
                        sameDocument &&
                        /Por vencer/.test(text),
                    `before=${before} turned by itself=${turned} same document=${sameDocument} badge="${text}" moved=${moved.shifted_seconds}s`,
                ];
            }),
        );

        await step(
            'alert-warning',
            'php artisan sla:evaluate',
            clean(async () => {
                const evaluated = seed('sla-evaluate');
                const stored = state(conversation);
                const agent = await slaNotifications(ana);
                const supervisor = await slaNotifications(sofia);
                await bellButton(ana).click();
                await ana.waitForTimeout(600);
                await shot(ana, 'sla-campana-aviso', { fullPage: false });
                await ana.keyboard.press('Escape');

                return [
                    evaluated.status === 0 &&
                        stored.cycle?.warned_at !== null &&
                        stored.cycle?.breached_at === null &&
                        agent.length === 1 &&
                        /SLA automático/.test(agent[0]) &&
                        /Cliente SLA/.test(agent[0]) &&
                        !/Requiere acción/.test(agent[0]) &&
                        supervisor.length === 0,
                    `evaluate=${evaluated.status} warned_at=${stored.cycle?.warned_at} agent bell=${JSON.stringify(agent)} supervisor bell=${JSON.stringify(supervisor)}`,
                ];
            }),
        );

        await step(
            'badge-breached',
            'deadline 45 s ago, php artisan sla:evaluate',
            clean(async () => {
                seed('sla-age', String(conversation), 'due', '-45');
                await openConversation(ana, conversation);
                const beforeEvaluator = await rowBadge(
                    ana,
                    CUSTOMER,
                ).getAttribute('data-sla-state');
                const evaluated = seed('sla-evaluate');
                const stored = state(conversation);
                await openConversation(ana, conversation);
                const row = (await rowBadge(ana, CUSTOMER).innerText()).replace(
                    /\s+/g,
                    ' ',
                );
                const headerLabel = await ana
                    .locator('[data-chat-pane] header [data-sla-badge]')
                    .getAttribute('title');

                if (
                    !(await ana
                        .locator('[data-sla-details]')
                        .isVisible()
                        .catch(() => false))
                ) {
                    await ana.locator('[data-tour="chat.details"]').click();
                }

                const details = (
                    await ana.locator('[data-sla-details]').innerText()
                ).replace(/\s+/g, ' ');
                const timeline = (
                    await ana
                        .locator('[data-chat-pane] p', {
                            hasText: 'Objetivo de respuesta incumplido',
                        })
                        .first()
                        .innerText()
                        .catch(() => '')
                ).replace(/\s+/g, ' ');
                await shot(ana, 'sla-vencida');

                return [
                    beforeEvaluator === 'breached' &&
                        evaluated.status === 0 &&
                        stored.cycle?.breached_at !== null &&
                        stored.events.some(
                            (event) => event.type === 'sla_breached',
                        ) &&
                        stored.audit.some(
                            (entry) =>
                                entry.action === 'conversation.sla_breached',
                        ) &&
                        /Vencida/.test(row) &&
                        (headerLabel ?? '').startsWith(
                            'SLA: Vencida. Venció hace ',
                        ) &&
                        /Tiempo vencido/.test(details) &&
                        /responsable: E2E Ana/.test(timeline) &&
                        !/→/.test(timeline),
                    `badge before the evaluator=${beforeEvaluator} breached_at=${stored.cycle?.breached_at} events=${JSON.stringify(stored.events.map((event) => event.type))} row="${row}" header="${headerLabel}" timeline event="${timeline}"`,
                ];
            }),
        );

        await step(
            'alert-breach',
            'bells of the agent and the supervisor',
            clean(async () => {
                const agent = await slaNotifications(ana);
                const supervisor = await slaNotifications(sofia);
                const urgent = (items) =>
                    items.filter((item) => /Requiere acción/.test(item));
                await bellButton(sofia).click();
                await sofia.waitForTimeout(600);
                await shot(sofia, 'sla-campana-vencida', { fullPage: false });
                await sofia.keyboard.press('Escape');

                return [
                    urgent(agent).length === 1 &&
                        urgent(supervisor).length === 1 &&
                        agent.every((item) => /SLA automático/.test(item)),
                    `agent=${JSON.stringify(agent)} supervisor=${JSON.stringify(supervisor)}`,
                ];
            }),
        );

        const monitorRow = (page) =>
            page.locator('[data-sla-row]', { hasText: CUSTOMER });

        await step(
            'monitor-row',
            'E2E Sofía › Monitoreo de SLA',
            clean(async () => {
                await openMonitor(sofia);
                await monitorRow(sofia).waitFor({ timeout: 15_000 });
                const text = (await monitorRow(sofia).innerText()).replace(
                    /\s+/g,
                    ' ',
                );
                const badge = await monitorRow(sofia)
                    .locator('[data-sla-badge]')
                    .getAttribute('data-sla-state');
                const counters = {
                    on_time: await statValue(sofia, 'En tiempo'),
                    warning: await statValue(sofia, 'Por vencer'),
                    breached: await statValue(sofia, 'Vencidas'),
                    unmeasured: await statValue(
                        sofia,
                        'Sin objetivo configurado',
                    ),
                };
                const headers = await sofia
                    .locator('[data-sla-open-table] thead th')
                    .allInnerTexts();
                const garbage = await domGarbage(sofia);
                await shot(sofia, 'sla-monitoreo');

                return [
                    badge === 'breached' &&
                        /Vencida hace/.test(text) &&
                        /E2E Ana/.test(text) &&
                        /Primera respuesta/.test(text) &&
                        counters.breached === 1 &&
                        counters.on_time === 0 &&
                        counters.warning === 0 &&
                        headers.length >= 6 &&
                        garbage.length === 0,
                    `row="${text.slice(0, 220)}" badge=${badge} counters=${JSON.stringify(counters)} columns=${headers.length} garbage=${JSON.stringify(garbage)}`,
                ];
            }),
        );

        const chooseFilter = async (key, option) => {
            await sofia.locator(`#sla-filter-${key}`).click();
            await sofia
                .getByRole('option', { name: option, exact: true })
                .click();
            await sofia.waitForLoadState('networkidle');
            await sofia.waitForTimeout(300);
        };

        await step(
            'monitor-filters',
            'Estado del SLA, Agente responsable',
            clean(async () => {
                await chooseFilter('state', 'En tiempo');
                const filteredEmpty = await sofia
                    .getByText('Ninguna solicitud coincide con los filtros')
                    .waitFor({ timeout: 10_000 })
                    .then(() => true)
                    .catch(() => false);
                const stateInUrl = new URL(sofia.url()).searchParams.get(
                    'sla_state',
                );
                const rowsOnTime = await sofia
                    .locator('[data-sla-row]')
                    .count();
                await shot(sofia, 'sla-monitoreo-filtro-vacio');
                await sofia
                    .getByRole('button', { name: 'Quitar filtros' })
                    .first()
                    .click();
                await monitorRow(sofia).waitFor({ timeout: 10_000 });
                const cleared = !new URL(sofia.url()).searchParams.has(
                    'sla_state',
                );
                await chooseFilter('state', 'Vencida');
                const rowsBreached = await sofia
                    .locator('[data-sla-row]')
                    .count();
                await chooseFilter('agent', 'E2E Ana');
                const agentInUrl = new URL(sofia.url()).searchParams.get(
                    'sla_agent',
                );
                const rowsAna = await sofia.locator('[data-sla-row]').count();
                await chooseFilter('agent', 'E2E Beto');
                const rowsBeto = await sofia.locator('[data-sla-row]').count();
                await sofia.reload();
                await sofia.waitForLoadState('networkidle');
                const keptAfterReload =
                    new URL(sofia.url()).searchParams.get('sla_state') ===
                    'breached';
                await sofia
                    .getByRole('button', { name: 'Quitar filtros' })
                    .first()
                    .click();
                await monitorRow(sofia).waitFor({ timeout: 10_000 });

                return [
                    filteredEmpty &&
                        stateInUrl === 'on_time' &&
                        rowsOnTime === 0 &&
                        cleared &&
                        rowsBreached === 1 &&
                        agentInUrl === String(data.ana) &&
                        rowsAna === 1 &&
                        rowsBeto === 0 &&
                        keptAfterReload,
                    `«En tiempo»: empty=${filteredEmpty} url=${stateInUrl} rows=${rowsOnTime}; cleared=${cleared}; «Vencida» rows=${rowsBreached}; agent Ana url=${agentInUrl} rows=${rowsAna}; agent Beto rows=${rowsBeto}; filters kept after reload=${keptAfterReload}`,
                ];
            }),
        );

        const breachRow = (page) =>
            page.locator('[data-sla-breach-row]', { hasText: CUSTOMER });

        await step(
            'monitor-history',
            'Historial de incumplimientos',
            clean(async () => {
                await breachRow(sofia).waitFor({ timeout: 10_000 });
                const text = (await breachRow(sofia).innerText()).replace(
                    /\s+/g,
                    ' ',
                );
                const from = await sofia
                    .locator('#sla-history-from')
                    .inputValue();
                const to = await sofia.locator('#sla-history-to').inputValue();

                return [
                    /Primera respuesta en 10 min/.test(text) &&
                        /E2E Ana/.test(text) &&
                        /Sigue sin respuesta/.test(text) &&
                        /^\d{4}-\d\d-\d\d$/.test(from) &&
                        /^\d{4}-\d\d-\d\d$/.test(to),
                    `row="${text.slice(0, 240)}" range=${from}..${to}`,
                ];
            }),
        );

        await step(
            'glossary',
            'keyboard: focus the summary, Enter',
            clean(async () => {
                const glossary = sofia.locator('[data-attention-glossary]');
                await glossary.locator('summary').focus();
                await sofia.keyboard.press('Enter');
                const open = await glossary.evaluate((element) => element.open);
                const terms = await glossary.locator('dt').allInnerTexts();
                await shot(sofia, 'sla-glosario');
                await sofia.keyboard.press('Enter');

                return [
                    open &&
                        terms.length === 3 &&
                        /Recordatorios/.test(terms[0]) &&
                        /Alertas automáticas de SLA/.test(terms[1]) &&
                        /Control de conversaciones pendientes/.test(terms[2]),
                    `open=${open} terms=${JSON.stringify(terms.map((term) => term.trim()))}`,
                ];
            }),
        );

        await step(
            'status-not-color-alone',
            'SLA badges of the monitor and the inbox',
            clean(async () => {
                const monitor = await notColorAlone(
                    sofia,
                    '[data-sla-row] [data-sla-badge]',
                );
                await openInbox(ana);
                await listRow(ana, CUSTOMER).waitFor();
                const inbox = await notColorAlone(
                    ana,
                    '[data-inbox-item] [data-sla-badge]',
                );
                const name = await rowBadge(ana, CUSTOMER)
                    .locator('.sr-only')
                    .innerText();

                return [
                    monitor[0] && inbox[0] && name.startsWith('SLA: Vencida.'),
                    `${monitor[1]} | ${inbox[1]} | accessible text="${name.slice(0, 90)}"`,
                ];
            }),
        );

        await step(
            'reply-resolves',
            REPLY,
            clean(async () => {
                await openConversation(ana, conversation);
                await ana.locator('#chat-composer').click();
                await ana.keyboard.type(REPLY);
                const posted = ana.waitForResponse(
                    (response) =>
                        response.request().method() === 'POST' &&
                        /\/conversations\/\d+\/messages$/.test(response.url()),
                );
                await ana
                    .getByRole('button', { name: 'Enviar', exact: true })
                    .click();
                const status = (await posted).status();
                await ana.waitForLoadState('networkidle');
                const badgeGone = await waitFor(
                    async () =>
                        (await ana
                            .locator('[data-sla-badge]:visible')
                            .count()) === 0,
                    10_000,
                );
                const stored = state(conversation);
                await shot(ana, 'sla-respondida');
                await openMonitor(sofia);
                await breachRow(sofia).waitFor({ timeout: 15_000 });
                const liveRows = await monitorRow(sofia).count();
                const breached = await statValue(sofia, 'Vencidas');
                const history = (await breachRow(sofia).innerText()).replace(
                    /\s+/g,
                    ' ',
                );
                const garbage = await domGarbage(sofia);
                await shot(sofia, 'sla-monitoreo-respondida');
                const bell = await slaNotifications(ana);

                return [
                    status < 400 &&
                        badgeGone &&
                        stored.cycle?.active === null &&
                        stored.cycle?.outcome === 'answered' &&
                        liveRows === 0 &&
                        breached === 0 &&
                        /Respondió E2E Ana el /.test(history) &&
                        garbage.length === 0 &&
                        bell.length > 0 &&
                        bell.every((item) => !/Requiere acción/.test(item)) &&
                        bell.some((item) => /ya respondida/i.test(item)),
                    `POST=${status} badge gone=${badgeGone} cycle=${JSON.stringify(stored.cycle)} live rows=${liveRows} «Vencidas»=${breached} history="${history.slice(0, 200)}" bell=${JSON.stringify(bell)} garbage=${JSON.stringify(garbage)}`,
                ];
            }),
        );

        /* A second breached request stays unanswered for the layout matrix. */
        const second = inbound(
            '5215511190002',
            'Cliente SLA Segundo con un nombre bastante largo',
            'Buenas tardes, sigo esperando',
        )[0].conversation;
        seed('sla-age', String(second), 'due', '-90');
        seed('sla-evaluate');

        await layoutSweep(browser, {
            id: 'sla',
            storageState: await sofia.context().storageState(),
            states: [
                {
                    name: 'monitoreo',
                    tables: true,
                    prepare: async (page) => {
                        await openMonitor(page);
                        await page
                            .locator('[data-sla-row]')
                            .first()
                            .waitFor({ timeout: 15_000 });
                    },
                },
                {
                    name: 'campana',
                    prepare: async (page) => {
                        await bellButton(page).click();
                        await page
                            .locator('[data-notification-sla]')
                            .first()
                            .waitFor({ timeout: 10_000 });
                        await page.waitForTimeout(250);
                    },
                    cleanup: async (page) => {
                        await page.keyboard.press('Escape');
                    },
                },
                {
                    name: 'bandeja',
                    prepare: async (page) => {
                        await openInbox(page);
                        await page
                            .locator('[data-inbox-item] [data-sla-badge]')
                            .first()
                            .waitFor({ timeout: 10_000 });
                    },
                },
                {
                    name: 'chat',
                    prepare: async (page) => {
                        await openConversation(page, second);
                        await page
                            .locator('[data-chat-pane] header [data-sla-badge]')
                            .waitFor({ timeout: 10_000 });
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
