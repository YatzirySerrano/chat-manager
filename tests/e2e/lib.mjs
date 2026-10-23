/*
 * Helpers shared by the browser scenarios of tests/e2e (*.mjs): Playwright
 * loading, the PASSED/FAILED report, page problem collectors, login, toasts,
 * inbox locators, the simulated inbound messages of increment-b-seed.php and,
 * for the real-time scenarios, the queue worker / Reverb processes and the
 * WebSocket frame log used to measure live updates.
 *
 * Every scenario runs in its own Node process (tests/e2e/run.php), so the
 * module-level state below (results, problems, latencies) belongs to one run.
 */
import { execFile, execFileSync, spawn } from 'node:child_process';
import { createHmac } from 'node:crypto';
import { mkdirSync, openSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';

const require = createRequire(import.meta.url);
const execFileAsync = promisify(execFile);

export const { chromium } = require(
    process.env.PLAYWRIGHT_MODULE ?? 'playwright',
);

export const HERE = dirname(fileURLToPath(import.meta.url));
export const ROOT = join(HERE, '..', '..');
export const BASE = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8020';
export const SHOTS = process.env.E2E_SCREENSHOTS ?? null;
export const REVERB_PORT = Number(process.env.E2E_REVERB_PORT ?? 0);
export const PASSWORD = 'e2e-password';
/** Accounts created by increment-b-seed.php (and finalizacion-seed.php). */
export const USERS = {
    admin: 'e2e-admin@example.test',
    superadmin: 'e2e-admin@example.test',
    administrator: 'e2e-administrador@example.test',
    ana: 'e2e-ana@example.test',
    beto: 'e2e-beto@example.test',
    carla: 'e2e-carla@example.test',
};
export const INCREMENT_B_SEED = join(HERE, 'increment-b-seed.php');
/** The PHP binary of the runner (tests/e2e/run.php), not whatever is on PATH. */
export const PHP_BINARY = process.env.E2E_PHP_BINARY ?? 'php';
/** Viewport widths of the responsive checks (phones, tablet, desktops). */
export const WIDTHS = [320, 360, 390, 430, 768, 1024, 1280, 1440];

/** Polling fallback of the inbox pages: a live update must beat it. */
export const FALLBACK_MS = 20_000;
/** A live update must arrive well before the fallback would refresh. */
export const LIVE_BUDGET_MS = 8_000;

if (SHOTS) {
    mkdirSync(SHOTS, { recursive: true });
}

/* ------------------------------------------------------------------ */
/* Report.                                                             */
/* ------------------------------------------------------------------ */

export const results = [];
export const problems = { console: [], pageerror: [], server: [] };
export const latencies = [];

/** Prints one result line; `input` may be null when the scenario has none. */
export function record(id, input, ok, note = '') {
    results.push({ id, input, ok, note });
    const columns = input === null ? [id, note] : [id, input, note];
    console.log(`${ok ? 'PASSED' : 'FAILED'} | ${columns.join(' | ')}`);
}

/**
 * Runs one check: the scenario returns [ok, note]; an exception fails it.
 * `onFailure` runs after a failed check (screenshots).
 */
export async function step(id, input, scenario, onFailure = null) {
    let ok = false;

    try {
        const [passed, note] = await scenario();
        ok = passed;
        record(id, input, passed, note);
    } catch (error) {
        record(id, input, false, String(error).split('\n')[0]);
    }

    if (!ok && onFailure) {
        await onFailure();
    }
}

/** Final checks on the collected browser errors (console and 5xx). */
export function recordPageProblems(noteLength = 500) {
    record(
        'no-page-errors',
        'console',
        problems.pageerror.length === 0 && problems.console.length === 0,
        JSON.stringify(problems.pageerror.concat(problems.console)).slice(
            0,
            noteLength,
        ),
    );
    record(
        'no-server-errors',
        'network',
        problems.server.length === 0,
        problems.server.join(', '),
    );
}

/** Prints "N/M scenarios passed" and exits with the matching status. */
export function exitWithSummary() {
    const failed = results.filter((result) => !result.ok);
    console.log(
        `\n${results.length - failed.length}/${results.length} scenarios passed`,
    );
    process.exit(failed.length === 0 ? 0 : 1);
}

export function printLatencies() {
    console.log(
        '\nLatencies (ms from action to UI; WS = signal frame on the socket):',
    );

    for (const entry of latencies) {
        console.log(
            `  ${entry.label} [${entry.session}]: UI ${entry.elapsed}, WS ${entry.frameMs ?? '—'} (${entry.event})`,
        );
    }
}

export function printProblems() {
    console.log(
        `\nconsole errors: ${problems.console.length}, page errors: ${problems.pageerror.length}, 5xx: ${problems.server.length}`,
    );
    [...problems.console, ...problems.pageerror, ...problems.server]
        .slice(0, 20)
        .forEach((line) => console.log(`  ${String(line)}`));
}

/* ------------------------------------------------------------------ */
/* Seed scripts (testing database only).                               */
/* ------------------------------------------------------------------ */

/** Runs a PHP seed script and returns its standard output. */
export function php(script, ...args) {
    return execFileSync(PHP_BINARY, [script, ...args], {
        env: process.env,
        cwd: ROOT,
        encoding: 'utf8',
    });
}

/** Same, without blocking the event loop (browser events keep flowing). */
export async function phpAsync(script, ...args) {
    const { stdout } = await execFileAsync(PHP_BINARY, [script, ...args], {
        env: process.env,
        cwd: ROOT,
    });

    return stdout;
}

/**
 * SIMULACIÓN: a customer message in the documented WhatsApp Cloud API
 * webhook format, processed directly (increment-b-seed.php inbound).
 */
export function inbound(waId, name, text) {
    return JSON.parse(php(INCREMENT_B_SEED, 'inbound', waId, name, text));
}

export async function inboundAsync(waId, name, text) {
    return JSON.parse(
        await phpAsync(INCREMENT_B_SEED, 'inbound', waId, name, text),
    );
}

/**
 * SIMULACIÓN: posts a webhook body to the local server signed like Meta does
 * (X-Hub-Signature-256 with the throwaway app secret) and returns the status.
 */
export async function signedWebhook(webhookKey, secret, body) {
    const signature = createHmac('sha256', secret).update(body).digest('hex');
    const response = await fetch(`${BASE}/webhooks/whatsapp/${webhookKey}`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Hub-Signature-256': `sha256=${signature}`,
        },
        body,
    });

    return response.status;
}

/* ------------------------------------------------------------------ */
/* Pages.                                                              */
/* ------------------------------------------------------------------ */

/** Collects console errors (except `ignoreConsole`), page errors and 5xx. */
export function watchProblems(page, ignoreConsole, prefix = '') {
    page.on('console', (message) => {
        if (message.type() === 'error' && !ignoreConsole.test(message.text())) {
            problems.console.push(`${prefix}${message.text()} @ ${page.url()}`);
        }
    });
    page.on('pageerror', (error) =>
        problems.pageerror.push(`${prefix}${String(error)}`),
    );
    page.on('response', (response) => {
        if (response.status() >= 500) {
            problems.server.push(
                `${prefix}${response.status()} ${response.url()}`,
            );
        }
    });
}

/**
 * A page in its own browser context (its own session cookies).
 *
 *   context        extra browser context options (viewport, downloads, …)
 *   ignoreConsole  console errors that are expected and not problems
 *   prefix         label of the session in the collected problems
 *   muteViteHmr    (default true) answer the Vite dev server socket
 *                  (public/hot) locally, so its full-page reloads while other
 *                  people edit sources do not interfere; only the
 *                  application's behaviour is seen
 *   trackToasts    remember every toast shown, also the ones already gone:
 *                  window.__toasts (texts) and window.__toastLog ({t, text}),
 *                  one entry per toast element, so a repeated toast with the
 *                  same text counts twice (see toastsSince)
 *   trackAlerts    spy on the notification sound (Web Audio oscillators
 *                  and media elements), see alertCounts / alertDelta
 */
export async function openPage(browser, options = {}) {
    const context = await browser.newContext({
        viewport: { width: 1366, height: 900 },
        locale: 'es-MX',
        ...options.context,
    });

    if (options.muteViteHmr ?? true) {
        await context.routeWebSocket(
            (url) => url.port === '5173',
            () => {},
        );
    }

    const page = await context.newPage();

    if (options.trackToasts) {
        await page.addInitScript(() => {
            const toastNodes = new WeakSet();
            window.__toasts = [];
            window.__toastLog = [];
            new MutationObserver(() => {
                for (const toast of document.querySelectorAll(
                    '[data-sonner-toast]',
                )) {
                    const text = toast.textContent.trim();

                    // One entry per toast element (never deduplicated by
                    // text); an element is logged once it shows its text.
                    if (text && !toastNodes.has(toast)) {
                        toastNodes.add(toast);
                        window.__toasts.push(text);
                        window.__toastLog.push({ t: Date.now(), text });
                    }
                }
            }).observe(document, { childList: true, subtree: true });
        });
    }

    if (options.trackAlerts) {
        await page.addInitScript(installAlertSpy);
    }

    page.setDefaultTimeout(15_000);
    watchProblems(
        page,
        options.ignoreConsole ?? /status of (403|404|409|422|429)/,
        options.prefix ?? '',
    );

    return page;
}

/** Logs in with the real keyboard; `settle` waits for the network to idle. */
export async function login(page, email, { settle = true } = {}) {
    await page.goto(`${BASE}/login`);
    await page.locator('#email').click();
    await page.keyboard.type(email);
    await page.locator('#password').click();
    await page.keyboard.type(PASSWORD);
    await page.keyboard.press('Enter');
    await page
        .waitForURL((url) => !url.pathname.startsWith('/login'))
        .catch(async () => {
            const typed = await page.locator('#email').inputValue();
            const errors = await page
                .locator('[role="alert"], .text-destructive, p.text-red-600')
                .allInnerTexts();

            throw new Error(
                `login of ${email} stayed on /login (email field="${typed}", errors=${JSON.stringify(errors)})`,
            );
        });

    if (settle) {
        await page.waitForLoadState('networkidle');
    }
}

/** Saves a screenshot when E2E_SCREENSHOTS is set; never fails the step. */
export async function shot(page, name, { fullPage = true } = {}) {
    if (SHOTS && page && !page.isClosed()) {
        await page
            .screenshot({ path: join(SHOTS, `${name}.png`), fullPage })
            .catch(() => {});
    }
}

/**
 * Text of the toast matching the expected outcome. Several toasts can be on
 * screen at once (newest first), so the expected one is looked up by its text;
 * when it never shows, the visible (and, with trackToasts, the already gone)
 * toasts are returned to explain the failure.
 */
export async function toastText(page, expected, timeout = 10_000) {
    const toast = page
        .locator('[data-sonner-toast]', { hasText: expected })
        .first();

    try {
        await toast.waitFor({ timeout });

        return (await toast.innerText()).replace(/\s+/g, ' ');
    } catch {
        const visible = await page
            .locator('[data-sonner-toast]')
            .allInnerTexts();
        const seen = await page
            .evaluate(() => window.__toasts ?? [])
            .catch(() => []);

        return `no toast matching ${expected}; visible: ${JSON.stringify(visible)}; seen: ${JSON.stringify(seen.slice(-4))}`;
    }
}

export async function waitFor(predicate, timeoutMs, intervalMs = 200) {
    const started = Date.now();

    while (Date.now() - started < timeoutMs) {
        if (await predicate()) {
            return true;
        }

        await new Promise((resolve) => setTimeout(resolve, intervalMs));
    }

    return false;
}

/** Toasts (one per element, see trackToasts) shown since `since`. */
export function toastsSince(page, since, pattern = /./) {
    return page.evaluate(
        ({ since, source, flags }) =>
            (window.__toastLog ?? []).filter(
                (entry) =>
                    entry.t >= since &&
                    new RegExp(source, flags).test(entry.text),
            ),
        { since, source: pattern.source, flags: pattern.flags },
    );
}

/**
 * Chromium without the autoplay restriction of the operating system: the
 * page still has to be activated by a gesture (the application waits for
 * navigator.userActivation before creating its audio context).
 */
export function launchBrowser(options = {}) {
    return chromium.launch({
        ...options,
        args: [
            '--autoplay-policy=no-user-gesture-required',
            ...(options.args ?? []),
        ],
    });
}

/**
 * Init script of openPage({ trackAlerts }): counts the notification sound.
 * The application synthesizes it with Web Audio (one oscillator per tone,
 * two tones when urgent), so every createOscillator() is a tone and the
 * tones created within 100 ms form one chime. Media elements are counted
 * too (no audio file is expected).
 */
function installAlertSpy() {
    const alerts = {
        contexts: 0,
        tones: 0,
        chimes: 0,
        running: 0,
        media: 0,
        log: [],
    };
    let lastTone = -Infinity;
    window.__alerts = alerts;

    const Base = window.BaseAudioContext ?? window.AudioContext;

    if (window.AudioContext) {
        const Original = window.AudioContext;
        window.AudioContext = class extends Original {
            constructor(...args) {
                super(...args);
                alerts.contexts++;
            }
        };
    }

    if (Base) {
        const createOscillator = Base.prototype.createOscillator;
        Base.prototype.createOscillator = function (...args) {
            const now = performance.now();

            alerts.tones++;
            alerts.running += this.state === 'running' ? 1 : 0;

            if (now - lastTone > 100) {
                alerts.chimes++;
            }

            lastTone = now;
            alerts.log.push({ t: Date.now(), state: this.state });

            return createOscillator.apply(this, args);
        };
    }

    const play = HTMLMediaElement.prototype.play;
    HTMLMediaElement.prototype.play = function (...args) {
        alerts.media++;

        return play.apply(this, args);
    };
}

/** Counters of the sound spy (trackAlerts) without the log. */
export function alertCounts(page) {
    return page.evaluate(() => {
        const { log, ...counts } = window.__alerts ?? {
            contexts: 0,
            tones: 0,
            chimes: 0,
            running: 0,
            media: 0,
            log: [],
        };

        return { ...counts, lastState: log.at(-1)?.state ?? null };
    });
}

/**
 * Runs the action and returns how the sound counters changed, after
 * waiting `settleMs` (late chimes count).
 */
export async function alertDelta(page, action, { settleMs = 1_500 } = {}) {
    const before = await alertCounts(page);
    const result = await action();
    await page.waitForTimeout(settleMs);
    const after = await alertCounts(page);
    const delta = {};

    for (const key of ['contexts', 'tones', 'chimes', 'running', 'media']) {
        delta[key] = after[key] - before[key];
    }

    return { ...delta, lastState: after.lastState, result };
}

/* ------------------------------------------------------------------ */
/* Inbox (three-panel page /conversations?c=ID).                       */
/* ------------------------------------------------------------------ */

export const AVAILABILITY_SWITCH =
    'Disponible para aceptar y recibir conversaciones';

/**
 * Horizontal overflow in px: the document's (page scrolls sideways) or, when
 * larger, the worst one inside the page (a panel that scrolls sideways or
 * cuts its content; see layoutReport). <= 0 means nothing overflows.
 */
export async function overflow(page) {
    const report = await layoutReport(page);

    return Math.max(
        report.doc,
        ...report.innerScroll.map((entry) => entry.by),
        ...report.offscreen.map((entry) => entry.by),
        ...report.clipped.map((entry) => entry.by),
    );
}

/**
 * Layout of the page as the user sees it, measured in the browser:
 *
 *   doc          document.scrollWidth - innerWidth (page scrolls sideways)
 *   offscreen    visible elements reaching past the left/right edge of the
 *                viewport (also the ones a clipping panel hides)
 *   innerScroll  elements that scroll horizontally inside (overflow-x
 *                auto/scroll with scrollWidth > clientWidth)
 *   clipped      overflow-x hidden/clip elements whose content is wider
 *                than they are and is cut without an ellipsis
 *   smallFont    visible text under `minFont` px inside `fontRoots`
 *   smallTargets visible `targets` (CSS selectors of the main actions)
 *                smaller than `minTarget` px in height or width
 *
 * Toasts and screen-reader-only text are ignored. Every entry has the tag,
 * an accessible name or text and the measure that failed.
 */
export function layoutReport(page, options = {}) {
    return page.evaluate(
        ({ fontRoots, minFont, targets, minTarget }) => {
            const viewport = document.documentElement.clientWidth;
            const ignored = (element) =>
                element.closest(
                    '[data-sonner-toaster], .sr-only, [data-e2e-ignore]',
                ) !== null;
            const shown = (element) => {
                if (ignored(element)) {
                    return false;
                }

                const rect = element.getBoundingClientRect();

                return (
                    rect.width > 0 &&
                    rect.height > 0 &&
                    (element.checkVisibility?.({
                        checkOpacity: true,
                        checkVisibilityCSS: true,
                    }) ??
                        true)
                );
            };
            const describe = (element, extra) => ({
                tag: element.tagName.toLowerCase(),
                name: (
                    element.getAttribute('aria-label') ||
                    element.getAttribute('title') ||
                    element.textContent ||
                    element.id ||
                    String(element.className)
                )
                    .replace(/\s+/g, ' ')
                    .trim()
                    .slice(0, 50),
                ...extra,
            });
            const ownText = (element) =>
                [...element.childNodes].some(
                    (node) =>
                        node.nodeType === Node.TEXT_NODE &&
                        node.textContent.trim() !== '',
                );
            const all = [...document.body.querySelectorAll('*')].filter(shown);
            const report = {
                width: window.innerWidth,
                doc: document.documentElement.scrollWidth - window.innerWidth,
                offscreen: [],
                innerScroll: [],
                clipped: [],
                smallFont: [],
                smallTargets: [],
            };

            for (const element of all) {
                const style = getComputedStyle(element);
                const rect = element.getBoundingClientRect();
                const leaf =
                    ownText(element) ||
                    element.matches(
                        'a, button, input, select, textarea, img, svg, [role]',
                    );

                if (
                    leaf &&
                    style.position !== 'fixed' &&
                    (rect.right > viewport + 1 || rect.left < -1)
                ) {
                    report.offscreen.push(
                        describe(element, {
                            by: Math.round(
                                Math.max(rect.right - viewport, -rect.left),
                            ),
                        }),
                    );
                }

                const extra = element.scrollWidth - element.clientWidth;

                if (extra > 1 && element.clientWidth > 1) {
                    if (['auto', 'scroll'].includes(style.overflowX)) {
                        report.innerScroll.push(
                            describe(element, { by: extra }),
                        );
                    } else if (
                        ['hidden', 'clip'].includes(style.overflowX) &&
                        style.textOverflow !== 'ellipsis'
                    ) {
                        report.clipped.push(describe(element, { by: extra }));
                    }
                }
            }

            const roots = fontRoots
                .flatMap((selector) => [...document.querySelectorAll(selector)])
                .filter(shown);

            for (const element of all) {
                if (
                    roots.some((root) => root.contains(element)) &&
                    ownText(element)
                ) {
                    const size = parseFloat(getComputedStyle(element).fontSize);

                    if (size < minFont) {
                        report.smallFont.push(describe(element, { size }));
                    }
                }
            }

            for (const selector of targets) {
                for (const element of document.querySelectorAll(selector)) {
                    if (!shown(element)) {
                        continue;
                    }

                    const rect = element.getBoundingClientRect();

                    if (rect.height < minTarget || rect.width < minTarget) {
                        report.smallTargets.push(
                            describe(element, {
                                selector,
                                size: `${Math.round(rect.width)}x${Math.round(rect.height)}`,
                            }),
                        );
                    }
                }
            }

            return report;
        },
        {
            fontRoots: options.fontRoots ?? [],
            minFont: options.minFont ?? 12,
            targets: options.targets ?? [],
            minTarget: options.minTarget ?? 40,
        },
    );
}

/** Problems of a layoutReport as short text ('' when there are none). */
export function layoutProblems(report) {
    const list = (label, entries, field) =>
        entries.length === 0
            ? null
            : `${label}=${entries.length} ${JSON.stringify(entries.slice(0, 3).map((entry) => `${entry.tag}:${entry.name}:${entry[field]}`))}`;

    return [
        report.doc > 0 ? `doc=${report.doc}` : null,
        list('offscreen', report.offscreen, 'by'),
        list('innerScroll', report.innerScroll, 'by'),
        list('clipped', report.clipped, 'by'),
        list('smallFont', report.smallFont, 'size'),
        list('smallTargets', report.smallTargets, 'size'),
    ]
        .filter(Boolean)
        .join('; ');
}

export const listRow = (page, name) =>
    page.locator('[data-inbox-item]', { hasText: name }).first();

export const operations = (page) =>
    page.getByRole('group', { name: 'Operaciones de la conversación' });

export const unavailableSelection = (page) =>
    page.getByText('La conversación no está disponible');

export const liveIndicator = (page) =>
    page.locator('[role="status"][aria-label="Tiempo real activo"]');

export const downIndicator = (page) =>
    page.locator('[role="status"][aria-label^="Tiempo real no disponible"]');

/** Main action of the chat header, or the same entry of "Más acciones". */
export async function runOperation(page, label) {
    const direct = operations(page).getByRole('button', {
        name: label,
        exact: true,
    });

    if ((await direct.count()) > 0) {
        await direct.click();

        return;
    }

    await page
        .getByRole('button', { name: 'Más acciones de la conversación' })
        .click();
    await page.getByRole('menuitem', { name: label, exact: true }).click();
}

/** Switches the inbox view ("Pendientes", …) and waits until it is selected. */
export async function openInboxView(page, label) {
    await page
        .getByRole('navigation', { name: 'Bandejas' })
        .getByRole('button', { name: new RegExp(label) })
        .click();
    await page.waitForFunction(
        (text) =>
            document
                .querySelector(
                    'nav[aria-label="Bandejas"] button[aria-pressed="true"]',
                )
                ?.textContent?.includes(text) ?? false,
        label,
    );
}

export async function openConversation(page, id, query = '') {
    await page.goto(`${BASE}/conversations?${query}${query ? '&' : ''}c=${id}`);
    await page.waitForLoadState('networkidle');
}

/**
 * Demo agents start as "No disponible": they switch to "Disponible" through
 * the availability switch of the list before taking conversations.
 */
export async function becomeAvailable(page) {
    const toggle = page.getByRole('switch', { name: AVAILABILITY_SWITCH });

    if ((await toggle.getAttribute('aria-checked')) !== 'true') {
        await toggle.click();
        await toastText(page, /Ahora estás disponible/);
        await page.waitForLoadState('networkidle');
    }

    return (await toggle.getAttribute('aria-checked')) === 'true';
}

/* ------------------------------------------------------------------ */
/* Real time: WebSocket frames and live measurements.                  */
/* ------------------------------------------------------------------ */

/**
 * Logs every frame the page receives on its WebSockets (page.frames$) and
 * every /broadcasting/auth answer (page.auth$).
 */
export function trackWebSocket(page) {
    page.frames$ = [];
    page.auth$ = [];
    page.on('websocket', (socket) => {
        page.frames$.push({ t: Date.now(), event: '__open' });
        socket.on('framereceived', ({ payload }) => {
            try {
                const frame = JSON.parse(String(payload));
                page.frames$.push({
                    t: Date.now(),
                    event: frame.event,
                    channel: frame.channel ?? null,
                });
            } catch {
                // Not JSON: ignore.
            }
        });
        socket.on('close', () =>
            page.frames$.push({ t: Date.now(), event: '__close' }),
        );
    });
    page.on('response', (response) => {
        if (response.url().includes('/broadcasting/auth')) {
            let channel = '';

            try {
                channel = JSON.parse(
                    response.request().postData() ?? '{}',
                ).channel_name;
            } catch {
                channel = '?';
            }

            page.auth$.push({
                t: Date.now(),
                status: response.status(),
                channel,
            });
        }
    });
}

/**
 * A named session of a multi-session real-time scenario: wide viewport, its
 * WebSocket frames logged (trackWebSocket) and the console errors expected
 * while Reverb restarts or a request is refused ignored.
 */
export async function openRealtimeSession(browser, name, options = {}) {
    const page = await openPage(browser, {
        context: { viewport: { width: 1440, height: 900 } },
        ignoreConsole:
            /status of (403|404|409|422|429)|WebSocket connection to|ERR_CONNECTION_REFUSED/,
        prefix: `[${name}] `,
        ...options,
    });
    page.session = name;
    trackWebSocket(page);

    return page;
}

/** First frame of the event received by the page since the given time. */
export function frameSince(page, event, since, channel = null) {
    return page.frames$.find(
        (frame) =>
            frame.t >= since &&
            frame.event === event &&
            (channel === null || (frame.channel ?? '').includes(channel)),
    );
}

/**
 * Waits for the locator in another session and measures the delay from the
 * action. The update counts as live only when it arrived within the budget
 * (well before the fallback) and the WebSocket delivered the signal first.
 */
export async function measure(
    label,
    page,
    locator,
    since,
    event,
    options = {},
) {
    const state = options.state ?? 'visible';
    const timeout = options.timeout ?? LIVE_BUDGET_MS + 4_000;
    await locator.first().waitFor({ state, timeout });
    const elapsed = Date.now() - since;
    const frame = frameSince(page, event, since, options.channel ?? null);
    const frameMs = frame ? frame.t - since : null;
    latencies.push({ label, session: page.session, elapsed, frameMs, event });
    const live =
        elapsed <= (options.budget ?? LIVE_BUDGET_MS) && frame !== undefined;
    const received = frame
        ? ''
        : ` (frames since: ${[...new Set(page.frames$.filter((f) => f.t >= since).map((f) => f.event))].join(',') || 'none'})`;

    return {
        live,
        elapsed,
        frameMs,
        note: `${page.session}: UI ${elapsed} ms, WS ${event} ${frameMs === null ? 'not seen' : `${frameMs} ms`}${received}`,
    };
}

/* ------------------------------------------------------------------ */
/* Processes started by a scenario (always stopped at the end).        */
/* ------------------------------------------------------------------ */

const children = new Set();
let stopOnExit = false;

export function stopChildren() {
    for (const child of children) {
        try {
            child.kill('SIGTERM');
        } catch {
            // Already gone.
        }
    }
}

/** Starts `php <args>` from the project root; logs go to E2E_SCREENSHOTS. */
export function startProcess(args, logName = null) {
    if (!stopOnExit) {
        stopOnExit = true;
        process.on('exit', stopChildren);
        process.on('SIGINT', () => {
            stopChildren();
            process.exit(130);
        });
    }

    const log =
        SHOTS && logName
            ? openSync(join(SHOTS, `${String(logName)}.log`), 'a')
            : 'ignore';
    const child = spawn(PHP_BINARY, args, {
        cwd: ROOT,
        env: process.env,
        stdio: ['ignore', log, log],
    });
    children.add(child);
    child.on('exit', () => children.delete(child));

    return child;
}

/** A real queue worker on every application queue. */
export function startWorker() {
    return startProcess(
        [
            'artisan',
            'queue:work',
            '--queue=webhooks,messages,realtime,notifications,media,default,exports',
            '--sleep=1',
            '--tries=3',
            '--timeout=60',
            '--max-time=900',
        ],
        'worker',
    );
}

export function reverbListenerPids() {
    try {
        return execFileSync(
            'lsof',
            ['-nP', '-t', `-iTCP:${REVERB_PORT}`, '-sTCP:LISTEN'],
            { encoding: 'utf8' },
        )
            .split('\n')
            .map((line) => Number(line.trim()))
            .filter((pid) => pid > 0);
    } catch {
        return [];
    }
}

/** Stops the Reverb server (the one the runner started, or ours). */
export async function stopReverb() {
    const pids = reverbListenerPids();

    for (const pid of pids) {
        process.kill(pid, 'SIGTERM');
    }

    const stopped = await waitFor(
        () => reverbListenerPids().length === 0,
        10_000,
    );

    if (!stopped) {
        for (const pid of reverbListenerPids()) {
            process.kill(pid, 'SIGKILL');
        }
    }

    return pids.length > 0;
}

export async function startReverb() {
    startProcess(
        [
            'artisan',
            'reverb:start',
            '--host=127.0.0.1',
            `--port=${REVERB_PORT}`,
        ],
        'reverb',
    );

    return waitFor(() => reverbListenerPids().length > 0, 15_000);
}

/** Exits with status 2 unless the runner started Reverb and the simulated transport. */
export function requireRealtimeEnvironment() {
    if (!REVERB_PORT || process.env.BROADCAST_CONNECTION !== 'reverb') {
        console.error(
            'Run with E2E_REVERB=1 through tests/e2e/run.php (Reverb is required).',
        );
        process.exit(2);
    }

    if (process.env.WHATSAPP_TRANSPORT !== 'simulated') {
        console.error(
            'Run with WHATSAPP_TRANSPORT=simulated (outbound messages are simulated).',
        );
        process.exit(2);
    }
}
